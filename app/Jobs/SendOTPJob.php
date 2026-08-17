<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Concerns\RetriesWithBackoff;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Support\NotificationRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendOTPJob implements ShouldQueue
{
    use Queueable;
    use RetriesWithBackoff;

    public function __construct(
        public readonly mixed $user,
        public readonly string $code,
    ) {}

    public function handle(): void
    {
        $email = NotificationRecipient::resolveEmail($this->user);

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Demande du code OTP',
            template: NotificationTemplate::SendOtp,
            recipients: [
                NotificationRecipient::email($this->user, [
                    'code' => $this->code,
                    'email' => $email,
                ]),
            ],
            variables: [
                'code' => $this->code,
                'email' => $email,
            ],
            type: 'SEND_OTP',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
