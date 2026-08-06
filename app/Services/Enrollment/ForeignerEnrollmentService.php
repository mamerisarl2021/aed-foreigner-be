<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Contracts\EnrollmentEventPublisherInterface;
use App\DataTransferObjects\EmailNotificationData;
use App\Enums\ActivityLogAction;
use App\Enums\EnrollmentStatus;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\ForeignerFinalizedJob;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\RegulaAnalysisJob;
use App\Jobs\UploadEnrollmentFilesJob;
use App\Models\EnrollmentRequest;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use App\Support\NotificationRecipient;
use App\Support\TrackingCodeAllocator;
use Illuminate\Http\Request;
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

    public function submitEnrollment(Request $request): ServiceResult
    {
        $email = strtolower(trim($request->input('email')));
        $phone = $this->otpService->normalizePhone((string) $request->input('phonenumber'));

        if (! $this->otpService->bothChannelsVerified($email, $phone)) {
            return ServiceResult::fail("Veuillez d'abord vérifier l'OTP email et téléphone.", null, 400);
        }

        if (! $this->kycVerification->isVerified($email, $phone)) {
            return ServiceResult::fail('Veuillez d\'abord valider le KYC.', null, 400);
        }

        $kycSession = $this->kycVerification->consumeVerification($email, $phone);
        $uploadedFiles = $this->uploadEnrollmentFiles($request);

        DB::beginTransaction();
        try {
            $enrollmentRequest = EnrollmentRequest::create([
                'tracking_code' => TrackingCodeAllocator::next(),
                'email' => $email,
                'phonenumber' => $phone,
                'kyc_data' => [
                    'name' => $request->input('name'),
                    'first_name' => $request->input('first_name'),
                    'sexe' => $request->input('sexe'),
                    'date_of_birth' => $request->input('date_of_birth'),
                    'place_of_birth' => $request->input('place_of_birth'),
                    'nationality' => $request->input('nationality'),
                    'country_of_residence' => $request->input('country_of_residence'),
                    'address' => $request->input('address'),
                    'document_type' => $request->input('document_type'),
                    'document_number' => $request->input('document_number'),
                ],
                'documents' => $uploadedFiles,
                'liveness' => $kycSession['liveness'] ?? $request->input('liveness'),
                'similarity' => $kycSession['similarity'] ?? $request->input('similarity'),
                'risk_score' => $kycSession['risk_score'] ?? null,
                'analysis_details' => $kycSession['analysis_details'] ?? null,
                'status' => EnrollmentStatus::EnAttenteAgent->value,
                'type' => 'PERSONNE_PHYSIQUE',
                'sla_deadline_at' => now()->addHours((int) config('enrollment.sla.max_hours', 72)),
            ]);

            $this->otpService->clearVerificationFlags($email, $phone);

            DB::commit();

            $this->dispatchPostSubmissionJobs($enrollmentRequest, $uploadedFiles);

            $this->events->publish('created', [
                'demande_id' => $enrollmentRequest->id,
                'statut' => $enrollmentRequest->status,
                'email' => $email,
            ]);

            $this->activityLog->record(
                ActivityLogAction::DemandeIdentite,
                sprintf(
                    '%s %s a initié une demande d\'identité.',
                    $request->input('first_name'),
                    $request->input('name')
                ),
                null,
                $enrollmentRequest->id,
            );

            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: 'Confirmation de soumission — enrôlement AED',
                template: NotificationTemplate::ForeignerFinalized,
                recipients: [NotificationRecipient::email($email, [
                    'name' => $request->input('name'),
                    'numero_suivi' => $enrollmentRequest->tracking_code,
                ])],
                variables: [
                    'name' => $request->input('name'),
                    'demande_id' => $enrollmentRequest->id,
                    'numero_suivi' => $enrollmentRequest->tracking_code,
                ],
                type: 'ENROLEMENT_SUBMITTED',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));

            return ServiceResult::ok('Demande acceptée.', [
                'demande_id' => $enrollmentRequest->id,
                'numero_suivi' => $enrollmentRequest->tracking_code,
                'statut' => $enrollmentRequest->status,
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
    private function uploadEnrollmentFiles(Request $request): array
    {
        $uploadedFiles = [];

        foreach (['selfie', 'recto', 'verso', 'profile'] as $field) {
            if ($request->hasFile($field)) {
                $uploadedFiles[$field] = $request->file($field)?->store('tmp/enrollments', 'local');
            }
        }

        return $uploadedFiles;
    }

    /**
     * @param  array<string, string|null>  $uploadedFiles
     */
    private function dispatchPostSubmissionJobs(EnrollmentRequest $enrollmentRequest, array $uploadedFiles): void
    {
        if ($uploadedFiles === []) {
            ForeignerFinalizedJob::dispatch($enrollmentRequest->email, 'PERSONNE_PHYSIQUE');

            return;
        }

        Bus::chain([
            new UploadEnrollmentFilesJob($enrollmentRequest->id, $uploadedFiles),
            new RegulaAnalysisJob($enrollmentRequest->id),
            new ForeignerFinalizedJob($enrollmentRequest->email, 'PERSONNE_PHYSIQUE'),
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
