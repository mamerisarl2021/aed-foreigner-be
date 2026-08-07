<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRequest;
use Illuminate\Support\Facades\DB;

class EnrollmentStatsService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $byStatus = EnrollmentRequest::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

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

        $rejectByMotif = EnrollmentRequest::query()
            ->where('status', EnrollmentStatus::Rejetee->value)
            ->whereNotNull('reject_reasons')
            ->get(['reject_reasons'])
            ->flatMap(function (EnrollmentRequest $row) {
                return collect($row->reject_reasons ?? [])->map(fn ($id) => (string) $id);
            })
            ->countBy()
            ->map(fn ($count, $id) => ['motif_id' => $id, 'count' => $count])
            ->values()
            ->all();

        return [
            'counts' => [
                'received' => $received,
                'in_progress' => $inProgress,
                'approved' => $approved,
                'rejected' => $rejected,
                'by_status' => $byStatus,
            ],
            'average_handling_seconds' => $avgHandlingSeconds !== null ? (float) $avgHandlingSeconds : null,
            'average_handling_hours' => $avgHandlingSeconds !== null
                ? round(((float) $avgHandlingSeconds) / 3600, 2)
                : null,
            'reject_rate_by_motif' => $rejectByMotif,
        ];
    }
}
