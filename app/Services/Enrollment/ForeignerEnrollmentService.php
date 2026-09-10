<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Contracts\EnrollmentEventPublisherInterface;
use App\DataTransferObjects\PhysiqueEnrollmentSubmission;
use App\Enums\ActivityLogAction;
use App\Enums\EnrollmentStatus;
use App\Jobs\ForeignerFinalizedJob;
use App\Jobs\RegulaAnalysisJob;
use App\Jobs\UploadEnrollmentFilesJob;
use App\Models\EnrollmentRequest;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use App\Support\TrackingCodeAllocator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class ForeignerEnrollmentService
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly KycVerificationService $kycVerification,
        private readonly EnrollmentEventPublisherInterface $events,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function submitEnrollment(PhysiqueEnrollmentSubmission $submission): ServiceResult
    {
        $email = $submission->email;
        $phone = $this->otpService->normalizePhone($submission->phonenumber);

        if (! $this->otpService->bothChannelsVerified($email, $phone)) {
            return ServiceResult::fail("Veuillez d'abord vérifier l'OTP email et téléphone.", null, 400);
        }

        if (! $this->kycVerification->isVerified($email, $phone)) {
            return ServiceResult::fail('Veuillez d\'abord valider le KYC.', null, 400);
        }

        $kycSession = $this->kycVerification->consumeVerification($email, $phone) ?? [];
        $uploadedFiles = $this->storeLocalFiles($submission);
        $capturedAt = $this->kycVerification->selfieCapturedAt($submission->captureLe, $kycSession);

        DB::beginTransaction();
        try {
            $enrollmentRequest = EnrollmentRequest::create([
                'tracking_code' => TrackingCodeAllocator::next(),
                'email' => $email,
                'phonenumber' => $phone,
                'kyc_data' => [
                    'name' => $submission->name,
                    'first_name' => $submission->firstName,
                    'sexe' => $submission->sexe,
                    'date_of_birth' => $submission->dateOfBirth,
                    'place_of_birth' => $submission->placeOfBirth,
                    'nationality' => $submission->nationality,
                    'country_of_residence' => $submission->countryOfResidence,
                    'address' => $submission->address,
                    'document_type' => $submission->documentType,
                    'document_number' => $submission->documentNumber,
                ],
                'documents' => $uploadedFiles,
                'liveness' => $kycSession['liveness'] ?? $submission->liveness,
                'similarity' => $kycSession['similarity'] ?? $submission->similarity,
                'risk_score' => $kycSession['risk_score'] ?? null,
                'analysis_details' => is_array($kycSession['analysis_details'] ?? null)
                    ? $kycSession['analysis_details']
                    : null,
                'selfie_captured_at' => $capturedAt,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'status' => EnrollmentStatus::EnAttenteAgent->value,
                'type' => 'PERSONNE_PHYSIQUE',
                'sla_deadline_at' => now()->addHours((int) config('enrollment.sla.max_hours', 72)),
            ]);

            $this->otpService->clearVerificationFlags($email, $phone);

            DB::commit();

            $this->dispatchPostSubmissionJobs($enrollmentRequest, $uploadedFiles);

            $this->events->publish('created', [
                'demande_id' => $enrollmentRequest->id,
                'statut' => $enrollmentRequest->status->value,
                'email' => $email,
            ]);

            $this->activityLog->record(
                ActivityLogAction::DemandeIdentite,
                sprintf(
                    '%s %s a initié une demande d\'identité.',
                    $submission->firstName,
                    $submission->name
                ),
                null,
                $enrollmentRequest->id,
            );

            return ServiceResult::ok('Demande acceptée.', [
                'demande_id' => $enrollmentRequest->id,
                'numero_suivi' => $enrollmentRequest->tracking_code,
                'statut' => $enrollmentRequest->status->value,
                'email_verifie' => true,
                'telephone_verifie' => true,
            ], 202);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Enrollment submission failed: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'email' => $email,
            ]);
            $this->cleanupUploadedFiles($uploadedFiles);

            return ServiceResult::fail('Erreur lors de la soumission de la demande.', null, 500);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function storeLocalFiles(PhysiqueEnrollmentSubmission $submission): array
    {
        $uploadedFiles = [];

        foreach ([
            'selfie' => $submission->selfie,
            'recto' => $submission->recto,
            'verso' => $submission->verso,
            'profile' => $submission->profile,
        ] as $field => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $chemin = $file->store('tmp/enrollments', 'local');
            $uploadedFiles[$field] = $chemin === false ? null : $chemin;
        }

        return $uploadedFiles;
    }

    /**
     * @param  array<string, string|null>  $uploadedFiles
     */
    private function dispatchPostSubmissionJobs(EnrollmentRequest $enrollmentRequest, array $uploadedFiles): void
    {
        $kyc = $enrollmentRequest->kyc_data;
        $confirmation = new ForeignerFinalizedJob(
            $enrollmentRequest->email,
            'PERSONNE_PHYSIQUE',
            is_array($kyc) ? (string) ($kyc['name'] ?? '') : '',
            $enrollmentRequest->tracking_code,
        );

        if ($uploadedFiles === []) {
            Bus::dispatch($confirmation);

            return;
        }

        Bus::chain([
            new UploadEnrollmentFilesJob($enrollmentRequest->id, $uploadedFiles),
            new RegulaAnalysisJob($enrollmentRequest->id),
            $confirmation,
        ])->dispatch();
    }

    /**
     * @param  array<string, string|null>  $uploadedFiles
     */
    private function cleanupUploadedFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $path) {
            if ($path && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
