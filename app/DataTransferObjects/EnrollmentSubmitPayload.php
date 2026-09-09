<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

final readonly class EnrollmentSubmitPayload
{
    public function __construct(
        public ?string $demandeId = null,
        public ?string $numeroSuivi = null,
        public ?string $statut = null,
        public ?string $status = null,
        public ?string $enrollmentRequestId = null,
        public ?bool $emailVerifie = null,
        public ?bool $telephoneVerifie = null,
        public ?bool $emailVerified = null,
        public ?bool $phoneVerified = null,
        public ?string $verificationDeadlineAt = null,
        public ?string $phonenumber = null,
        public ?string $channel = null,
        public ?string $avisAgent = null,
    ) {}

    public static function from(mixed $resource): self
    {
        if ($resource instanceof self) {
            return $resource;
        }

        return self::fromArray(is_array($resource) ? $resource : []);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            demandeId: self::nullableString($data['demande_id'] ?? null),
            numeroSuivi: self::nullableString($data['numero_suivi'] ?? null),
            statut: self::nullableString($data['statut'] ?? null),
            status: self::nullableString($data['status'] ?? null),
            enrollmentRequestId: self::nullableString($data['enrollment_request_id'] ?? null),
            emailVerifie: self::nullableBool($data['email_verifie'] ?? null),
            telephoneVerifie: self::nullableBool($data['telephone_verifie'] ?? null),
            emailVerified: self::nullableBool($data['email_verified'] ?? null),
            phoneVerified: self::nullableBool($data['phone_verified'] ?? null),
            verificationDeadlineAt: self::nullableString($data['verification_deadline_at'] ?? null),
            phonenumber: self::nullableString($data['phonenumber'] ?? null),
            channel: self::nullableString($data['channel'] ?? null),
            avisAgent: self::nullableString($data['avis_agent'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'demande_id' => $this->demandeId,
            'numero_suivi' => $this->numeroSuivi,
            'statut' => $this->statut,
            'status' => $this->status,
            'enrollment_request_id' => $this->enrollmentRequestId,
            'email_verifie' => $this->emailVerifie,
            'telephone_verifie' => $this->telephoneVerifie,
            'email_verified' => $this->emailVerified,
            'phone_verified' => $this->phoneVerified,
            'verification_deadline_at' => $this->verificationDeadlineAt,
            'phonenumber' => $this->phonenumber,
            'channel' => $this->channel,
            'avis_agent' => $this->avisAgent,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function nullableBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
