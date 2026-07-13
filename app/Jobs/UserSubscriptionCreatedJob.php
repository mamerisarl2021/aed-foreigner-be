<?php

namespace App\Jobs;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Support\NotificationRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class UserSubscriptionCreatedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly mixed $user,
        public readonly mixed $processId,
    ) {}

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Souscription crée avec succès',
            template: NotificationTemplate::UserSubscriptionCreated,
            recipients: [
                NotificationRecipient::email($this->user, [
                    'user' => $this->user,
                    'processId' => $this->processId,
                ]),
            ],
            variables: [
                'user' => $this->user,
                'processId' => $this->processId,
            ],
            type: 'USER_SUBSCRIPTION_CREATED',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
