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

final class ForeignerFinalizedJob implements ShouldQueue
{
    use Queueable;
    use RetriesWithBackoff;

    public function __construct(
        public readonly string $email,
        public readonly string $type,
        public readonly string $name = '',
        public readonly ?string $numeroSuivi = null,
    ) {}

    public function handle(): void
    {
        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Confirmation de soumission — enrôlement AED',
            template: NotificationTemplate::ForeignerFinalized,
            recipients: [
                NotificationRecipient::email($this->email, [
                    'name' => $this->name,
                    'type' => $this->type,
                    'numero_suivi' => $this->numeroSuivi,
                ]),
            ],
            variables: [
                'name' => $this->name,
                'type' => $this->type,
                'numero_suivi' => $this->numeroSuivi,
            ],
            type: 'ENROLEMENT_SUBMITTED',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
