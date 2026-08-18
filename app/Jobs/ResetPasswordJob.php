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

final class ResetPasswordJob implements ShouldQueue
{
    use Queueable;
    use RetriesWithBackoff;

    public function __construct(
        public readonly mixed $user,
        public readonly string $link,
    ) {}

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Lien de réinitialisation',
            template: NotificationTemplate::ResetLink,
            recipients: [
                NotificationRecipient::email($this->user, [
                    'user' => $this->user,
                    'link' => $this->link,
                ]),
            ],
            variables: ['link' => $this->link],
            type: 'RESET_PASSWORD',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
