#!/usr/bin/env bash
# Smoke test Kafka (broker + topics AED). Usage: ./testkafka.sh
set -euo pipefail

CONTAINER="${KAFKA_CONTAINER:-kafka1}"
KAFKA_BIN="${KAFKA_BIN:-/opt/kafka/bin}"
# Depuis l'intérieur du conteneur (listener INTERNAL).
BOOTSTRAP_INTERNAL="${KAFKA_BOOTSTRAP_INTERNAL:-kafka1:29092}"
# Depuis l'hôte Docker (listener EXTERNAL).
BOOTSTRAP_HOST="${KAFKA_BROKERS:-localhost:9092}"

TOPICS=(
    "${KAFKA_TOPIC_EMAIL:-notify.email}"
    "${KAFKA_TOPIC_SMS:-notify.sms}"
    "${KAFKA_TOPIC_WEBSOCKET:-notify.websocket}"
    "${KAFKA_TOPIC_OTP_SEND:-enrolement.otp.send}"
    "${KAFKA_TOPIC_ENROLEMENT_CREATED:-enrolement.created}"
    "${KAFKA_TOPIC_ENROLEMENT_STATUS_CHANGED:-enrolement.status_changed}"
    "${KAFKA_TOPIC_ENROLEMENT_APPROVED:-enrolement.approved}"
    "${KAFKA_TOPIC_ENROLEMENT_REJECTED:-enrolement.rejected}"
    "${KAFKA_TOPIC_ENROLEMENT_COMPLETED:-enrolement.completed}"
)

PROBE_TOPIC="${KAFKA_TOPIC_EMAIL:-notify.email}"
PROBE_VALUE="{\"source\":\"test-kafka.sh\",\"at\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\"}"

kafka_sh() {
    local script="$1"
    shift
    docker exec "$CONTAINER" "${KAFKA_BIN}/${script}" --bootstrap-server "$BOOTSTRAP_INTERNAL" "$@"
}

kafka_in() {
    local script="$1"
    shift
    docker exec -i "$CONTAINER" "${KAFKA_BIN}/${script}" --bootstrap-server "$BOOTSTRAP_INTERNAL" "$@"
}

echo "==> conteneur ${CONTAINER}"
status="$(docker inspect -f '{{.State.Status}}' "$CONTAINER")"
if [[ "$status" != "running" ]]; then
    echo "FAIL: ${CONTAINER} status=${status}"
    exit 1
fi

echo "==> broker (metadata)"
kafka_sh kafka-broker-api-versions.sh >/dev/null
echo "    OK ${BOOTSTRAP_INTERNAL}"

echo "==> topics AED"
for topic in "${TOPICS[@]}"; do
    kafka_sh kafka-topics.sh --create --if-not-exists --topic "$topic" --partitions 1 --replication-factor 1 >/dev/null
done
kafka_sh kafka-topics.sh --list

echo "==> produce ${PROBE_TOPIC}"
printf '%s\n' "$PROBE_VALUE" | kafka_in kafka-console-producer.sh --topic "$PROBE_TOPIC"

echo "==> consume 1 message (timeout 12s)"
GOT="$(
    docker exec "$CONTAINER" timeout 12 "${KAFKA_BIN}/kafka-console-consumer.sh" \
        --bootstrap-server "$BOOTSTRAP_INTERNAL" \
        --topic "$PROBE_TOPIC" \
        --from-beginning \
        --max-messages 1 \
        --timeout-ms 10000 2>/dev/null ||
        true
)"

if [[ "$GOT" != *test-kafka.sh* ]]; then
    echo "FAIL: message de probe introuvable sur ${PROBE_TOPIC}"
    echo "$GOT"
    exit 1
fi
echo "    OK $GOT"

echo
echo "Broker OK. Pour tester Laravel (rdkafka) :"
echo "  1. NOTIFICATION_DRIVER=kafka et KAFKA_BROKERS=${BOOTSTRAP_HOST} dans .env"
echo "  2. php artisan tinker --execute=\"app(\\App\\Contracts\\NotificationPublisherInterface::class)->publishEmail(new \\App\\DataTransferObjects\\EmailNotificationData(subject: 'probe', template: \\App\\Enums\\NotificationTemplate::EnrollmentSubmitted, recipients: [['email' => 'probe@example.com']], variables: ['probe' => true]));\""
echo "  3. kafka-ui / consumer sur ${PROBE_TOPIC}"
