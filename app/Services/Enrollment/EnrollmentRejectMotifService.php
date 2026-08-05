<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\EnrollmentRejectMotif;
use App\Services\ServiceResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class EnrollmentRejectMotifService
{
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
    public function create(array $data): ServiceResult
    {
        try {
            $motif = EnrollmentRejectMotif::query()->create($data);

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
    public function update(string $id, array $data): ServiceResult
    {
        $motif = EnrollmentRejectMotif::query()->find($id);
        if (! $motif) {
            return ServiceResult::fail('Motif de rejet introuvable.', null, 404);
        }

        try {
            $motif->fill($data);
            $motif->save();

            return ServiceResult::ok('Motif de rejet mis à jour.', $motif);
        } catch (Throwable $e) {
            Log::error('Failed to update reject motif', ['id' => $id, 'error' => $e->getMessage()]);

            return ServiceResult::fail('Impossible de mettre à jour le motif de rejet.', null, 500);
        }
    }

    public function delete(string $id): ServiceResult
    {
        $motif = EnrollmentRejectMotif::query()->find($id);
        if (! $motif) {
            return ServiceResult::fail('Motif de rejet introuvable.', null, 404);
        }

        try {
            $motif->delete();

            return ServiceResult::ok('Motif de rejet supprimé.', []);
        } catch (Throwable $e) {
            Log::error('Failed to delete reject motif', ['id' => $id, 'error' => $e->getMessage()]);

            return ServiceResult::fail('Impossible de supprimer le motif de rejet.', null, 500);
        }
    }
}
