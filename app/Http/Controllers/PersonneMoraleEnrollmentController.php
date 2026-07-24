<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\SubmitMoraleEnrollmentRequest;
use App\Http\Requests\Enrollment\VerifyMoraleEmailRequest;
use App\Http\Requests\Enrollment\VerifyMoralePhoneOtpRequest;
use App\Http\Resources\MoraleEnrollmentOwnerResource;
use App\Models\EnrollmentRequest;
use App\Services\Enrollment\PersonneMoraleEnrollmentService;
use Illuminate\Http\JsonResponse;
use OpenApi\Annotations as OA;

class PersonneMoraleEnrollmentController extends BaseController
{
    public function __construct(
        private readonly PersonneMoraleEnrollmentService $moraleEnrollment,
    ) {}

    /**
     * @OA\Post(
     *      path="/api/v1/enrolements/morales",
     *      operationId="enrollmentMoraleSubmit",
     *      tags={"Enrollment - Morale"},
     *      summary="Submit personne morale enrollment",
     *      description="Requires authenticated client with finalized physique enrollment. Initial statut AWAITING_CONTACT_VERIFICATION.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *
     *              @OA\Schema(
     *                  required={"email", "phonenumber", "legal_name", "country_of_incorporation", "registration_number", "headquarters_address", "activity_sector", "legal_representative_name", "legal_representative_first_name", "is_legal_representative", "trade_register_extract"},
     *
     *                  @OA\Property(property="email", type="string", format="email"),
     *                  @OA\Property(property="phonenumber", type="string"),
     *                  @OA\Property(property="legal_name", type="string"),
     *                  @OA\Property(property="legal_form", type="string"),
     *                  @OA\Property(property="country_of_incorporation", type="string"),
     *                  @OA\Property(property="registration_number", type="string"),
     *                  @OA\Property(property="incorporation_date", type="string", format="date"),
     *                  @OA\Property(property="headquarters_address", type="string"),
     *                  @OA\Property(property="activity_sector", type="string"),
     *                  @OA\Property(property="legal_representative_name", type="string"),
     *                  @OA\Property(property="legal_representative_first_name", type="string"),
     *                  @OA\Property(property="is_legal_representative", type="boolean"),
     *                  @OA\Property(property="trade_register_extract", type="string", format="binary"),
     *                  @OA\Property(property="statutes", type="string", format="binary"),
     *                  @OA\Property(property="procuration", type="string", format="binary")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Demande enregistrée"),
     *      @OA\Response(response=403, description="Prerequisites not met"),
     *      @OA\Response(response=409, description="Duplicate open request or company")
     * )
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
     * @OA\Get(
     *      path="/api/v1/enrolements/morales/{id}",
     *      operationId="enrollmentMoraleShow",
     *      tags={"Enrollment - Morale"},
     *      summary="Morale enrollment detail (owner only)",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\Response(response=200, description="Detail demande morale"),
     *      @OA\Response(response=403, description="Not owner")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $user = request()->user();
        if (! $user) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('viewOwnMorale', $enrollment);

        $result = $this->moraleEnrollment->show($user, $id);
        if ($result->success) {
            return $this->sendResponse($result->message, new MoraleEnrollmentOwnerResource($result->data));
        }

        return $this->sendError($result->message, $result->data ?? [], $result->code);
    }

    /**
     * @OA\Post(
     *      path="/api/v1/enrolements/morales/{id}/verify-email",
     *      operationId="enrollmentMoraleVerifyEmail",
     *      tags={"Enrollment - Morale"},
     *      summary="Verify company email (public token link)",
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"token"},
     *
     *              @OA\Property(property="token", type="string")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Email vérifié; may promote to EN_ATTENTE if phone also verified")
     * )
     */
    public function verifyEmail(VerifyMoraleEmailRequest $request, int $id): JsonResponse
    {
        return $this->respond($this->moraleEnrollment->verifyEmail($id, $request->input('token')));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/enrolements/morales/{id}/send-phone-otp",
     *      operationId="enrollmentMoraleSendPhoneOtp",
     *      tags={"Enrollment - Morale"},
     *      summary="Send SMS OTP for company phone verification",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\Response(response=200, description="OTP SMS envoyé")
     * )
     */
    public function sendPhoneOtp(int $id): JsonResponse
    {
        $user = request()->user();
        if (! $user) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        $enrollment = EnrollmentRequest::findOrFail($id);
        $this->authorize('viewOwnMorale', $enrollment);

        return $this->respond($this->moraleEnrollment->sendPhoneOtp($user, $id));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/enrolements/morales/{id}/verify-phone-otp",
     *      operationId="enrollmentMoraleVerifyPhoneOtp",
     *      tags={"Enrollment - Morale"},
     *      summary="Verify company phone OTP",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"otp"},
     *
     *              @OA\Property(property="otp", type="string", example="123456")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Téléphone vérifié; may promote to EN_ATTENTE")
     * )
     */
    public function verifyPhoneOtp(VerifyMoralePhoneOtpRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        return $this->respond($this->moraleEnrollment->verifyPhoneOtp($user, $id, $request->input('otp')));
    }
}
