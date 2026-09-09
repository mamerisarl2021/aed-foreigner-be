<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

final readonly class MoraleEnrollmentCorrectionPayload
{
    public function __construct(
        public ?string $demandeId,
        public ?string $numeroSuivi,
        public ?string $statut,
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
        );
    }

    /**
     * @return array{demande_id: string|null, numero_suivi: string|null, statut: string|null}
     */
    public function toArray(): array
    {
        return [
            'demande_id' => $this->demandeId,
            'numero_suivi' => $this->numeroSuivi,
            'statut' => $this->statut,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
