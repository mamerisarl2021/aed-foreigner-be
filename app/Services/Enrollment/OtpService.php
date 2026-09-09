<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Contracts\EnrollmentEventPublisherInterface;
use App\DataTransferObjects\EmailNotificationData;
use App\DataTransferObjects\SmsNotificationData;
use App\Enums\ActivityLogAction;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\Notifications\SendSmsNotificationJob;
use App\Rules\PhoneNumber;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use App\Support\NotificationRecipient;
use Illuminate\Support\Facades\Cache;

final class OtpService
{
    private const TTL_MINUTES = 5;

    private const VALIDITY_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly EnrollmentEventPublisherInterface $events,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function send(?string $email, ?string $phonenumber): ServiceResult
    {
        if (! $email && ! $phonenumber) {
            return ServiceResult::fail('Email ou numéro de téléphone requis.', null, 422);
        }

        $channels = [];

        if ($email) {
            $email = strtolower(trim($email));
            $otp = (string) random_int(100000, 999999);
            Cache::put($this->emailKey($email), hash('sha256', $otp), now()->addMinutes(self::TTL_MINUTES));
            Cache::forget($this->attemptsKey('email', $email));
            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: 'Votre code OTP AED',
                template: NotificationTemplate::ForeignerOtp,
                recipients: [NotificationRecipient::email($email, ['otp' => $otp])],
                variables: ['otp' => $otp],
                type: 'OTP_SEND',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));
            $channels[] = 'email';
        }

        if ($phonenumber) {
            $phone = $this->normalizePhone($phonenumber);
            if ($phone === '' || ! PhoneNumber::isValid($phonenumber)) {
                return ServiceResult::fail('Numéro de téléphone invalide.', null, 422);
            }
            $otp = (string) random_int(100000, 999999);
            Cache::put($this->phoneKey($phone), hash('sha256', $otp), now()->addMinutes(self::TTL_MINUTES));
            Cache::forget($this->attemptsKey('phone', $phone));
            SendSmsNotificationJob::dispatch(new SmsNotificationData(
                subject: "Votre code OTP AED est : {$otp} (valide ".self::TTL_MINUTES.' minutes).',
                recipients: [NotificationRecipient::phone($phone, ['otp' => $otp])],
                type: 'OTP_SEND',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));
            $channels[] = 'sms';
        }

        $this->events->publish('otp_send', [
            'email' => $email ?? null,
            'phonenumber' => isset($phone) ? $phone : null,
            'channels' => $channels,
        ]);

        $this->activityLog->record(
            ActivityLogAction::OtpEnvoye,
            sprintf('OTP enrollment envoyé (%s).', implode(', ', $channels)),
            null,
            null,
            [
                'email' => $email ?? null,
                'phonenumber' => isset($phone) ? $phone : null,
                'channels' => $channels,
            ],
        );

        return ServiceResult::ok('OTP en cours d\'envoi.', [
            'email' => $email ?? null,
            'phonenumber' => isset($phone) ? $phone : ($phonenumber ?? null),
        ]);
    }

    public function verify(?string $email, ?string $phonenumber, string $otp): ServiceResult
    {
        if (! $email && ! $phonenumber) {
            return ServiceResult::fail('Email ou numéro de téléphone requis.', null, 422);
        }

        $email = $email ? strtolower(trim($email)) : null;
        $phone = null;
        if ($phonenumber) {
            $phone = $this->normalizePhone($phonenumber);
            if ($phone === '' || ! PhoneNumber::isValid($phonenumber)) {
                return ServiceResult::fail('Numéro de téléphone invalide.', null, 422);
            }
        }

        $matched = false;

        // Prefer matching a single channel when both contacts are sent (different OTPs per channel).
        if ($email !== null) {
            $emailAttempt = $this->attemptOtp(
                'email',
                $email,
                $otp,
                $this->emailKey($email),
                $this->verifiedEmailKey($email),
                $phone === null,
                'OTP email invalide ou expiré.',
            );
            if ($emailAttempt instanceof ServiceResult) {
                return $emailAttempt;
            }
            $matched = $emailAttempt;
        }

        if (! $matched && $phone !== null) {
            $phoneAttempt = $this->attemptOtp(
                'phone',
                $phone,
                $otp,
                $this->phoneKey($phone),
                $this->verifiedPhoneKey($phone),
                $email === null,
                'OTP téléphone invalide ou expiré.',
            );
            if ($phoneAttempt instanceof ServiceResult) {
                return $phoneAttempt;
            }
            $matched = $phoneAttempt;
        }

        if (! $matched) {
            if ($email !== null) {
                $this->recordFailedAttempt('email', $email);
            }
            if ($phone !== null) {
                $this->recordFailedAttempt('phone', $phone);
            }

            return ServiceResult::fail('OTP invalide ou expiré.', null, 400);
        }

        $this->activityLog->record(
            ActivityLogAction::OtpVerifie,
            'OTP enrollment vérifié avec succès.',
            null,
            null,
            [
                'email' => $email,
                'phonenumber' => $phone,
            ],
        );

        return ServiceResult::ok('OTP valide.', [
            'email_verified' => $email !== null && (bool) Cache::get($this->verifiedEmailKey($email)),
            'phone_verified' => $phone !== null && (bool) Cache::get($this->verifiedPhoneKey($phone)),
            'email_verifie' => $email !== null && (bool) Cache::get($this->verifiedEmailKey($email)),
            'telephone_verifie' => $phone !== null && (bool) Cache::get($this->verifiedPhoneKey($phone)),
        ]);
    }

    public function bothChannelsVerified(string $email, string $phonenumber): bool
    {
        $email = strtolower(trim($email));
        $phone = $this->normalizePhone($phonenumber);

        return (bool) Cache::get($this->verifiedEmailKey($email))
            && (bool) Cache::get($this->verifiedPhoneKey($phone));
    }

    public function clearVerificationFlags(string $email, string $phonenumber): void
    {
        Cache::forget($this->verifiedEmailKey(strtolower(trim($email))));
        Cache::forget($this->verifiedPhoneKey($this->normalizePhone($phonenumber)));
    }

    public function normalizePhone(string $phonenumber): string
    {
        return PhoneNumber::normalize($phonenumber);
    }

    /**
     * @return ServiceResult|bool true when the OTP matches, false when another channel may still match
     */
    private function attemptOtp(
        string $channel,
        string $identifier,
        string $otp,
        string $cacheKey,
        string $verifiedKey,
        bool $isOnlyChannel,
        string $invalidMessage,
    ): ServiceResult|bool {
        if ($this->tooManyAttempts($channel, $identifier)) {
            return ServiceResult::fail('Trop de tentatives. Demandez un nouveau code OTP.', null, 429);
        }

        $expected = Cache::get($cacheKey);
        if (is_string($expected) && hash_equals($expected, hash('sha256', $otp))) {
            Cache::put($verifiedKey, true, now()->addMinutes(self::VALIDITY_MINUTES));
            Cache::forget($cacheKey);
            Cache::forget($this->attemptsKey($channel, $identifier));

            return true;
        }

        if ($isOnlyChannel) {
            $this->recordFailedAttempt($channel, $identifier);

            return ServiceResult::fail($invalidMessage, null, 400);
        }

        return false;
    }

    private function tooManyAttempts(string $channel, string $identifier): bool
    {
        return (int) Cache::get($this->attemptsKey($channel, $identifier), 0) >= self::MAX_ATTEMPTS;
    }

    private function recordFailedAttempt(string $channel, string $identifier): void
    {
        $key = $this->attemptsKey($channel, $identifier);
        Cache::add($key, 0, now()->addMinutes(self::TTL_MINUTES));
        Cache::increment($key);
    }

    private function emailKey(string $email): string
    {
        return 'enrollment_otp_email_'.$email;
    }

    private function phoneKey(string $phone): string
    {
        return 'enrollment_otp_phone_'.$phone;
    }

    private function attemptsKey(string $channel, string $identifier): string
    {
        return 'enrollment_otp_attempts_'.$channel.'_'.$identifier;
    }

    private function verifiedEmailKey(string $email): string
    {
        return 'enrollment_otp_verified_email_'.$email;
    }

    private function verifiedPhoneKey(string $phone): string
    {
        return 'enrollment_otp_verified_phone_'.$phone;
    }
}
