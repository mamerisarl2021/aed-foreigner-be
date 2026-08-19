<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPlatform;
use InvalidArgumentException;

final readonly class SmsNotificationData
{
    public function __construct(
        public string $subject,
        public array $recipients,
        public ?string $type = null,
        public NotificationPlatform $platform = NotificationPlatform::Portal,
        public ?NotificationChannel $secondaryChannel = null,
        public ?int $fallbackDelay = null,
        public bool $visibleInInterface = true,
    ) {
        if ($this->recipients === []) {
            throw new InvalidArgumentException('At least one recipient is required.');
        }
    }

    public function toArray(): array
    {
        $payload = [
            'subject' => $this->subject,
            'platform' => $this->platform->value,
            'primaryChannel' => NotificationChannel::Sms->value,
            'recipients' => $this->recipients,
            'visibleInInterface' => $this->visibleInInterface,
        ];

        if ($this->type !== null) {
            $payload['type'] = $this->type;
        }

        if ($this->secondaryChannel !== null) {
            $payload['secondaryChannel'] = $this->secondaryChannel->value;

            if ($this->fallbackDelay !== null) {
                $payload['fallbackDelay'] = $this->fallbackDelay;
            }
        }

        return $payload;
    }
}
