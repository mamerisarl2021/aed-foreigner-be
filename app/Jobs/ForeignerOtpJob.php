<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Support\NotificationRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ForeignerOtpJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $email,
        public readonly string $otp,
        public readonly int $ttlMinutes = 5,
    ) {}

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Votre code OTP',
            template: NotificationTemplate::ForeignerOtp,
            recipients: [
                NotificationRecipient::email($this->email, [
                    'otp' => $this->otp,
                    'ttl' => $this->ttlMinutes,
                ]),
            ],
            variables: [
                'otp' => $this->otp,
                'ttl' => $this->ttlMinutes,
            ],
            type: 'FOREIGNER_OTP',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
