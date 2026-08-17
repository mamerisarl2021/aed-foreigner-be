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

final class MoraleEmailVerificationJob implements ShouldQueue
{
    use Queueable;
    use RetriesWithBackoff;

    public function __construct(
        public readonly string $email,
        public readonly string $legalName,
        public readonly string $verificationLink,
    ) {}

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Confirmez l\'email officiel de votre entreprise — AED',
            template: NotificationTemplate::SendInitLink,
            recipients: [
                NotificationRecipient::email($this->email, [
                    'code' => $this->verificationLink,
                    'type' => 'email officiel de l\'entreprise',
                ]),
            ],
            variables: [
                'code' => $this->verificationLink,
                'type' => 'email officiel de l\'entreprise',
            ],
            type: 'MORALE_EMAIL_VERIFICATION',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
