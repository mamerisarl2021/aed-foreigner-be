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

final class AdvancedIdRequestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly mixed $user,
    ) {}

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Demande d\'une identité de type avancée.',
            template: NotificationTemplate::AdvancedIdRequest,
            recipients: [
                NotificationRecipient::email($this->user, [
                    'user' => $this->user,
                ]),
            ],
            variables: [
                'user' => $this->user,
            ],
            type: 'ADVANCED_ID_REQUEST',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
