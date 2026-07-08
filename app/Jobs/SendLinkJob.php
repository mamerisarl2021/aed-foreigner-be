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

final class SendLinkJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly mixed $user,
        public readonly string $code,
    ) {}

    public function handle(): void
    {
        $email = NotificationRecipient::resolveEmail($this->user);

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Demande du lien de mise à jour',
            template: NotificationTemplate::SendLink,
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
            type: 'SEND_LINK',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
