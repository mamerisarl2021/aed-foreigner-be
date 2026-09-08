<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPlatform;
use InvalidArgumentException;

final readonly class WebsocketNotificationData
{
    /**
     * @param  list<array<string, mixed>>  $recipients
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public string $subject,
        public array $recipients,
        public array $variables = [],
        public ?string $type = null,
        public NotificationPlatform $platform = NotificationPlatform::Portal,
        public bool $visibleInInterface = true,
    ) {
        if ($this->recipients === []) {
            throw new InvalidArgumentException('Au moins un destinataire est requis.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'subject' => $this->subject,
            'platform' => $this->platform->value,
            'primaryChannel' => NotificationChannel::Websocket->value,
            'recipients' => $this->recipients,
            'visibleInInterface' => $this->visibleInInterface,
        ];

        if ($this->type !== null) {
            $payload['type'] = $this->type;
        }

        if ($this->variables !== []) {
            $payload['variables'] = $this->variables;
        }

        return $payload;
    }
}
