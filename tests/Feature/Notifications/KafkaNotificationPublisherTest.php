<?php

namespace Tests\Feature\Notifications;

use App\Contracts\NotificationPublisherInterface;
use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Support\NotificationRecipient;
use Junges\Kafka\Facades\Kafka;
use Tests\TestCase;

final class KafkaNotificationPublisherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.driver' => 'kafka']);
        $this->app->forgetInstance(NotificationPublisherInterface::class);
    }

    public function test_it_publishes_email_notifications_to_kafka(): void
    {
        Kafka::fake();

        app(NotificationPublisherInterface::class)->publishEmail(
            new EmailNotificationData(
                subject: 'Test subject',
                template: NotificationTemplate::UserAddedToAed,
                recipients: [NotificationRecipient::email('user@example.com')],
                type: 'TEST',
                platform: NotificationPlatform::Portal,
            )
        );

        Kafka::assertPublishedOn(config('notifications.topics.email'));
    }
}
