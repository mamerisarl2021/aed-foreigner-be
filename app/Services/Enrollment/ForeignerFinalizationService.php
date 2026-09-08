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
use App\Support\SecurityQuestions;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

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

    public function showByToken(string $token, ?string $npi = null): ServiceResult
    {
        $resolved = $this->resolveInvite($token, $npi, npiRequired: false);
        if ($resolved instanceof ServiceResult) {
            return $resolved;
        }

        [$user, $enrollment] = $resolved;

        return ServiceResult::ok('Demande éligible à la finalisation.', [
            'demande_id' => $enrollment->id,
            'numero_suivi' => $enrollment->tracking_code,
            'statut' => $enrollment->status->value,
            'email' => $user->email,
        ]);
    }

    public function sendOtp(string $npi, string $token): ServiceResult
    {
        $resolved = $this->resolveInvite($token, $npi, npiRequired: true);
        if ($resolved instanceof ServiceResult) {
            return $resolved;
        }

        [$user, $enrollment] = $resolved;
        $npiKey = (string) $user->npi;

        if ($this->tooManyOtpAttempts($npiKey)) {
            return ServiceResult::fail('Trop de tentatives. Demandez un nouveau code OTP.', null, 429);
        }

        $otp = (string) random_int(100000, 999999);
        Cache::put($this->otpKey($npiKey), hash('sha256', $otp), now()->addMinutes(self::OTP_TTL_MINUTES));
        Cache::forget($this->otpAttemptsKey($npiKey));

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Votre code OTP de finalisation AED',
            template: NotificationTemplate::ForeignerOtp,
            recipients: [NotificationRecipient::email($enrollment->email, ['otp' => $otp])],
            variables: [
                'otp' => $otp,
                'npi' => $npiKey,
                'numero_suivi' => $enrollment->tracking_code,
            ],
            type: 'FINALISATION_OTP_SEND',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));

        $this->activityLog->record(
            ActivityLogAction::OtpEnvoye,
            sprintf('OTP de finalisation envoyé pour le NPI %s.', $npiKey),
            null,
            $enrollment->id,
            ['context' => 'finalisation'],
        );

        return ServiceResult::ok('OTP de finalisation envoyé.', [
            'npi' => $npiKey,
            'demande_id' => $enrollment->id,
            'email' => $enrollment->email,
        ]);
    }

    public function verifyOtp(string $npi, string $otp, string $token): ServiceResult
    {
        $resolved = $this->resolveInvite($token, $npi, npiRequired: true);
        if ($resolved instanceof ServiceResult) {
            return $resolved;
        }

        [$user, $enrollment] = $resolved;
        $npiKey = (string) $user->npi;

        if ($this->tooManyOtpAttempts($npiKey)) {
            return ServiceResult::fail('Trop de tentatives. Demandez un nouveau code OTP.', null, 429);
        }

        $expected = Cache::get($this->otpKey($npiKey));
        if (! is_string($expected) || ! hash_equals($expected, hash('sha256', $otp))) {
            $this->recordOtpAttempt($npiKey);

            return ServiceResult::fail('OTP invalide ou expiré.', null, 400);
        }

        Cache::forget($this->otpKey($npiKey));
        Cache::forget($this->otpAttemptsKey($npiKey));
        Cache::put($this->otpVerifiedKey($npiKey), true, now()->addMinutes(self::OTP_PROOF_MINUTES));

        $this->activityLog->record(
            ActivityLogAction::OtpVerifie,
            sprintf('OTP de finalisation vérifié pour le NPI %s.', $npiKey),
            null,
            $enrollment->id,
            ['context' => 'finalisation'],
        );

        return ServiceResult::ok('OTP de finalisation valide.', [
            'npi' => $npiKey,
            'demande_id' => $enrollment->id,
            'otp_verified' => true,
        ]);
    }

    /**
     * @param  array<int, array{question: string, answer: string}>  $securityQuestions
     */
    public function finalize(
        string $npi,
        string $token,
        string $password,
        array $securityQuestions,
    ): ServiceResult {
        $resolved = $this->resolveInvite($token, $npi, npiRequired: true);
        if ($resolved instanceof ServiceResult) {
            return $resolved;
        }

        [$user, $enrollment] = $resolved;
        $npiKey = (string) $user->npi;

        if ($enrollment->status !== EnrollmentStatus::Approuvee) {
            return ServiceResult::fail('Demande non éligible à la finalisation.', null, 422);
        }

        if (! Cache::get($this->otpVerifiedKey($npiKey))) {
            return ServiceResult::fail('Veuillez d\'abord vérifier l\'OTP de finalisation.', null, 400);
        }

        if ($user->trustedx_registered_at === null) {
            return ServiceResult::fail('Identité TrustedX non enregistrée. Contactez le support.', null, 422);
        }

        if (count($securityQuestions) < 2) {
            return ServiceResult::fail('Deux questions de sécurité sont requises.', null, 422);
        }

        try {
            $hashedQuestions = SecurityQuestions::persist($securityQuestions);
        } catch (InvalidArgumentException $e) {
            return ServiceResult::fail($e->getMessage(), null, 422);
        }

        $lookup = $this->trustedXClient->getUserWithNPI((string) $user->npi);
        if (! ($lookup['status'] ?? false)) {
            return ServiceResult::fail($lookup['message'] ?? 'Impossible de récupérer le compte TrustedX.', null, 400);
        }

        $trustedXUserId = $lookup['data']['id'] ?? null;
        if ((! is_string($trustedXUserId) && ! is_int($trustedXUserId)) || $trustedXUserId === '') {
            return ServiceResult::fail('Identifiant TrustedX introuvable.', null, 400);
        }

        $generatedPin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $passwordOutput = $this->trustedXClient->setDefaultPassword(
            ['id' => $trustedXUserId, 'password' => $password],
            'password'
        );
        $pinOutput = $this->trustedXClient->setDefaultPassword(
            ['id' => $trustedXUserId, 'password' => $generatedPin],
            'pin'
        );

        if (! ($passwordOutput['status'] ?? false) || ! ($pinOutput['status'] ?? false)) {
            return ServiceResult::fail('Échec de la définition du mot de passe / PIN.', null, 400);
        }

        DB::beginTransaction();
        try {
            ClientLocalCredentials::apply($user, 'password', $password);
            ClientLocalCredentials::apply($user, 'pin', $generatedPin);
            $user->security_questions = $hashedQuestions;
            $user->status = 'ACTIVE';
            $user->save();

            $enrollment->status = EnrollmentStatus::Enrolee;
            $enrollment->save();

            PasswordResetToken::where('npi', $npiKey)->where('type', 'finalisation')->delete();
            Cache::forget($this->otpVerifiedKey($npiKey));

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
                'npi' => $npiKey,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Foreigner finalization failed: '.$e->getMessage(), [
                'enrollment_request_id' => $enrollment->id,
            ]);

            return ServiceResult::fail('Erreur lors de la finalisation.', null, 500);
        }
    }

    /**
     * @return array{0: User, 1: EnrollmentRequest}|ServiceResult
     */
    private function resolveInvite(string $token, ?string $npi, bool $npiRequired): array|ServiceResult
    {
        $resolved = $this->resolveToken($token);
        if ($resolved instanceof ServiceResult) {
            return $resolved;
        }

        [$user, $enrollment] = $resolved;
        $typed = $npi !== null ? trim($npi) : '';

        if ($npiRequired && $typed === '') {
            return ServiceResult::fail('Le NPI est obligatoire.', null, 422);
        }

        if ($typed !== '') {
            $expected = (string) $user->npi;
            if ($expected === '' || ! hash_equals($expected, $typed)) {
                return ServiceResult::fail('Lien de finalisation invalide.', null, 404);
            }
        }

        return [$user, $enrollment];
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

    private function otpKey(string $npi): string
    {
        return 'finalisation_otp_'.$npi;
    }

    private function otpVerifiedKey(string $npi): string
    {
        return 'finalisation_otp_verified_'.$npi;
    }

    private function otpAttemptsKey(string $npi): string
    {
        return 'finalisation_otp_attempts_'.$npi;
    }

    private function tooManyOtpAttempts(string $npi): bool
    {
        return (int) Cache::get($this->otpAttemptsKey($npi), 0) >= self::OTP_MAX_ATTEMPTS;
    }

    private function recordOtpAttempt(string $npi): void
    {
        $key = $this->otpAttemptsKey($npi);
        Cache::add($key, 0, now()->addMinutes(self::OTP_TTL_MINUTES));
        Cache::increment($key);
    }
}
