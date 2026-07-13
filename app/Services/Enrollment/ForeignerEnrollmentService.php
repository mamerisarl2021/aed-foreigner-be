<?php

namespace App\Services\Enrollment;

use App\Jobs\AdvancedIdRequestJob;
use App\Jobs\ForeignerFinalizedJob;
use App\Jobs\ForeignerInitRegistrationJob;
use App\Jobs\ForeignerOtpJob;
use App\Jobs\PlanifiedEmailJob;
use App\Jobs\ProcessStructureFilesJob;
use App\Jobs\RegulaAnalysisJob;
use App\Models\Identity;
use App\Models\PendingRegistration;
use App\Models\StructurePackage;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\ServiceResult;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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

    public function initRegistration(string $email, ?UploadedFile $profile = null): ServiceResult
    {
        $email = strtolower(trim($email));

        if (! Cache::get('foreigner_otp_valid_'.$email)) {
            return ServiceResult::fail("Veuillez d'abord vérifier votre OTP.", null, 400);
        }

        $profilePath = null;
        if ($profile !== null) {
            $profilePath = Storage::cloud()->put('profiles', $profile);
        }

        $token = Str::random(64);
        $expiresAt = Carbon::now()->addHours(6);

        PendingRegistration::create([
            'npi' => '',
            'registration_token' => $token,
            'email' => $email,
            'status' => 'PENDING',
            'expires_at' => $expiresAt,
            'profile_path' => $profilePath,
            'user_data' => [
                'is_foreigner' => true,
                'form' => [],
                'cached_data' => [],
            ],
        ]);

        $link = rtrim(config('app.frontend_url'), '/').'/register/foreigner/'.$token;
        ForeignerInitRegistrationJob::dispatch($email, $link);

        return ServiceResult::ok('Inscription initialisée.', [
            'registration_token' => $token,
            'expires_at' => $expiresAt->toISOString(),
            'link' => $link,
        ]);
    }

    public function finalizeRegistration(Request $request, PendingRegistration $pending): ServiceResult
    {
        $isForeigner = $pending->user_data['is_foreigner'] ?? false;
        if (! $isForeigner) {
            return ServiceResult::fail('Flux non étranger non pris en charge ici.', null, 400);
        }

        $email = $pending->email;
        if (! Cache::get('foreigner_otp_valid_'.$email)) {
            return ServiceResult::fail("Veuillez d'abord vérifier votre OTP.", null, 400);
        }

        $uploadedFiles = $this->uploadEnrollmentFiles($request);

        $subscriptionValidation = $this->validateSubscription($request->input('transaction_id'));
        if (! $subscriptionValidation['status']) {
            return ServiceResult::fail(
                $subscriptionValidation['message'],
                $subscriptionValidation['data'],
                500
            );
        }

        DB::beginTransaction();
        try {
            $kyc = $request->input('kyc', []);
            $user = User::create([
                'npi' => null,
                'email' => $email,
                'name' => $kyc['name'] ?? null,
                'phonenumber' => $kyc['phonenumber'] ?? null,
                'nationality' => $kyc['nationality'] ?? null,
                'profile' => $pending->profile_path,
            ]);

            Identity::create([
                'type' => 'ONLINE',
                'proof' => json_encode([
                    'selfiePath' => $uploadedFiles['selfie'] ?? null,
                    'rectoPath' => $uploadedFiles['recto'] ?? null,
                    'versoPath' => $uploadedFiles['verso'] ?? null,
                    'liveness' => $request->input('liveness'),
                    'similarity' => $request->input('similarity'),
                    'exp_date' => $request->input('exp_date', ''),
                    'birth_date' => $request->input('birth_date', ''),
                    'document_type' => $kyc['document_type'] ?? null,
                    'document_number' => $kyc['document_number'] ?? null,
                    'nationality' => $kyc['nationality'] ?? null,
                ]),
                'level' => 'ADVANCED',
                'user_id' => $user->id,
                'status' => 'PENDING',
            ]);

            $this->createSubscriptionRecord($subscriptionValidation['data'], $user->id);
            $user->assignRole('client');

            Cache::forget('foreigner_otp_'.$email);
            Cache::forget('foreigner_otp_valid_'.$email);
            $pending->update(['status' => 'COMPLETED']);

            DB::commit();

            $this->dispatchPostRegistrationJobs($user, $request, null, $uploadedFiles);

            return ServiceResult::ok('Inscription finalisée.', [
                'user_id' => $user->id,
                'phonenumber' => $user->phonenumber,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Registration failed: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'email' => $email,
            ]);

            $this->cleanupUploadedFiles($uploadedFiles);

            return ServiceResult::fail('Erreur lors de la finalisation.', null, 500);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function uploadEnrollmentFiles(Request $request): array
    {
        $uploadedFiles = [];

        if ($request->input('type') === 'ONLINE') {
            if ($request->hasFile('selfie')) {
                $uploadedFiles['selfie'] = Storage::cloud()->put('selfies', $request->file('selfie'));
            }
            if ($request->hasFile('recto')) {
                $uploadedFiles['recto'] = Storage::cloud()->put('images', $request->file('recto'));
            }
            if ($request->hasFile('verso')) {
                $uploadedFiles['verso'] = Storage::cloud()->put('images', $request->file('verso'));
            }
        }

        return $uploadedFiles;
    }

    /**
     * @return array{status: bool, message: string, data: array<string, mixed>|null}
     */
    private function validateSubscription(string $transactionId): array
    {
        try {
            // $state = $this->kkiaPayment($transactionId); // Pour la prod
            $state = collect([json_encode(['package' => 7, 'amount' => 5000])]);

            $payload = json_decode($state[0], true);

            if (StructurePackage::where('id', $payload['package'])
                ->where('prix', (int) $payload['amount'])
                ->count() != 1
            ) {
                return [
                    'status' => false,
                    'message' => 'Ooops tentative de fraude détectée!',
                    'data' => [],
                ];
            }

            return [
                'status' => true,
                'message' => 'Abonnement validé.',
                'data' => $payload,
            ];
        } catch (Throwable $e) {
            Log::error('Subscription validation failed: '.$e->getMessage());

            return [
                'status' => false,
                'message' => "Échec de la validation de l'abonnement.",
                'data' => null,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createSubscriptionRecord(array $payload, string $userId): void
    {
        UserSubscription::where('user_id', $userId)
            ->where('type', 'CITIZEN')
            ->update(['current' => false]);

        UserSubscription::create([
            'user_id' => $userId,
            'current' => true,
            'status' => 'SENT',
            'package_id' => $payload['package'],
            'type' => 'CITIZEN',
        ]);
    }

    /**
     * @param  array<string, string|null>  $uploadedFiles
     */
    private function dispatchPostRegistrationJobs(User $user, Request $request, ?int $structureId, array $uploadedFiles): void
    {
        $type = $request->input('type');

        if ($type === 'IN_PERSON') {
            PlanifiedEmailJob::dispatch($user->email);
        } else {
            AdvancedIdRequestJob::dispatch($user->email);
        }
        ForeignerFinalizedJob::dispatch($user->email, $type);

        if ($type === 'ONLINE') {
            Log::info("Dispatching Regula analysis for user {$user->id}");
            $identity = Identity::where('user_id', $user->id)->latest()->first();
            if ($identity) {
                RegulaAnalysisJob::dispatch($identity->id);
            }
        }

        if ($structureId && $request->has('structure.attachements')) {
            ProcessStructureFilesJob::dispatch($structureId, $request->input('structure.attachements'), $request->allFiles());
        }
    }

    /**
     * @param  array<string, string|null>  $uploadedFiles
     */
    private function cleanupUploadedFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $path) {
            if ($path && Storage::cloud()->exists($path)) {
                Storage::cloud()->delete($path);
            }
        }
    }
}
