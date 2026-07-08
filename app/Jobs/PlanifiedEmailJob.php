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

final class PlanifiedEmailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly mixed $user,
    )
    {
    }

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Planification d\'une rencontre en face à face.',
            template: NotificationTemplate::Planned,
            recipients: [
                NotificationRecipient::email($this->user, [
                    'user' => $this->user,
                ]),
            ],
            variables: [
                'user' => $this->user,
            ],
            type: 'PLANIFIED_EMAIL',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
