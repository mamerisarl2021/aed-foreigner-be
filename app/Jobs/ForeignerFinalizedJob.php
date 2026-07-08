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

final class ForeignerFinalizedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $email,
        public readonly string $type,
    ) {}

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Votre demande a été enregistrée',
            template: NotificationTemplate::ForeignerFinalized,
            recipients: [
                NotificationRecipient::email($this->email, [
                    'type' => $this->type,
                ]),
            ],
            variables: [
                'type' => $this->type,
            ],
            type: 'FOREIGNER_FINALIZED',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
