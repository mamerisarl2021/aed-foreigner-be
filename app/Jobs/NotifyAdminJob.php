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

final class NotifyAdminJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly mixed $user,
        public readonly string $code,
    ) {}

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Demande du code OTP',
            template: NotificationTemplate::NotifyAdmin,
            recipients: [
                NotificationRecipient::email($this->user, [
                    'code' => $this->code,
                    'email' => NotificationRecipient::resolveEmail($this->user),
                ]),
            ],
            variables: [
                'code' => $this->code,
                'email' => NotificationRecipient::resolveEmail($this->user),
            ],
            type: 'NOTIFY_ADMIN',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
