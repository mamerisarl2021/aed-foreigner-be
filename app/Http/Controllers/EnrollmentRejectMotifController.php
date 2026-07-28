<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\ListRejectMotifsRequest;
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
     * Defaults: active_only=true. Optional stage filter
     * (KYC, DOCUMENT, BIOMETRY, COMPANY, REPRESENTATIVE, OTHER).
     */
    public function index(ListRejectMotifsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', EnrollmentRejectMotif::class);

        $query = EnrollmentRejectMotif::query()->orderBy('stage')->orderBy('label_fr');

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        if ($request->filled('stage')) {
            $query->where('stage', strtoupper((string) $request->input('stage')));
        }

        return $this->sendResponse(
            'Liste des motifs de rejet.',
            EnrollmentRejectMotifResource::collection($query->get())
        );
    }
}
