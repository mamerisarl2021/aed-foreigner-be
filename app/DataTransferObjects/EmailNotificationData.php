<?php

namespace App\DataTransferObjects;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use InvalidArgumentException;

final readonly class EmailNotificationData
{
    public function __construct(
        public string $subject,
        public NotificationTemplate $template,
        public array $recipients,
        public array $variables = [],
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
            'primaryChannel' => NotificationChannel::Email->value,
            'recipients' => $this->recipients,
            'templateName' => $this->template->value,
            'visibleInInterface' => $this->visibleInInterface,
        ];

        if ($this->type !== null) {
            $payload['type'] = $this->type;
        }

        if ($this->variables !== []) {
            $payload['variables'] = $this->variables;
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
