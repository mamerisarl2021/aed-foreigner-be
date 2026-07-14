<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Jobs\AdvancedIdRequestJob;
use App\Jobs\ForeignerFinalizedJob;
use App\Jobs\ForeignerOtpJob;
use App\Jobs\RegulaAnalysisJob;
use App\Jobs\UploadEnrollmentFilesJob;
use App\Models\EnrollmentRequest;
use App\Services\ServiceResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ForeignerEnrollmentService
{
    public function sendOtp(string $email): ServiceResult
    {
        $email = strtolower(trim($email));
        $otp = (string) random_int(100000, 999999);
        $ttl = 5;

        Cache::put('foreigner_otp_'.$email, $otp, now()->addMinutes($ttl));
        ForeignerOtpJob::dispatch($email, $otp, $ttl);

        return ServiceResult::ok('OTP envoyé à votre adresse email.', ['email' => $email]);
    }

    public function verifyOtp(string $email, string $otp): ServiceResult
    {
        $email = strtolower(trim($email));
        $expected = Cache::get('foreigner_otp_'.$email);

        if (! $expected || $expected !== $otp) {
            return ServiceResult::fail('OTP invalide ou expiré.', null, 400);
        }

        Cache::put('foreigner_otp_valid_'.$email, true, now()->addMinutes(10));

        return ServiceResult::ok('OTP vérifié.', ['email' => $email]);
    }

    /**
     * Single-step enrollment submission for physical foreigners.
     * Stores the request data in enrollment_requests for agent review.
     * Does NOT create User or Identity records — that happens on approval.
     */
    public function submitEnrollment(Request $request): ServiceResult
    {
        $email = strtolower(trim($request->input('email')));

        if (! Cache::get('foreigner_otp_valid_'.$email)) {
            return ServiceResult::fail("Veuillez d'abord vérifier votre OTP.", null, 400);
        }

        $uploadedFiles = $this->uploadEnrollmentFiles($request);

        DB::beginTransaction();
        try {
            $enrollmentRequest = EnrollmentRequest::create([
                'email' => $email,
                'phonenumber' => $request->input('phonenumber'),
                'kyc_data' => [
                    'name' => $request->input('name'),
                    'first_name' => $request->input('first_name'),
                    'sex' => $request->input('sex'),
                    'date_of_birth' => $request->input('date_of_birth'),
                    'place_of_birth' => $request->input('place_of_birth'),
                    'nationality' => $request->input('nationality'),
                    'country_of_residence' => $request->input('country_of_residence'),
                    'address' => $request->input('address'),
                    'document_type' => $request->input('document_type'),
                    'document_number' => $request->input('document_number'),
                ],
                'documents' => $uploadedFiles,
                'liveness' => $request->input('liveness'),
                'similarity' => $request->input('similarity'),
                'status' => 'PENDING',
                'type' => 'PERSONNE_PHYSIQUE',
            ]);

            Cache::forget('foreigner_otp_'.$email);
            Cache::forget('foreigner_otp_valid_'.$email);

            DB::commit();

            $this->dispatchPostSubmissionJobs($enrollmentRequest, $uploadedFiles);

            return ServiceResult::ok('Demande enregistrée avec succès.', [
                'enrollment_request_id' => $enrollmentRequest->id,
            ]);
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

        if ($request->hasFile('selfie')) {
            $uploadedFiles['selfie'] = $request->file('selfie')?->store('tmp/enrollments', 'local');
        }
        if ($request->hasFile('recto')) {
            $uploadedFiles['recto'] = $request->file('recto')?->store('tmp/enrollments', 'local');
        }
        if ($request->hasFile('verso')) {
            $uploadedFiles['verso'] = $request->file('verso')?->store('tmp/enrollments', 'local');
        }
        if ($request->hasFile('profile')) {
            $uploadedFiles['profile'] = $request->file('profile')?->store('tmp/enrollments', 'local');
        }

        return $uploadedFiles;
    }

    private function dispatchPostSubmissionJobs(EnrollmentRequest $enrollmentRequest, array $uploadedFiles): void
    {
        $chain = [];

        if (! empty($uploadedFiles)) {
            $chain[] = new UploadEnrollmentFilesJob($enrollmentRequest->id, $uploadedFiles);
            $chain[] = new RegulaAnalysisJob($enrollmentRequest->id);
        }

        $chain[] = new AdvancedIdRequestJob($enrollmentRequest->email);

        if (! empty($chain)) {
            Bus::chain($chain)->dispatch();
        }
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
