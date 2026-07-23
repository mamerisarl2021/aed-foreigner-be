<?php

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
        public readonly mixed $user,
        public readonly string $defaultPassword,
    ) {}

    public function handle(): void
    {
        $loginUrl = config('app.frontend_url').'/backoffice/login';

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Ajout d\'un compte agent',
            template: NotificationTemplate::AgentAddedToAed,
            recipients: [
                NotificationRecipient::email($this->user->email, [
                    'nom' => $this->user->name,
                    'prenom' => $this->user->first_name,
                    'email' => $this->user->email,
                    'default_password' => $this->defaultPassword,
                    'login_url' => $loginUrl,
                ]),
            ],
            variables: [
                'nom' => $this->user->name,
                'prenom' => $this->user->first_name,
                'email' => $this->user->email,
                'default_password' => $this->defaultPassword,
                'login_url' => $loginUrl,
            ],
            type: 'WELCOME_AGENT',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
