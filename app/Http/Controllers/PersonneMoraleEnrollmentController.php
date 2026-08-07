<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\SendMoralePhoneOtpRequest;
use App\Http\Requests\Enrollment\ShowMoraleEnrollmentRequest;
use App\Http\Requests\Enrollment\SubmitMoraleEnrollmentRequest;
use App\Http\Requests\Enrollment\VerifyMoraleEmailRequest;
use App\Http\Requests\Enrollment\VerifyMoralePhoneOtpRequest;
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
     * Submit personne morale enrollment
     *
     * Requires authenticated client with finalized physique enrollment.
     * Initial statut AWAITING_CONTACT_VERIFICATION.
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
