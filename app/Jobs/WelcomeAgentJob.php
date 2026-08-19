<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Concerns\RetriesWithBackoff;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Models\User;
use App\Services\Auth\AdminAuthService;
use App\Services\Auth\StaffKeycloakAdminClient;
use App\Support\NotificationRecipient;
use App\Support\StaffPasswordGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Hash;

final class WelcomeAgentJob implements ShouldQueue
{
    use Queueable;
    use RetriesWithBackoff;

    public function __construct(
        public readonly User $user,
    ) {}

    public function handle(): void
    {
        $defaultPassword = StaffPasswordGenerator::generate($this->user->email);
        $this->user->forceFill(['password' => Hash::make($defaultPassword)])->save();

        if (AdminAuthService::staffKeycloakEnabled()) {
            app(StaffKeycloakAdminClient::class)->setPasswordByEmail(
                (string) $this->user->email,
                $defaultPassword,
            );
        }

        $loginUrl = config('app.frontend_url').'/backoffice/login';

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Ajout d\'un compte agent',
            template: NotificationTemplate::AgentAddedToAed,
            recipients: [
                NotificationRecipient::email($this->user->email, [
                    'nom' => $this->user->name,
                    'prenom' => $this->user->first_name,
                    'email' => $this->user->email,
                    'default_password' => $defaultPassword,
                    'login_url' => $loginUrl,
                ]),
            ],
            variables: [
                'nom' => $this->user->name,
                'prenom' => $this->user->first_name,
                'email' => $this->user->email,
                'default_password' => $defaultPassword,
                'login_url' => $loginUrl,
            ],
            type: 'WELCOME_AGENT',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
