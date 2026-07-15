<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\SubmitEnrollmentRequest;
use App\Http\Requests\Foreigner\SendOtpRequest;
use App\Http\Requests\Foreigner\VerifyOtpRequest;
use App\Services\Enrollment\ForeignerEnrollmentService;
use Illuminate\Http\JsonResponse;

class ForeignerEnrollmentController extends BaseController
{
    public function __construct(
        private readonly ForeignerEnrollmentService $enrollment,
    ) {}

    /**
     * @OA\Post(
     *      path="/api/v1/foreigner/send-otp",
     *      operationId="sendForeignerOtp",
     *      tags={"Enrollment"},
     *      summary="Send OTP to email or phone",
     *      description="Sends an OTP to the provided email address or phone number for verification. Provide either email or phonenumber.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *              @OA\Property(property="phonenumber", type="string", example="+22990123456")
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="OTP sent successfully",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="OTP envoyé à votre adresse email."),
     *              @OA\Property(property="data", type="object",
     *                  @OA\Property(property="email", type="string", example="user@example.com")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=422,
     *          description="Validation error"
     *      )
     * )
     */
    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        return $this->respond($this->enrollment->sendOtp(
            $request->input('email'),
            $request->input('phonenumber'),
        ));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/foreigner/verify-otp",
     *      operationId="verifyForeignerOtp",
     *      tags={"Enrollment"},
     *      summary="Verify OTP",
     *      description="Verifies the OTP sent to the email or phone number. Provide either email or phonenumber with the otp.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"otp"},
     *
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *              @OA\Property(property="phonenumber", type="string", example="+22990123456"),
     *              @OA\Property(property="otp", type="string", example="123456")
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="OTP verified successfully",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="OTP vérifié.")
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=400,
     *          description="Invalid OTP"
     *      )
     * )
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        return $this->respond($this->enrollment->verifyOtp(
            $request->input('email'),
            $request->input('phonenumber'),
            $request->input('otp'),
        ));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/foreigner/enroll",
     *      operationId="submitForeignerEnrollment",
     *      tags={"Enrollment"},
     *      summary="Submit foreigner enrollment request",
     *      description="Submits a complete enrollment request for a physical foreigner. All KYC data, identity documents, and liveness data are provided in a single step. The request is stored for agent review.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *
     *              @OA\Schema(
     *                  required={"email", "phonenumber", "name", "first_name", "sex", "date_of_birth", "place_of_birth", "nationality", "country_of_residence", "address", "document_type", "document_number", "selfie", "recto"},
     *
     *                  @OA\Property(property="email", type="string", format="email"),
     *                  @OA\Property(property="phonenumber", type="string"),
     *                  @OA\Property(property="name", type="string"),
     *                  @OA\Property(property="first_name", type="string"),
     *                  @OA\Property(property="sex", type="string", enum={"M", "F"}),
     *                  @OA\Property(property="date_of_birth", type="string", format="date"),
     *                  @OA\Property(property="place_of_birth", type="string"),
     *                  @OA\Property(property="nationality", type="string"),
     *                  @OA\Property(property="country_of_residence", type="string"),
     *                  @OA\Property(property="address", type="string"),
     *                  @OA\Property(property="document_type", type="string", enum={"PASSPORT", "CNI_ECOWAS"}),
     *                  @OA\Property(property="document_number", type="string"),
     *                  @OA\Property(property="selfie", type="string", format="binary"),
     *                  @OA\Property(property="recto", type="string", format="binary"),
     *                  @OA\Property(property="verso", type="string", format="binary"),
     *                  @OA\Property(property="profile", type="string", format="binary"),
     *                  @OA\Property(property="liveness", type="string"),
     *                  @OA\Property(property="similarity", type="string")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Enrollment request submitted successfully",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Demande enregistrée avec succès."),
     *              @OA\Property(property="data", type="object",
     *                  @OA\Property(property="enrollment_request_id", type="integer")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(response=400, description="OTP not verified"),
     *      @OA\Response(response=422, description="Validation error"),
     *      @OA\Response(response=500, description="Internal server error")
     * )
     */
    public function submitEnrollment(SubmitEnrollmentRequest $request): JsonResponse
    {
        return $this->respond($this->enrollment->submitEnrollment($request));
    }
}
