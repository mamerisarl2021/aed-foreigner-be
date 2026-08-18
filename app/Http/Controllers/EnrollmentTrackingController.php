<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\TrackEnrollmentRequest;
use App\Http\Resources\EnrollmentTrackingResource;
use App\Services\Enrollment\EnrollmentTrackingService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Enrollment - Suivi')]
final class EnrollmentTrackingController extends BaseController
{
    public function __construct(
        private readonly EnrollmentTrackingService $tracking,
    ) {}

    /**
     * Track an enrollment by numero_suivi and email
     *
     * Guest POST. Proof of ownership: numero_suivi (from submit / confirmation email)
     * plus the enrollment email (physique) or official/demandeur email (morale).
     * Returns demandeur-facing statut_libelle only — no KYC analysis, avis agent, or documents.
     * Exposes email_verifie / telephone_verifie for physique and morale.
     * Wrong or unknown pair → 404 Demande introuvable.
     */
    public function show(TrackEnrollmentRequest $request): JsonResponse
    {
        $result = $this->tracking->show(
            (string) $request->validated('numero_suivi'),
            (string) $request->validated('email'),
        );

        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, new EnrollmentTrackingResource($result->data));
    }
}
