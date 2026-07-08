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

final class ScheduledAppointmentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $email,
        public readonly mixed $data,
    ) {}

    public function handle(): void
    {
        $variables = is_array($this->data) ? $this->data : ['data' => $this->data];

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Validation de votre rendez-vous de vérification d\'identité',
            template: NotificationTemplate::Scheduled,
            recipients: [
                NotificationRecipient::email($this->email, $variables),
            ],
            variables: $variables,
            type: 'SCHEDULED_APPOINTMENT',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
