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

final class AdvancedIdMidJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly string $link,
    ) {}

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Paiement enregistré.',
            template: NotificationTemplate::AdvancedIdMid,
            recipients: [
                NotificationRecipient::email($this->email, [
                    'email' => $this->email,
                    'name' => $this->name,
                    'link' => $this->link,
                ]),
            ],
            variables: [
                'email' => $this->email,
                'name' => $this->name,
                'link' => $this->link,
            ],
            type: 'ADVANCED_ID_MID',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
