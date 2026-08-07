<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\ActivityLogAction;
use App\Models\EnrollmentRejectMotif;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class EnrollmentRejectMotifService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * @return Collection<int, EnrollmentRejectMotif>
     */
    public function list(): Collection
    {
        return EnrollmentRejectMotif::query()
            ->orderBy('title')
            ->get();
    }

    /**
     * @param  array{title: string, description: string}  $data
     */
    public function create(array $data, ?string $actorUserId = null): ServiceResult
    {
        try {
            $motif = EnrollmentRejectMotif::query()->create($data);

            $this->activityLog->record(
                ActivityLogAction::MotifCree,
                sprintf('Motif de rejet créé : %s.', $motif->title),
                $actorUserId,
                null,
                ['motif_id' => $motif->id],
            );

            return ServiceResult::ok('Motif de rejet créé.', $motif, 201);
        } catch (Throwable $e) {
            Log::error('Failed to create reject motif', ['error' => $e->getMessage()]);

            return ServiceResult::fail('Impossible de créer le motif de rejet.', null, 500);
        }
    }

    public function find(string $id): ServiceResult
    {
        $motif = EnrollmentRejectMotif::query()->find($id);
        if (! $motif) {
            return ServiceResult::fail('Motif de rejet introuvable.', null, 404);
        }

        return ServiceResult::ok('Motif de rejet.', $motif);
    }

    /**
     * @param  array{title?: string, description?: string}  $data
     */
    public function update(string $id, array $data, ?string $actorUserId = null): ServiceResult
    {
        $motif = EnrollmentRejectMotif::query()->find($id);
        if (! $motif) {
            return ServiceResult::fail('Motif de rejet introuvable.', null, 404);
        }

        try {
            $motif->fill($data);
            $motif->save();

            $this->activityLog->record(
                ActivityLogAction::MotifModifie,
                sprintf('Motif de rejet modifié : %s.', $motif->title),
                $actorUserId,
                null,
                ['motif_id' => $motif->id],
            );

            return ServiceResult::ok('Motif de rejet mis à jour.', $motif);
        } catch (Throwable $e) {
            Log::error('Failed to update reject motif', ['id' => $id, 'error' => $e->getMessage()]);

            return ServiceResult::fail('Impossible de mettre à jour le motif de rejet.', null, 500);
        }
    }

    public function delete(string $id, ?string $actorUserId = null): ServiceResult
    {
        $motif = EnrollmentRejectMotif::query()->find($id);
        if (! $motif) {
            return ServiceResult::fail('Motif de rejet introuvable.', null, 404);
        }

        try {
            $title = $motif->title;
            $motif->delete();

            $this->activityLog->record(
                ActivityLogAction::MotifSupprime,
                sprintf('Motif de rejet supprimé : %s.', $title),
                $actorUserId,
                null,
                ['motif_id' => $id],
            );

            return ServiceResult::ok('Motif de rejet supprimé.', []);
        } catch (Throwable $e) {
            Log::error('Failed to delete reject motif', ['id' => $id, 'error' => $e->getMessage()]);

            return ServiceResult::fail('Impossible de supprimer le motif de rejet.', null, 500);
        }
    }
}
