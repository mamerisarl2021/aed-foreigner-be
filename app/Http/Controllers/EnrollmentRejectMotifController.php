<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\ListRejectMotifsRequest;
use App\Http\Requests\Enrollment\StoreEnrollmentRejectMotifRequest;
use App\Http\Requests\Enrollment\UpdateEnrollmentRejectMotifRequest;
use App\Http\Resources\EnrollmentRejectMotifResource;
use App\Models\EnrollmentRejectMotif;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Admin')]
class EnrollmentRejectMotifController extends BaseController
{
    /**
     * List enrollment reject motifs
     *
     * Available to agent, responsable de validation, and administrateur plateforme.
     * Returns `{ id, title, description }` — use `id` in reject payloads (`motif[]` / `reasons[]`).
     */
    public function index(ListRejectMotifsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', EnrollmentRejectMotif::class);

        $motifs = EnrollmentRejectMotif::query()
            ->orderBy('title')
            ->get();

        return $this->sendResponse(
            'Liste des motifs de rejet.',
            EnrollmentRejectMotifResource::collection($motifs)
        );
    }

    /**
     * Create enrollment reject motif (admin only)
     *
     * Body: `{ title, description }`.
     */
    public function store(StoreEnrollmentRejectMotifRequest $request): JsonResponse
    {
        $this->authorize('create', EnrollmentRejectMotif::class);

        $motif = EnrollmentRejectMotif::query()->create($request->validated());

        return $this->sendResponse(
            'Motif de rejet créé.',
            new EnrollmentRejectMotifResource($motif),
            201
        );
    }

    /**
     * Show enrollment reject motif (admin only)
     */
    public function show(string $id): JsonResponse
    {
        $motif = EnrollmentRejectMotif::query()->find($id);
        if (! $motif) {
            return $this->sendError('Motif de rejet introuvable.', null, 404);
        }

        $this->authorize('view', $motif);

        return $this->sendResponse(
            'Motif de rejet.',
            new EnrollmentRejectMotifResource($motif)
        );
    }

    /**
     * Update enrollment reject motif (admin only)
     *
     * Body: `{ title?, description? }`.
     */
    public function update(UpdateEnrollmentRejectMotifRequest $request, string $id): JsonResponse
    {
        $motif = EnrollmentRejectMotif::query()->find($id);
        if (! $motif) {
            return $this->sendError('Motif de rejet introuvable.', null, 404);
        }

        $this->authorize('update', $motif);

        $motif->fill($request->validated());
        $motif->save();

        return $this->sendResponse(
            'Motif de rejet mis à jour.',
            new EnrollmentRejectMotifResource($motif)
        );
    }

    /**
     * Delete enrollment reject motif (admin only)
     *
     * Hard delete — the motif is permanently removed.
     */
    public function destroy(string $id): JsonResponse
    {
        $motif = EnrollmentRejectMotif::query()->find($id);
        if (! $motif) {
            return $this->sendError('Motif de rejet introuvable.', null, 404);
        }

        $this->authorize('delete', $motif);

        $motif->delete();

        return $this->sendResponse('Motif de rejet supprimé.', []);
    }
}
