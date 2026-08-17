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

final class SendInitLinkJob implements ShouldQueue
{
    use Queueable;
    use RetriesWithBackoff;

    public function __construct(
        public readonly mixed $user,
        public readonly string $code,
        public readonly string $type,
    ) {}

    public function handle(): void
    {
        $email = NotificationRecipient::resolveEmail($this->user);

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Demande du lien de mise à jour',
            template: NotificationTemplate::SendInitLink,
            recipients: [
                NotificationRecipient::email($this->user, [
                    'code' => $this->code,
                    'email' => $email,
                    'type' => $this->type,
                ]),
            ],
            variables: [
                'code' => $this->code,
                'email' => $email,
                'type' => $this->type,
            ],
            type: 'SEND_INIT_LINK',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
