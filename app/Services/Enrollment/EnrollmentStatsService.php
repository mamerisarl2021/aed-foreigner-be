<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRejectMotif;
use App\Models\EnrollmentRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EnrollmentStatsService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(string $granularite = 'semaine'): array
    {
        [$currentFrom, $currentTo, $previousFrom, $previousTo] = $this->tendanceWindows($granularite);
        $rows = $this->aggregatedStatusCounts($currentFrom, $currentTo, $previousFrom, $previousTo);

        $byStatus = $this->totalsByStatus($rows);
        $summary = $this->summarizeCounts($byStatus);
        $received = $summary['received'];
        $rejected = $summary['rejected'];

        $averages = $this->handlingAverages($currentFrom, $currentTo, $previousFrom, $previousTo);
        $avgHandlingSeconds = $averages['global_seconds'];

        $tauxRejetGlobal = $received > 0 ? round(($rejected / $received) * 100, 1) : 0.0;

        return [
            'counts' => [
                'received' => $received,
                'in_progress' => $summary['in_progress'],
                'approved' => $summary['approved'],
                'rejected' => $rejected,
                'by_status' => $byStatus,
            ],
            'taux_rejet_global' => $tauxRejetGlobal,
            'average_handling_seconds' => $avgHandlingSeconds,
            'average_handling_hours' => $avgHandlingSeconds !== null
                ? round($avgHandlingSeconds / 3600, 2)
                : null,
            'average_handling_days' => $avgHandlingSeconds !== null
                ? round($avgHandlingSeconds / 86400, 1)
                : null,
            'par_type' => $this->parTypeFromRows($rows),
            'reject_rate_by_motif' => $this->rejectRateByMotif($rejected),
            'evolution' => [
                'granularite' => $granularite,
                'points' => $this->evolutionPoints($granularite),
            ],
            'tendances' => $this->tendancesFromRows($rows, $averages),
        ];
    }

    /**
     * @param  Collection<int, object{type: string, status: string, total: int|string, current_count: int|string, previous_count: int|string}>  $rows
     * @return array<string, array<string, array{valeur: int, variation_pct: float|null}>>
     */
    private function parTypeFromRows(Collection $rows): array
    {
        $bloc = [];
        foreach (['PERSONNE_PHYSIQUE', 'PERSONNE_MORALE'] as $type) {
            $typed = $rows->where('type', $type);
            $total = $this->summarizeCounts($this->pluckCount($typed, 'total'));
            $courant = $this->summarizeCounts($this->pluckCount($typed, 'current_count'));
            $precedent = $this->summarizeCounts($this->pluckCount($typed, 'previous_count'));

            $compteurs = [];
            foreach (['received', 'in_progress', 'approved', 'enrolled', 'rejected'] as $cle) {
                $compteurs[$cle] = [
                    'valeur' => $total[$cle],
                    'variation_pct' => $this->variationPct(
                        (float) $courant[$cle],
                        (float) $precedent[$cle],
                    ),
                ];
            }

            $bloc[$type] = $compteurs;
        }

        return $bloc;
    }

    /**
     * @return Collection<int, object{type: string, status: string, total: int|string, current_count: int|string, previous_count: int|string}>
     */
    private function aggregatedStatusCounts(
        Carbon $currentFrom,
        Carbon $currentTo,
        Carbon $previousFrom,
        Carbon $previousTo,
    ): Collection {
        $rows = DB::select(
            <<<'SQL'
            SELECT type, status,
                   COUNT(*)::int AS total,
                   COUNT(*) FILTER (WHERE created_at >= ? AND created_at <= ?)::int AS current_count,
                   COUNT(*) FILTER (WHERE created_at >= ? AND created_at <= ?)::int AS previous_count
            FROM enrollment_requests
            GROUP BY type, status
            SQL,
            [$currentFrom, $currentTo, $previousFrom, $previousTo],
        );

        return collect($rows);
    }

    /**
     * @param  Collection<int, object{type: string, status: string, total: int|string, current_count: int|string, previous_count: int|string}>  $rows
     * @return array<string, int>
     */
    private function totalsByStatus(Collection $rows): array
    {
        $byStatus = [];
        foreach ($rows as $row) {
            $statusValue = (string) $row->status;
            $byStatus[$statusValue] = ($byStatus[$statusValue] ?? 0) + (int) $row->total;
        }

        return $byStatus;
    }

    /**
     * @param  Collection<int, object{type: string, status: string, total: int|string, current_count: int|string, previous_count: int|string}>  $rows
     * @return array<string, int>
     */
    private function pluckCount(Collection $rows, string $attribute): array
    {
        $byStatus = [];
        foreach ($rows as $row) {
            $statusValue = (string) $row->status;
            $byStatus[$statusValue] = (int) $row->{$attribute};
        }

        return $byStatus;
    }

    /**
     * @param  array<string, int>  $byStatus
     * @return array{received: int, in_progress: int, approved: int, enrolled: int, rejected: int}
     */
    private function summarizeCounts(array $byStatus): array
    {
        $received = (int) array_sum($byStatus);
        $openStatuses = [
            ...EnrollmentStatus::open(),
            EnrollmentStatus::AwaitingContactVerification->value,
        ];
        $inProgress = (int) array_sum(array_map(
            fn (string $status) => $byStatus[$status] ?? 0,
            $openStatuses,
        ));
        $approved = (int) ($byStatus[EnrollmentStatus::Approuvee->value] ?? 0);
        $enrolled = (int) ($byStatus[EnrollmentStatus::Enrolee->value] ?? 0);
        $rejected = (int) ($byStatus[EnrollmentStatus::Rejetee->value] ?? 0);

        return [
            'received' => $received,
            'in_progress' => $inProgress,
            'approved' => $approved,
            'enrolled' => $enrolled,
            'rejected' => $rejected,
        ];
    }

    /**
     * @return list<array{id: string, title: ?string, count: int, taux: float}>
     */
    private function rejectRateByMotif(int $rejected): array
    {
        $titles = EnrollmentRejectMotif::query()->pluck('title', 'id');

        $rows = DB::select(
            <<<'SQL'
            SELECT elem AS id, COUNT(*)::int AS total
            FROM enrollment_requests
            CROSS JOIN LATERAL jsonb_array_elements_text(reject_reasons::jsonb) AS elem
            WHERE status = ?
              AND reject_reasons IS NOT NULL
            GROUP BY elem
            SQL,
            [EnrollmentStatus::Rejetee->value],
        );

        return array_values(collect($rows)
            ->map(function (\stdClass $row) use ($titles, $rejected): array {
                $id = (string) $row->id;
                $total = (int) $row->total;

                return [
                    'id' => $id,
                    'title' => is_string($titre = $titles->get($id)) ? $titre : null,
                    'count' => $total,
                    'taux' => $rejected > 0 ? round(($total / $rejected) * 100, 1) : 0.0,
                ];
            })
            ->values()
            ->all());
    }

    /**
     * @return list<array{periode: string, count: int}>
     */
    private function evolutionPoints(string $granularite): array
    {
        [$start, $bucket] = $this->evolutionWindow($granularite);

        $bucketSql = $granularite === 'mois'
            ? "to_char(created_at, 'YYYY-MM')"
            : "to_char(created_at, 'IYYY-\"W\"IW')";

        $counts = EnrollmentRequest::query()
            ->where('created_at', '>=', $start)
            ->selectRaw("{$bucketSql} as bucket, COUNT(*)::int as total")
            ->groupBy(DB::raw($bucketSql))
            ->pluck('total', 'bucket');

        $points = [];
        $cursor = $start->copy();
        for ($i = 0; $i < 12; $i++) {
            $periode = $bucket($cursor);
            $points[] = [
                'periode' => $periode,
                'count' => (int) ($counts[$periode] ?? 0),
            ];
            if ($granularite === 'mois') {
                $cursor->addMonth();
            } else {
                $cursor->addWeek();
            }
        }

        return $points;
    }

    /**
     * @param  Collection<int, object{type: string, status: string, total: int|string, current_count: int|string, previous_count: int|string}>  $rows
     * @param  array{global_seconds: float|null, current_days: float|null, previous_days: float|null}  $averages
     * @return array{received: array{valeur: int, variation_pct: float|null}, in_progress: array{valeur: int, variation_pct: float|null}, approved: array{valeur: int, variation_pct: float|null}, enrolled: array{valeur: int, variation_pct: float|null}, rejected: array{valeur: int, variation_pct: float|null}, taux_rejet_global: array{valeur: float, variation_pct: float|null}, average_handling_days: array{valeur: float|null, variation_days: float|null}}
     */
    private function tendancesFromRows(Collection $rows, array $averages): array
    {
        $current = $this->summarizeCounts($this->pluckCount($rows, 'current_count'));
        $previous = $this->summarizeCounts($this->pluckCount($rows, 'previous_count'));

        $currentTaux = $current['received'] > 0 ? round(($current['rejected'] / $current['received']) * 100, 1) : 0.0;
        $previousTaux = $previous['received'] > 0 ? round(($previous['rejected'] / $previous['received']) * 100, 1) : 0.0;

        return [
            'received' => $this->tendancePoint($current['received'], $previous['received']),
            'in_progress' => $this->tendancePoint($current['in_progress'], $previous['in_progress']),
            'approved' => $this->tendancePoint($current['approved'], $previous['approved']),
            'enrolled' => $this->tendancePoint($current['enrolled'], $previous['enrolled']),
            'rejected' => $this->tendancePoint($current['rejected'], $previous['rejected']),
            'taux_rejet_global' => [
                'valeur' => $currentTaux,
                'variation_pct' => $this->variationPct($currentTaux, $previousTaux),
            ],
            'average_handling_days' => [
                'valeur' => $averages['current_days'],
                'variation_days' => $this->variationDays($averages['current_days'], $averages['previous_days']),
            ],
        ];
    }

    /**
     * @return array{global_seconds: float|null, current_days: float|null, previous_days: float|null}
     */
    private function handlingAverages(
        Carbon $currentFrom,
        Carbon $currentTo,
        Carbon $previousFrom,
        Carbon $previousTo,
    ): array {
        $closed = [
            EnrollmentStatus::Approuvee->value,
            EnrollmentStatus::Enrolee->value,
            EnrollmentStatus::Rejetee->value,
        ];

        $row = DB::selectOne(
            <<<'SQL'
            SELECT
                AVG(EXTRACT(EPOCH FROM (updated_at - created_at))) AS global_seconds,
                AVG(EXTRACT(EPOCH FROM (updated_at - created_at))) FILTER (
                    WHERE created_at >= ? AND created_at <= ?
                ) AS current_seconds,
                AVG(EXTRACT(EPOCH FROM (updated_at - created_at))) FILTER (
                    WHERE created_at >= ? AND created_at <= ?
                ) AS previous_seconds
            FROM enrollment_requests
            WHERE status IN (?, ?, ?)
            SQL,
            [
                $currentFrom,
                $currentTo,
                $previousFrom,
                $previousTo,
                $closed[0],
                $closed[1],
                $closed[2],
            ],
        );

        if (! is_object($row)) {
            return [
                'global_seconds' => null,
                'current_days' => null,
                'previous_days' => null,
            ];
        }

        $global = isset($row->global_seconds) ? (float) $row->global_seconds : null;
        $current = isset($row->current_seconds) ? (float) $row->current_seconds : null;
        $previous = isset($row->previous_seconds) ? (float) $row->previous_seconds : null;

        return [
            'global_seconds' => $global,
            'current_days' => $current !== null ? round($current / 86400, 1) : null,
            'previous_days' => $previous !== null ? round($previous / 86400, 1) : null,
        ];
    }

    private function variationDays(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        return round($current - $previous, 1);
    }

    /**
     * @return array{0: Carbon, 1: callable(Carbon): string}
     */
    private function evolutionWindow(string $granularite): array
    {
        if ($granularite === 'mois') {
            $start = now()->copy()->startOfMonth()->subMonths(11);

            return [$start, fn (Carbon $date): string => $date->format('Y-m')];
        }

        $start = now()->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(11);

        return [$start, fn (Carbon $date): string => $date->copy()->startOfWeek(Carbon::MONDAY)->format('o-\WW')];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon}
     */
    private function tendanceWindows(string $granularite): array
    {
        if ($granularite === 'mois') {
            $currentFrom = now()->copy()->startOfMonth();
            $currentTo = now()->copy()->endOfMonth();
            $previousFrom = $currentFrom->copy()->subMonth();
            $previousTo = $currentFrom->copy()->subSecond();

            return [$currentFrom, $currentTo, $previousFrom, $previousTo];
        }

        $currentFrom = now()->copy()->startOfWeek(Carbon::MONDAY);
        $currentTo = now()->copy()->endOfWeek(Carbon::SUNDAY);
        $previousFrom = $currentFrom->copy()->subWeek();
        $previousTo = $currentFrom->copy()->subSecond();

        return [$currentFrom, $currentTo, $previousFrom, $previousTo];
    }

    /**
     * @return array{valeur: int, variation_pct: float|null}
     */
    private function tendancePoint(int $current, int $previous): array
    {
        return [
            'valeur' => $current,
            'variation_pct' => $this->variationPct((float) $current, (float) $previous),
        ];
    }

    private function variationPct(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
