<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

final readonly class FinalisationPayload
{
    public function __construct(
        public ?string $demandeId = null,
        public ?string $numeroSuivi = null,
        public ?string $statut = null,
        public ?string $email = null,
        public ?string $npi = null,
        public ?bool $otpVerified = null,
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
        $otpVerified = $data['otp_verified'] ?? null;

        return new self(
            demandeId: self::nullableString($data['demande_id'] ?? null),
            numeroSuivi: self::nullableString($data['numero_suivi'] ?? null),
            statut: self::nullableString($data['statut'] ?? null),
            email: self::nullableString($data['email'] ?? null),
            npi: self::nullableString($data['npi'] ?? null),
            otpVerified: is_bool($otpVerified) ? $otpVerified : null,
        );
    }

    /**
     * @return array<string, string|bool>
     */
    public function toArray(): array
    {
        return array_filter([
            'demande_id' => $this->demandeId,
            'numero_suivi' => $this->numeroSuivi,
            'statut' => $this->statut,
            'email' => $this->email,
            'npi' => $this->npi,
            'otp_verified' => $this->otpVerified,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
