<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\NotificationPublisherInterface;
use App\DataTransferObjects\EmailNotificationData;
use App\DataTransferObjects\SmsNotificationData;
use App\DataTransferObjects\WebsocketNotificationData;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\Message;
use Junges\Kafka\Producers\Builder as ProducerBuilder;

final class KafkaNotificationPublisher implements NotificationPublisherInterface
{
    public function publishEmail(EmailNotificationData $notification): void
    {
        $this->publish(config('notifications.topics.email'), $notification->toArray());
    }

    public function publishSms(SmsNotificationData $notification): void
    {
        $this->publish(config('notifications.topics.sms'), $notification->toArray());
    }

    public function publishWebsocket(WebsocketNotificationData $notification): void
    {
        $this->publish(config('notifications.topics.websocket'), $notification->toArray());
    }

    private function publish(string $topic, array $payload): void
    {
        $producer = Kafka::asyncPublish((string) config('kafka.brokers'))
            ->onTopic($topic);

        $producer = $this->applySecurity($producer);

        $producer->withMessage(new Message(body: $payload))
            ->send();
    }

    private function applySecurity(object $producer): object // ProducerBuilder
    {
        $protocol = (string) config('kafka.securityProtocol');

        if (in_array($protocol, ['SASL_PLAINTEXT', 'SASL_SSL'], true)) {
            return $producer->withSasl(
                username: config('kafka.sasl.username'),
                password: config('kafka.sasl.password'),
                mechanisms: config('kafka.sasl.mechanisms'),
                securityProtocol: $protocol,
            );
        }

        if ($protocol === 'SSL') {
            return $producer->withConfigOptions(array_filter([
                'security.protocol' => 'SSL',
                'ssl.ca.location' => config('kafka.ssl.ca_location'),
                'ssl.certificate.location' => config('kafka.ssl.cert_location'),
                'ssl.key.location' => config('kafka.ssl.key_location'),
                'ssl.endpoint.identification.algorithm' => config('kafka.ssl.endpoint_identification_algorithm'),
            ]));
        }

        return $producer;
    }
}
