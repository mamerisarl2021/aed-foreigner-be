<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

final readonly class EnrollmentDashboardStatsPayload
{
    /**
     * @param  array<string, mixed>  $counts
     * @param  array<string, mixed>  $parType
     * @param  list<array<string, mixed>>  $rejectRateByMotif
     * @param  array<string, mixed>  $evolution
     * @param  array<string, mixed>  $tendances
     */
    public function __construct(
        public array $counts,
        public float $tauxRejetGlobal,
        public ?float $averageHandlingSeconds,
        public ?float $averageHandlingHours,
        public ?float $averageHandlingDays,
        public array $parType,
        public array $rejectRateByMotif,
        public array $evolution,
        public array $tendances,
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
        $seconds = $data['average_handling_seconds'] ?? null;
        $hours = $data['average_handling_hours'] ?? null;
        $days = $data['average_handling_days'] ?? null;
        $motifs = $data['reject_rate_by_motif'] ?? [];

        return new self(
            counts: is_array($data['counts'] ?? null) ? $data['counts'] : [],
            tauxRejetGlobal: is_numeric($data['taux_rejet_global'] ?? null)
                ? (float) $data['taux_rejet_global']
                : 0.0,
            averageHandlingSeconds: is_numeric($seconds) ? (float) $seconds : null,
            averageHandlingHours: is_numeric($hours) ? (float) $hours : null,
            averageHandlingDays: is_numeric($days) ? (float) $days : null,
            parType: is_array($data['par_type'] ?? null) ? $data['par_type'] : [],
            rejectRateByMotif: is_array($motifs) ? array_values($motifs) : [],
            evolution: is_array($data['evolution'] ?? null) ? $data['evolution'] : [],
            tendances: is_array($data['tendances'] ?? null) ? $data['tendances'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'counts' => $this->counts,
            'taux_rejet_global' => $this->tauxRejetGlobal,
            'average_handling_seconds' => $this->averageHandlingSeconds,
            'average_handling_hours' => $this->averageHandlingHours,
            'average_handling_days' => $this->averageHandlingDays,
            'par_type' => $this->parType,
            'reject_rate_by_motif' => $this->rejectRateByMotif,
            'evolution' => $this->evolution,
            'tendances' => $this->tendances,
        ];
    }
}
