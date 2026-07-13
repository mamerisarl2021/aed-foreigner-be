<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Foreigner\FinalizeRegistrationRequest;
use App\Http\Requests\Foreigner\InitRegistrationRequest;
use App\Http\Requests\Foreigner\SendOtpRequest;
use App\Http\Requests\Foreigner\VerifyOtpRequest;
use App\Models\PendingRegistration;
use App\Services\Enrollment\ForeignerEnrollmentService;
use Carbon\Carbon;

class ForeignerEnrollmentController extends BaseController
{
    public function __construct(
        private readonly ForeignerEnrollmentService $enrollment,
    ) {}

    /**
     * @OA\Post(
     *      path="/api/foreigner/send-otp",
     *      operationId="sendOtp",
     *      tags={"Enrollment"},
     *      summary="Send OTP to email",
     *      description="Sends an OTP to the provided email address for verification.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"email"},
     *
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com")
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
    public function sendOtp(SendOtpRequest $request): \Illuminate\Http\JsonResponse
    {
        return $this->respond($this->enrollment->sendOtp($request->input('email')));
    }

    /**
     * @OA\Post(
     *      path="/api/foreigner/verify-otp",
     *      operationId="verifyOtp",
     *      tags={"Enrollment"},
     *      summary="Verify OTP",
     *      description="Verifies the OTP sent to the email.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"email", "otp"},
     *
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com"),
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
    public function verifyOtp(VerifyOtpRequest $request): \Illuminate\Http\JsonResponse
    {
        return $this->respond($this->enrollment->verifyOtp(
            $request->input('email'),
            $request->input('otp'),
        ));
    }

    /**
     * @OA\Post(
     *      path="/api/foreigner/register/init",
     *      operationId="initRegistration",
     *      tags={"Enrollment"},
     *      summary="Initialize Registration",
     *      description="Initializes the registration process, returning a registration token.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *
     *              @OA\Schema(
     *                  required={"email"},
     *
     *                  @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *                  @OA\Property(property="profile", type="string", format="binary")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Registration initialized",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="data", type="object",
     *                  @OA\Property(property="registration_token", type="string"),
     *                  @OA\Property(property="expires_at", type="string", format="date-time"),
     *                  @OA\Property(property="link", type="string")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=400,
     *          description="OTP not verified"
     *      )
     * )
     */
    public function initRegistration(InitRegistrationRequest $request): \Illuminate\Http\JsonResponse
    {
        return $this->respond($this->enrollment->initRegistration(
            $request->input('email'),
            $request->file('profile'),
        ));
    }

    /**
     * @OA\Post(
     *      path="/api/foreigner/register/finalize",
     *      operationId="finalizeRegistration",
     *      tags={"Enrollment"},
     *      summary="Finalize Registration",
     *      description="Finalizes the registration with full details and documents.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *
     *              @OA\Schema(
     *                  required={"registration_token", "transaction_id"},
     *
     *                  @OA\Property(property="registration_token", type="string"),
     *                  @OA\Property(property="transaction_id", type="string"),
     *                  @OA\Property(property="selfie", type="string", format="binary", description="Required"),
     *                  @OA\Property(property="recto", type="string", format="binary", description="Required"),
     *                  @OA\Property(property="verso", type="string", format="binary", description="Required"),
     *                  @OA\Property(property="similarity", type="string", description="Required"),
     *                  @OA\Property(property="liveness", type="string", description="Required"),
     *                  @OA\Property(property="exp_date", type="string", format="date", description="Expiration date of document"),
     *                  @OA\Property(property="birth_date", type="string", format="date", description="Birth date"),
     *                  @OA\Property(property="kyc[name]", type="string"),
     *                  @OA\Property(property="kyc[first_name]", type="string"),
     *                  @OA\Property(property="kyc[phonenumber]", type="string"),
     *                  @OA\Property(property="kyc[nationality]", type="string"),
     *                  @OA\Property(property="kyc[document_type]", type="string", enum={"PASSPORT", "RESIDENCE_PERMIT", "OTHER"}),
     *                  @OA\Property(property="kyc[document_number]", type="string")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Registration finalized",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="data", type="object",
     *                  @OA\Property(property="user_id", type="integer"),
     *                  @OA\Property(property="phonenumber", type="string")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=422,
     *          description="Validation error"
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Internal server error"
     *      )
     * )
     */
    public function finalizeRegistration(FinalizeRegistrationRequest $request): \Illuminate\Http\JsonResponse
    {
        $pending = PendingRegistration::where('registration_token', $request->registration_token)
            ->where('status', 'PENDING')
            ->where('expires_at', '>', Carbon::now())
            ->firstOrFail();

        return $this->respond($this->enrollment->finalizeRegistration($request, $pending));
    }
}
