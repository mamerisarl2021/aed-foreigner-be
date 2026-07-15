<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\EnrollmentRejectMotifResource;
use App\Models\EnrollmentRejectMotif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentRejectMotifController extends BaseController
{
    public function index(Request $request): JsonResponse
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
