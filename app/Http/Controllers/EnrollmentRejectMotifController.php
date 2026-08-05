<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\DestroyEnrollmentRejectMotifRequest;
use App\Http\Requests\Enrollment\ListRejectMotifsRequest;
use App\Http\Requests\Enrollment\ShowEnrollmentRejectMotifRequest;
use App\Http\Requests\Enrollment\StoreEnrollmentRejectMotifRequest;
use App\Http\Requests\Enrollment\UpdateEnrollmentRejectMotifRequest;
use App\Http\Resources\EnrollmentRejectMotifResource;
use App\Models\EnrollmentRejectMotif;
use App\Services\Enrollment\EnrollmentRejectMotifService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Admin')]
class EnrollmentRejectMotifController extends BaseController
{
    public function __construct(
        private readonly EnrollmentRejectMotifService $motifs,
    ) {}

    /**
     * List enrollment reject motifs
     *
     * Available to agent, responsable de validation, and administrateur plateforme.
     * Returns `{ id, title, description }` — use `id` in reject payloads (`motif[]` / `reasons[]`).
     */
    public function index(ListRejectMotifsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', EnrollmentRejectMotif::class);

        return $this->sendResponse(
            'Liste des motifs de rejet.',
            EnrollmentRejectMotifResource::collection($this->motifs->list())
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

        $actorId = is_string($request->user()?->id) ? $request->user()->id : null;
        $result = $this->motifs->create($request->validated(), $actorId);
        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse(
            $result->message,
            new EnrollmentRejectMotifResource($result->data),
            $result->code
        );
    }

    /**
     * Show enrollment reject motif (admin only)
     */
    public function show(ShowEnrollmentRejectMotifRequest $request): JsonResponse
    {
        $result = $this->motifs->find((string) $request->validated('id'));
        if (! $result->success) {
            return $this->respond($result);
        }

        $this->authorize('view', $result->data);

        return $this->sendResponse(
            $result->message,
            new EnrollmentRejectMotifResource($result->data)
        );
    }

    /**
     * Update enrollment reject motif (admin only)
     *
     * Body: `{ title?, description? }`.
     */
    public function update(UpdateEnrollmentRejectMotifRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $id = (string) $validated['id'];
        unset($validated['id']);

        $existing = $this->motifs->find($id);
        if (! $existing->success) {
            return $this->respond($existing);
        }

        $this->authorize('update', $existing->data);

        $actorId = is_string($request->user()?->id) ? $request->user()->id : null;
        $result = $this->motifs->update($id, $validated, $actorId);
        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse(
            $result->message,
            new EnrollmentRejectMotifResource($result->data)
        );
    }

    /**
     * Delete enrollment reject motif (admin only)
     *
     * Hard delete — the motif is permanently removed.
     */
    public function destroy(DestroyEnrollmentRejectMotifRequest $request): JsonResponse
    {
        $id = (string) $request->validated('id');

        $existing = $this->motifs->find($id);
        if (! $existing->success) {
            return $this->respond($existing);
        }

        $this->authorize('delete', $existing->data);

        $actorId = is_string($request->user()?->id) ? $request->user()->id : null;

        return $this->respond($this->motifs->delete($id, $actorId));
    }
}
