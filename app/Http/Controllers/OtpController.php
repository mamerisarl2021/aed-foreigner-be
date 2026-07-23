<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Enrollment\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

final class OtpController extends BaseController
{
    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    /**
     * @OA\Post(
     *      path="/api/v1/otp/send",
     *      operationId="enrollmentOtpSend",
     *      tags={"Enrollment - OTP"},
     *      summary="Send OTP to email and/or phone",
     *      description="Diagram §2.2. Publishes otp.send event and delivers via notify.email / notify.sms. Call once with both email and phonenumber, then verify each channel separately.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="email", type="string", format="email", example="etranger@example.com"),
     *              @OA\Property(property="phonenumber", type="string", example="+22990123456")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="OTP en cours d'envoi"),
     *      @OA\Response(response=422, description="Validation error")
     * )
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'nullable|email',
            'phonenumber' => 'nullable|string',
        ]);

        return $this->respond($this->otpService->send(
            $request->input('email'),
            $request->input('phonenumber'),
        ));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/otp/verify",
     *      operationId="enrollmentOtpVerify",
     *      tags={"Enrollment - OTP"},
     *      summary="Verify OTP for one channel (email or phone)",
     *      description="Call twice (email, then phonenumber) before POST /kyc/verify. Both channels must be verified.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"otp"},
     *
     *              @OA\Property(property="email", type="string", format="email"),
     *              @OA\Property(property="phonenumber", type="string"),
     *              @OA\Property(property="otp", type="string", example="123456")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="OTP valide"),
     *      @OA\Response(response=400, description="OTP invalide ou expiré")
     * )
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'nullable|email',
            'phonenumber' => 'nullable|string',
            'otp' => 'required|string',
        ]);

        return $this->respond($this->otpService->verify(
            $request->input('email'),
            $request->input('phonenumber'),
            $request->input('otp'),
        ));
    }
}
