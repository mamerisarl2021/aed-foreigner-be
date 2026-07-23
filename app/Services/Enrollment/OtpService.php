<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Contracts\EnrollmentEventPublisherInterface;
use App\DataTransferObjects\EmailNotificationData;
use App\DataTransferObjects\SmsNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\Notifications\SendSmsNotificationJob;
use App\Services\ServiceResult;
use App\Support\NotificationRecipient;
use Illuminate\Support\Facades\Cache;

final class OtpService
{
    private const TTL_MINUTES = 5;

    private const VALIDITY_MINUTES = 10;

    public function __construct(
        private readonly EnrollmentEventPublisherInterface $events,
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
            Cache::put($this->emailKey($email), $otp, now()->addMinutes(self::TTL_MINUTES));
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
            $otp = (string) random_int(100000, 999999);
            Cache::put($this->phoneKey($phone), $otp, now()->addMinutes(self::TTL_MINUTES));
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

        return ServiceResult::ok('OTP en cours d\'envoi.', [
            'email' => $email ?? null,
            'phonenumber' => $phonenumber ?? null,
        ]);
    }

    public function verify(?string $email, ?string $phonenumber, string $otp): ServiceResult
    {
        if ($email) {
            $email = strtolower(trim($email));
            $expected = Cache::get($this->emailKey($email));
            if (! $expected || $expected !== $otp) {
                return ServiceResult::fail('OTP email invalide ou expiré.', null, 400);
            }
            Cache::put($this->verifiedEmailKey($email), true, now()->addMinutes(self::VALIDITY_MINUTES));
            Cache::forget($this->emailKey($email));
        }

        if ($phonenumber) {
            $phone = $this->normalizePhone($phonenumber);
            $expected = Cache::get($this->phoneKey($phone));
            if (! $expected || $expected !== $otp) {
                return ServiceResult::fail('OTP téléphone invalide ou expiré.', null, 400);
            }
            Cache::put($this->verifiedPhoneKey($phone), true, now()->addMinutes(self::VALIDITY_MINUTES));
            Cache::forget($this->phoneKey($phone));
        }

        if (! $email && ! $phonenumber) {
            return ServiceResult::fail('Email ou numéro de téléphone requis.', null, 422);
        }

        return ServiceResult::ok('OTP valide — email & téléphone confirmés.', [
            'email_verified' => (bool) $email,
            'phone_verified' => (bool) $phonenumber,
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
        return preg_replace('/[^\d+]/', '', trim($phonenumber)) ?? '';
    }

    private function emailKey(string $email): string
    {
        return 'enrollment_otp_email_'.$email;
    }

    private function phoneKey(string $phone): string
    {
        return 'enrollment_otp_phone_'.$phone;
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
