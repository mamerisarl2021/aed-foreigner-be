<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Contracts\EnrollmentEventPublisherInterface;
use App\DataTransferObjects\EmailNotificationData;
use App\Enums\ActivityLogAction;
use App\Enums\EnrollmentStatus;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Models\EnrollmentRequest;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\PKI\TrustedXClientService;
use App\Services\ServiceResult;
use App\Support\ClientLocalCredentials;
use App\Support\NotificationRecipient;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ForeignerFinalizationService
{
    private const OTP_TTL_MINUTES = 5;

    private const OTP_PROOF_MINUTES = 15;

    private const OTP_MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly TrustedXClientService $trustedXClient,
        private readonly EnrollmentEventPublisherInterface $events,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function showByToken(string $token): ServiceResult
    {
        $resolved = $this->resolveToken($token);
        if ($resolved instanceof ServiceResult) {
            return $resolved;
        }

        [$user, $enrollment] = $resolved;

        return ServiceResult::ok('Demande éligible à la finalisation.', [
            'demande_id' => $enrollment->id,
            'numero_suivi' => $enrollment->tracking_code,
            'statut' => $enrollment->status->value,
            'npi' => $user->npi,
            'email' => $user->email,
        ]);
    }

    public function sendOtp(string $numeroSuivi): ServiceResult
    {
        $enrollment = $this->findApprouveeByTracking($numeroSuivi);
        if ($enrollment instanceof ServiceResult) {
            return $enrollment;
        }

        $code = strtoupper(trim($numeroSuivi));
        if ($this->tooManyOtpAttempts($code)) {
            return ServiceResult::fail('Trop de tentatives. Demandez un nouveau code OTP.', null, 429);
        }

        $otp = (string) random_int(100000, 999999);
        Cache::put($this->otpKey($code), hash('sha256', $otp), now()->addMinutes(self::OTP_TTL_MINUTES));
        Cache::forget($this->otpAttemptsKey($code));

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Votre code OTP de finalisation AED',
            template: NotificationTemplate::ForeignerOtp,
            recipients: [NotificationRecipient::email($enrollment->email, ['otp' => $otp])],
            variables: [
                'otp' => $otp,
                'numero_suivi' => $enrollment->tracking_code,
            ],
            type: 'FINALISATION_OTP_SEND',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));

        $this->activityLog->record(
            ActivityLogAction::OtpEnvoye,
            sprintf('OTP de finalisation envoyé pour %s.', $enrollment->tracking_code),
            null,
            $enrollment->id,
            ['context' => 'finalisation'],
        );

        return ServiceResult::ok('OTP de finalisation envoyé.', [
            'numero_suivi' => $enrollment->tracking_code,
            'demande_id' => $enrollment->id,
            'email' => $enrollment->email,
        ]);
    }

    public function verifyOtp(string $numeroSuivi, string $otp): ServiceResult
    {
        $enrollment = $this->findApprouveeByTracking($numeroSuivi);
        if ($enrollment instanceof ServiceResult) {
            return $enrollment;
        }

        $code = strtoupper(trim($numeroSuivi));
        if ($this->tooManyOtpAttempts($code)) {
            return ServiceResult::fail('Trop de tentatives. Demandez un nouveau code OTP.', null, 429);
        }

        $expected = Cache::get($this->otpKey($code));
        if (! is_string($expected) || ! hash_equals($expected, hash('sha256', $otp))) {
            $this->recordOtpAttempt($code);

            return ServiceResult::fail('OTP invalide ou expiré.', null, 400);
        }

        Cache::forget($this->otpKey($code));
        Cache::forget($this->otpAttemptsKey($code));
        Cache::put($this->otpVerifiedKey($code), true, now()->addMinutes(self::OTP_PROOF_MINUTES));

        $this->activityLog->record(
            ActivityLogAction::OtpVerifie,
            sprintf('OTP de finalisation vérifié pour %s.', $enrollment->tracking_code),
            null,
            $enrollment->id,
            ['context' => 'finalisation'],
        );

        return ServiceResult::ok('OTP de finalisation valide.', [
            'numero_suivi' => $enrollment->tracking_code,
            'demande_id' => $enrollment->id,
            'otp_verified' => true,
        ]);
    }

    /**
     * @param  array<int, array{question: string, answer: string}>  $securityQuestions
     */
    public function finalize(
        string $demandeId,
        string $password,
        array $securityQuestions,
        ?string $token = null,
        ?string $numeroSuivi = null,
    ): ServiceResult {
        $enrollment = EnrollmentRequest::findOrFail($demandeId);
        if ($enrollment->status !== EnrollmentStatus::Approuvee) {
            return ServiceResult::fail('Demande non éligible à la finalisation.', null, 422);
        }

        $user = null;

        if (is_string($token) && $token !== '') {
            $resolved = $this->resolveToken($token);
            if ($resolved instanceof ServiceResult) {
                return $resolved;
            }
            [$user, $tokenEnrollment] = $resolved;
            if ($tokenEnrollment->id !== $enrollment->id) {
                return ServiceResult::fail('Token de finalisation ne correspond pas à la demande.', null, 422);
            }
        }

        $tracking = $numeroSuivi !== null && trim($numeroSuivi) !== ''
            ? strtoupper(trim($numeroSuivi))
            : strtoupper((string) $enrollment->tracking_code);

        if ($tracking === '' || strtoupper((string) $enrollment->tracking_code) !== $tracking) {
            return ServiceResult::fail('Numéro de suivi invalide.', null, 422);
        }

        if (! Cache::get($this->otpVerifiedKey($tracking))) {
            return ServiceResult::fail('Veuillez d\'abord vérifier l\'OTP de finalisation.', null, 400);
        }

        if ($user === null) {
            $user = User::where('email', $enrollment->email)->first();
        }
        if (! $user) {
            return ServiceResult::fail('Utilisateur introuvable.', null, 404);
        }

        if ($user->trustedx_registered_at === null) {
            return ServiceResult::fail('Identité TrustedX non enregistrée. Contactez le support.', null, 422);
        }

        if (count($securityQuestions) < 2) {
            return ServiceResult::fail('Deux questions de sécurité sont requises.', null, 422);
        }

        $generatedPin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        DB::beginTransaction();
        try {
            $lookup = $this->trustedXClient->getUserWithNPI($user->npi);
            if (! ($lookup['status'] ?? false)) {
                DB::rollBack();

                return ServiceResult::fail($lookup['message'] ?? 'Impossible de récupérer le compte TrustedX.', null, 400);
            }

            $trustedXUserId = $lookup['data']['id'] ?? null;
            if (! $trustedXUserId) {
                DB::rollBack();

                return ServiceResult::fail('Identifiant TrustedX introuvable.', null, 400);
            }

            $passwordOutput = $this->trustedXClient->setDefaultPassword(
                ['id' => $trustedXUserId, 'password' => $password],
                'password'
            );
            $pinOutput = $this->trustedXClient->setDefaultPassword(
                ['id' => $trustedXUserId, 'password' => $generatedPin],
                'pin'
            );

            if (! ($passwordOutput['status'] ?? false) || ! ($pinOutput['status'] ?? false)) {
                DB::rollBack();

                return ServiceResult::fail('Échec de la définition du mot de passe / PIN.', null, 400);
            }

            ClientLocalCredentials::apply($user, 'password', $password);
            ClientLocalCredentials::apply($user, 'pin', $generatedPin);
            $user->security_questions = $securityQuestions;
            $user->status = 'ACTIVE';
            $user->save();

            $enrollment->status = EnrollmentStatus::Enrolee;
            $enrollment->save();

            if (is_string($token) && $token !== '') {
                PasswordResetToken::where('token', hash('sha256', $token))->delete();
            } else {
                PasswordResetToken::where('npi', $user->npi)->where('type', 'finalisation')->delete();
            }

            Cache::forget($this->otpVerifiedKey($tracking));

            $this->events->publish('completed', [
                'demande_id' => $enrollment->id,
                'npi' => $user->npi,
                'statut' => $enrollment->status->value,
            ]);

            $this->activityLog->record(
                ActivityLogAction::EnrolementFinalise,
                sprintf(
                    '%s %s a finalisé son enrôlement.',
                    $user->first_name,
                    $user->name
                ),
                $user->id,
                $enrollment->id,
            );

            DB::commit();

            return ServiceResult::ok('Enrôlement finalisé.', [
                'demande_id' => $enrollment->id,
                'numero_suivi' => $enrollment->tracking_code,
                'statut' => $enrollment->status->value,
                'npi' => $user->npi,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Foreigner finalization failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ServiceResult::fail('Erreur lors de la finalisation.', null, 500);
        }
    }

    /**
     * @return array{0: User, 1: EnrollmentRequest}|ServiceResult
     */
    private function resolveToken(string $token): array|ServiceResult
    {
        $tokenData = PasswordResetToken::where('token', hash('sha256', $token))->where('type', 'finalisation')->first();
        if (! $tokenData) {
            return ServiceResult::fail('Lien de finalisation invalide.', null, 404);
        }

        if (Carbon::parse($tokenData->created_at)->addMinutes(60)->isPast()) {
            return ServiceResult::fail('Lien de finalisation expiré.', null, 400);
        }

        $user = User::where('npi', $tokenData->npi)->first();
        if (! $user) {
            return ServiceResult::fail('Utilisateur introuvable.', null, 404);
        }

        $enrollment = EnrollmentRequest::query()
            ->where('email', $user->email)
            ->where('status', EnrollmentStatus::Approuvee->value)
            ->latest('created_at')
            ->first();

        if (! $enrollment) {
            return ServiceResult::fail('Aucune demande en attente de finalisation.', null, 404);
        }

        return [$user, $enrollment];
    }

    private function findApprouveeByTracking(string $numeroSuivi): EnrollmentRequest|ServiceResult
    {
        $code = strtoupper(trim($numeroSuivi));
        if ($code === '') {
            return ServiceResult::fail('Numéro de suivi requis.', null, 422);
        }

        $enrollment = EnrollmentRequest::query()
            ->where('tracking_code', $code)
            ->where('status', EnrollmentStatus::Approuvee->value)
            ->first();

        if (! $enrollment) {
            return ServiceResult::fail('Demande introuvable ou non éligible à la finalisation.', null, 404);
        }

        return $enrollment;
    }

    private function otpKey(string $trackingCode): string
    {
        return 'finalisation_otp_'.$trackingCode;
    }

    private function otpVerifiedKey(string $trackingCode): string
    {
        return 'finalisation_otp_verified_'.$trackingCode;
    }

    private function otpAttemptsKey(string $trackingCode): string
    {
        return 'finalisation_otp_attempts_'.$trackingCode;
    }

    private function tooManyOtpAttempts(string $trackingCode): bool
    {
        return (int) Cache::get($this->otpAttemptsKey($trackingCode), 0) >= self::OTP_MAX_ATTEMPTS;
    }

    private function recordOtpAttempt(string $trackingCode): void
    {
        $key = $this->otpAttemptsKey($trackingCode);
        Cache::add($key, 0, now()->addMinutes(self::OTP_TTL_MINUTES));
        Cache::increment($key);
    }
}
