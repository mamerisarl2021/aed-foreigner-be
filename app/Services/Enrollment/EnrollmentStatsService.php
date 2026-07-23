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
        $inProgress = (int) (
            ($byStatus[EnrollmentStatus::EnAttente->value] ?? 0)
            + ($byStatus[EnrollmentStatus::ValidationAgent->value] ?? 0)
            + ($byStatus[EnrollmentStatus::RejetAgent->value] ?? 0)
            + ($byStatus[EnrollmentStatus::AwaitingContactVerification->value] ?? 0)
        );
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
                return collect($row->reject_reasons ?? [])->map(fn ($code) => (string) $code);
            })
            ->countBy()
            ->map(fn ($count, $code) => ['code' => $code, 'count' => $count])
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
