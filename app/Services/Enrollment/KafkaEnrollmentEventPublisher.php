<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Contracts\EnrollmentEventPublisherInterface;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\Message;

final class KafkaEnrollmentEventPublisher implements EnrollmentEventPublisherInterface
{
    public function publish(string $event, array $payload): void
    {
        $topic = config("enrollment_events.topics.{$event}");
        if (! is_string($topic) || $topic === '') {
            throw new \InvalidArgumentException("Unknown enrollment event: {$event}");
        }

        $producer = Kafka::asyncPublish((string) config('kafka.brokers'))->onTopic($topic);
        $producer->withMessage(new Message(body: array_merge($payload, ['event' => $event])))
            ->send();
    }
}
