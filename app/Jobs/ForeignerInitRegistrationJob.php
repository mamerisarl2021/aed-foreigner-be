<?php

namespace App\Jobs;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Support\NotificationRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ForeignerInitRegistrationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $email,
        public readonly string $registrationLink,
    ) {}

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Complétez votre inscription',
            template: NotificationTemplate::ForeignerInitRegistration,
            recipients: [
                NotificationRecipient::email($this->email, [
                    'link' => $this->registrationLink,
                ]),
            ],
            variables: [
                'link' => $this->registrationLink,
            ],
            type: 'FOREIGNER_INIT_REGISTRATION',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
