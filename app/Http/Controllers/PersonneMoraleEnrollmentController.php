<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\CorrectMoraleEnrollmentRequest;
use App\Http\Requests\Enrollment\ListMoraleEnrollmentsRequest;
use App\Http\Requests\Enrollment\SendMoralePhoneOtpRequest;
use App\Http\Requests\Enrollment\ShowMoraleEnrollmentRequest;
use App\Http\Requests\Enrollment\SubmitMoraleEnrollmentRequest;
use App\Http\Requests\Enrollment\VerifyMoraleEmailRequest;
use App\Http\Requests\Enrollment\VerifyMoralePhoneOtpRequest;
use App\Http\Resources\MoraleEnrollmentCorrectionResource;
use App\Http\Resources\MoraleEnrollmentOwnerListResource;
use App\Http\Resources\MoraleEnrollmentOwnerResource;
use App\Models\EnrollmentRequest;
use App\Services\Enrollment\PersonneMoraleEnrollmentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;

#[Group('Enrollment - Morale')]
class PersonneMoraleEnrollmentController extends BaseController
{
    public function __construct(
        private readonly PersonneMoraleEnrollmentService $moraleEnrollment,
    ) {}

    /**
     * List the authenticated client's personne morale enrollments
     *
     * Owner only. Includes AWAITING_CONTACT_VERIFICATION. Defaults: per_page=15 (max 100).
     */
    public function index(ListMoraleEnrollmentsRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        $this->authorize('listOwnMorale', EnrollmentRequest::class);

        $perPage = (int) ($request->validated('per_page') ?? 15);
        $paginator = $this->moraleEnrollment->listOwn($user, $perPage);
        $paginator->getCollection()->transform(
            fn ($item) => new MoraleEnrollmentOwnerListResource($item)
        );

        return $this->sendResponse('Liste des entreprises.', $paginator);
    }

    /**
     * Submit personne morale enrollment
     *
     * Requires authenticated client with finalized physique enrollment and a prior
     * POST /kyc/verify session (OTP skipped for ACTIVE clients).
     * Optional capture_le (ISO-8601) is stored for analyse_kyc.selfie.capture_le.
     * Initial statut AWAITING_CONTACT_VERIFICATION. Returns numero_suivi (PK…).
     * phonenumber: optional leading +, then 8–20 digits; spaces/dashes/parentheses allowed and stripped.
     */
    public function submit(SubmitMoraleEnrollmentRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        $this->authorize('submitMorale', EnrollmentRequest::class);

        return $this->respond($this->moraleEnrollment->submit($user, $request));
    }

    /**
     * Morale enrollment detail (owner only)
     *
     * Company fields, contact verification flags, and pièces jointes (RCCM, statuts, procuration).
     */
    #[PathParameter('id', description: 'Personne morale enrollment request UUID.', type: 'string', format: 'uuid')]
    public function show(ShowMoraleEnrollmentRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('viewOwnMorale', $enrollment);

        $result = $this->moraleEnrollment->show($user, $id);
        if ($result->success) {
            return $this->sendResponse($result->message, new MoraleEnrollmentOwnerResource($result->data));
        }

        return $this->sendError($result->message, $result->data ?? [], $result->code);
    }

    /**
     * Correct a rejected personne morale enrollment (owner, A_CORRIGER only)
     *
     * Updates company fields and attachments (not official contacts, not KYC selfie).
     * On success the request returns to EN_ATTENTE_AGENT.
     */
    #[PathParameter('id', description: 'Personne morale enrollment request UUID.', type: 'string', format: 'uuid')]
    public function correct(CorrectMoraleEnrollmentRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('correctMorale', $enrollment);

        $result = $this->moraleEnrollment->correct($user, $enrollment, $request);
        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse($result->message, new MoraleEnrollmentCorrectionResource($result->data));
    }

    /**
     * Verify company email (public token link)
     *
     * May promote the request to EN_ATTENTE_AGENT if the phone is also verified.
     */
    #[PathParameter('id', description: 'Personne morale enrollment request UUID.', type: 'string', format: 'uuid')]
    public function verifyEmail(VerifyMoraleEmailRequest $request): JsonResponse
    {
        return $this->respond($this->moraleEnrollment->verifyEmail(
            (string) $request->validated('id'),
            $request->input('token'),
        ));
    }

    /**
     * Send SMS OTP for company phone verification
     */
    #[PathParameter('id', description: 'Personne morale enrollment request UUID.', type: 'string', format: 'uuid')]
    public function sendPhoneOtp(SendMoralePhoneOtpRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('viewOwnMorale', $enrollment);

        return $this->respond($this->moraleEnrollment->sendPhoneOtp($user, $id));
    }

    /**
     * Verify company phone OTP
     *
     * May promote the request to EN_ATTENTE_AGENT once both channels are verified.
     * Confirmation email is sent to the demandeur only after both verifications.
     */
    #[PathParameter('id', description: 'Personne morale enrollment request UUID.', type: 'string', format: 'uuid')]
    public function verifyPhoneOtp(VerifyMoralePhoneOtpRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        $id = (string) $request->validated('id');
        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('viewOwnMorale', $enrollment);

        return $this->respond($this->moraleEnrollment->verifyPhoneOtp($user, $id, $request->input('otp')));
    }
}
