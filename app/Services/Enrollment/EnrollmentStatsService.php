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

        $avgHandlingSeconds = EnrollmentRequest::query()
            ->whereIn('status', [
                EnrollmentStatus::Approuvee->value,
                EnrollmentStatus::Enrolee->value,
                EnrollmentStatus::Rejetee->value,
            ])
            ->avg(DB::raw('TIMESTAMPDIFF(SECOND, created_at, updated_at)'));

        $avgHandlingSeconds = $avgHandlingSeconds !== null ? (float) $avgHandlingSeconds : null;

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
            // Les cartes du pilotage lisent ici : chaque type porte ses propres
            // compteurs et sa propre variation, le global ne les résume plus.
            'par_type' => $this->parType($granularite),
            'reject_rate_by_motif' => $this->rejectRateByMotif($rejected),
            'evolution' => [
                'granularite' => $granularite,
                'points' => $this->evolutionPoints($granularite),
            ],
            'tendances' => $this->tendances($granularite),
        ];
    }

    /**
     * Compteurs et variations de chaque type de demande.
     *
     * La valeur est le cumul depuis toujours — ce que la carte affiche en
     * grand — et la variation compare la période courante à la précédente,
     * comme les tendances globales. Deux lectures différentes du même
     * compteur, et c'est voulu : le manager veut le volume total et le sens
     * dans lequel il bouge.
     *
     * @return array<string, array<string, array{valeur: int, variation_pct: float|null}>>
     */
    private function parType(string $granularite): array
    {
        [$currentFrom, $currentTo, $previousFrom, $previousTo] = $this->tendanceWindows($granularite);

        $bloc = [];
        foreach (['PERSONNE_PHYSIQUE', 'PERSONNE_MORALE'] as $type) {
            $total = $this->summarizeCounts($this->countsByStatus(null, null, $type));
            $courant = $this->summarizeCounts($this->countsByStatus($currentFrom, $currentTo, $type));
            $precedent = $this->summarizeCounts($this->countsByStatus($previousFrom, $previousTo, $type));

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
     * @return array<string, int>
     */
    private function countsByStatus(?Carbon $from = null, ?Carbon $to = null, ?string $type = null): array
    {
        $query = EnrollmentRequest::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status');

        if ($type !== null) {
            $query->where('type', $type);
        }

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
     * Résumé d'un jeu de compteurs.
     *
     * `approved` et `enrolled` sont distincts : une décision favorable n'est pas
     * un enrôlement — l'identité, ou l'entreprise, ne naît qu'au terme du
     * parcours. Les additionner, comme le faisait ce résumé, empêchait le
     * pilotage de dire combien de dossiers approuvés restent à finaliser.
     *
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
            SELECT motifs.motif_id AS id, COUNT(*) AS total
            FROM enrollment_requests,
            JSON_TABLE(reject_reasons, '$[*]' COLUMNS(motif_id VARCHAR(64) PATH '$')) AS motifs
            WHERE status = ?
              AND reject_reasons IS NOT NULL
            GROUP BY motifs.motif_id
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
            ? "DATE_FORMAT(created_at, '%Y-%m')"
            : "DATE_FORMAT(created_at, '%x-W%v')";

        $counts = EnrollmentRequest::query()
            ->where('created_at', '>=', $start)
            ->selectRaw("{$bucketSql} as bucket, COUNT(*) as total")
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
     * @return array{received: array{valeur: int, variation_pct: float|null}, in_progress: array{valeur: int, variation_pct: float|null}, approved: array{valeur: int, variation_pct: float|null}, enrolled: array{valeur: int, variation_pct: float|null}, rejected: array{valeur: int, variation_pct: float|null}, taux_rejet_global: array{valeur: float, variation_pct: float|null}, average_handling_days: array{valeur: float|null, variation_days: float|null}}
     */
    private function tendances(string $granularite): array
    {
        [$currentFrom, $currentTo, $previousFrom, $previousTo] = $this->tendanceWindows($granularite);

        $current = $this->summarizeCounts($this->countsByStatus($currentFrom, $currentTo));
        $previous = $this->summarizeCounts($this->countsByStatus($previousFrom, $previousTo));

        $currentTaux = $current['received'] > 0 ? round(($current['rejected'] / $current['received']) * 100, 1) : 0.0;
        $previousTaux = $previous['received'] > 0 ? round(($previous['rejected'] / $previous['received']) * 100, 1) : 0.0;

        $currentAvgDays = $this->averageHandlingDays($currentFrom, $currentTo);
        $previousAvgDays = $this->averageHandlingDays($previousFrom, $previousTo);

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
                'valeur' => $currentAvgDays,
                'variation_days' => $this->variationDays($currentAvgDays, $previousAvgDays),
            ],
        ];
    }

    private function averageHandlingDays(?Carbon $from = null, ?Carbon $to = null): ?float
    {
        $query = EnrollmentRequest::query()
            ->whereIn('status', [
                EnrollmentStatus::Approuvee->value,
                EnrollmentStatus::Enrolee->value,
                EnrollmentStatus::Rejetee->value,
            ]);

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        $avgHandlingSeconds = $query->avg(DB::raw('TIMESTAMPDIFF(SECOND, created_at, updated_at)'));

        if ($avgHandlingSeconds === null) {
            return null;
        }

        return round(((float) $avgHandlingSeconds) / 86400, 1);
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
