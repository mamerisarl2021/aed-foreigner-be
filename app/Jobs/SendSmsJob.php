<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DataTransferObjects\SmsNotificationData;
use App\Enums\NotificationPlatform;
use App\Jobs\Notifications\SendSmsNotificationJob;
use App\Support\NotificationRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $phoneNumber,
        public readonly string $msg,
    ) {}

    public function handle(): void
    {
        SendSmsNotificationJob::dispatch(new SmsNotificationData(
            subject: $this->msg,
            recipients: [NotificationRecipient::phone($this->phoneNumber)],
            type: 'GENERIC_SMS',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
