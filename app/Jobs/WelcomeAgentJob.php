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

final class WelcomeAgentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly mixed  $user,
        public readonly string $link,
    )
    {
    }

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Ajout d\'un compte agent',
            template: NotificationTemplate::AgentAddedToAed,
            recipients: [
                NotificationRecipient::email($this->user, [
                    'user' => $this->user,
                    'link' => $this->link,
                ]),
            ],
            variables: ['link' => $this->link],
            type: 'WELCOME_AGENT',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
