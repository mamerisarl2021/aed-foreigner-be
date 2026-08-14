<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRejectMotif;
use App\Models\EnrollmentRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EnrollmentStatsService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(string $granularite = 'semaine'): array
    {
        $byStatus = $this->countsByStatus();
        $summary = $this->summarizeCounts($byStatus);
        $received = $summary['received'];
        $rejected = $summary['rejected'];

        $closed = EnrollmentRequest::query()
            ->whereIn('status', [
                EnrollmentStatus::Approuvee->value,
                EnrollmentStatus::Enrolee->value,
                EnrollmentStatus::Rejetee->value,
            ])
            ->get(['created_at', 'updated_at']);

        $avgHandlingSeconds = $closed->isEmpty()
            ? null
            : $closed->avg(fn (EnrollmentRequest $row) => $row->created_at->diffInSeconds($row->updated_at));

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
            'average_handling_seconds' => $avgHandlingSeconds !== null ? (float) $avgHandlingSeconds : null,
            'average_handling_hours' => $avgHandlingSeconds !== null
                ? round(((float) $avgHandlingSeconds) / 3600, 2)
                : null,
            'average_handling_days' => $avgHandlingSeconds !== null
                ? round(((float) $avgHandlingSeconds) / 86400, 1)
                : null,
            'reject_rate_by_motif' => $this->rejectRateByMotif($rejected),
            'evolution' => [
                'granularite' => $granularite,
                'points' => $this->evolutionPoints($granularite),
            ],
            'tendances' => $this->tendances($granularite),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function countsByStatus(?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = EnrollmentRequest::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status');

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        $raw = $query->pluck('total', 'status')->all();
        $byStatus = [];
        foreach ($raw as $status => $total) {
            $byStatus[(string) $status] = (int) $total;
        }

        return $byStatus;
    }

    /**
     * @param  array<string, int>  $byStatus
     * @return array{received: int, in_progress: int, approved: int, rejected: int}
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
        $approved = (int) (($byStatus[EnrollmentStatus::Approuvee->value] ?? 0) + ($byStatus[EnrollmentStatus::Enrolee->value] ?? 0));
        $rejected = (int) ($byStatus[EnrollmentStatus::Rejetee->value] ?? 0);

        return [
            'received' => $received,
            'in_progress' => $inProgress,
            'approved' => $approved,
            'rejected' => $rejected,
        ];
    }

    /**
     * @return list<array{id: string, title: ?string, count: int, taux: float}>
     */
    private function rejectRateByMotif(int $rejected): array
    {
        $titles = EnrollmentRejectMotif::query()->pluck('title', 'id');

        return EnrollmentRequest::query()
            ->where('status', EnrollmentStatus::Rejetee->value)
            ->whereNotNull('reject_reasons')
            ->get(['reject_reasons'])
            ->flatMap(function (EnrollmentRequest $row) {
                return collect($row->reject_reasons ?? [])->map(fn ($id) => (string) $id);
            })
            ->countBy()
            ->map(function ($count, $id) use ($titles, $rejected) {
                $total = (int) $count;

                return [
                    'id' => (string) $id,
                    'title' => $titles->get($id),
                    'count' => $total,
                    'taux' => $rejected > 0 ? round(($total / $rejected) * 100, 1) : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{periode: string, count: int}>
     */
    private function evolutionPoints(string $granularite): array
    {
        [$start, $bucket] = $this->evolutionWindow($granularite);

        $counts = EnrollmentRequest::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->countBy(fn (EnrollmentRequest $row) => $bucket($row->created_at));

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
     * @return array{received: array{valeur: int, variation_pct: float|null}, in_progress: array{valeur: int, variation_pct: float|null}, approved: array{valeur: int, variation_pct: float|null}, rejected: array{valeur: int, variation_pct: float|null}, taux_rejet_global: array{valeur: float, variation_pct: float|null}}
     */
    private function tendances(string $granularite): array
    {
        [$currentFrom, $currentTo, $previousFrom, $previousTo] = $this->tendanceWindows($granularite);

        $current = $this->summarizeCounts($this->countsByStatus($currentFrom, $currentTo));
        $previous = $this->summarizeCounts($this->countsByStatus($previousFrom, $previousTo));

        $currentTaux = $current['received'] > 0 ? round(($current['rejected'] / $current['received']) * 100, 1) : 0.0;
        $previousTaux = $previous['received'] > 0 ? round(($previous['rejected'] / $previous['received']) * 100, 1) : 0.0;

        return [
            'received' => $this->tendancePoint($current['received'], $previous['received']),
            'in_progress' => $this->tendancePoint($current['in_progress'], $previous['in_progress']),
            'approved' => $this->tendancePoint($current['approved'], $previous['approved']),
            'rejected' => $this->tendancePoint($current['rejected'], $previous['rejected']),
            'taux_rejet_global' => [
                'valeur' => $currentTaux,
                'variation_pct' => $this->variationPct($currentTaux, $previousTaux),
            ],
        ];
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
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
