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

final class WelcomeUserJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $email,
        public readonly mixed $user,
        public readonly string $activationUrl,
        public readonly bool $hasAccount = false,
    ) {}

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Activation de votre compte client',
            template: NotificationTemplate::UserAddedToAed,
            recipients: [
                NotificationRecipient::email($this->email, [
                    'user' => $this->user,
                    'activationUrl' => $this->activationUrl,
                    'hasAccount' => $this->hasAccount,
                ]),
            ],
            variables: [
                'activationUrl' => $this->activationUrl,
                'hasAccount' => $this->hasAccount,
            ],
            type: 'WELCOME_USER',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
