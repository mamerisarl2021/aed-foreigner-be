<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Foreigner\SendOtpRequest;
use App\Http\Requests\Foreigner\VerifyOtpRequest;
use App\Services\Enrollment\OtpService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Enrollment - OTP')]
final class OtpController extends BaseController
{
    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    /**
     * Send OTP to email and/or phone
     *
     * Diagram §2.2. Publishes otp.send event and delivers via notify.email / notify.sms.
     * Call once with both email and phonenumber, then verify each channel separately.
     * phonenumber: optional leading +, then 8–20 digits; spaces/dashes/parentheses allowed and stripped.
     */
    public function send(SendOtpRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $phonenumber = $request->validated('phonenumber');

        return $this->respond($this->otpService->send(
            is_string($email) ? $email : null,
            is_string($phonenumber) ? $phonenumber : null,
        ));
    }

    /**
     * Verify OTP for one channel (email or phone)
     *
     * Call twice before POST /kyc/verify. Prefer sending both email and phonenumber on each
     * call so the response reflects both cache flags; only the OTP matching one channel is consumed.
     * Success data includes email_verified / phone_verified and the French aliases
     * email_verifie / telephone_verifie (same keys as personne morale).
     * phonenumber: optional leading +, then 8–20 digits; spaces/dashes/parentheses allowed and stripped.
     */
    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $phonenumber = $request->validated('phonenumber');

        return $this->respond($this->otpService->verify(
            is_string($email) ? $email : null,
            is_string($phonenumber) ? $phonenumber : null,
            (string) $request->validated('otp'),
        ));
    }
}
