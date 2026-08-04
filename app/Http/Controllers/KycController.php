<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\VerifyKycRequest;
use App\Services\Enrollment\KycVerificationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Enrollment - KYC')]
final class KycController extends BaseController
{
    public function __construct(
        private readonly KycVerificationService $kycVerification,
    ) {}

    /**
     * Sync KYC verification (Document Reader + Face match)
     *
     * Diagram §2.3. Requires both OTP channels verified.
     * When REGULA_MOCK=false: selfie + recto required; verso optional.
     * Optional liveness / liveness_transaction_id = Face liveness transaction id (not a client score).
     * Client similarity is ignored for the OK/KO gate; scores come from Face /api/match.
     * Success caches KYC session ~30 min for submit; data includes kyc_valid, risk_score, similarity.
     * phonenumber: optional leading +, then 8–20 digits; spaces/dashes/parentheses allowed and stripped.
     */
    public function verify(VerifyKycRequest $request): JsonResponse
    {
        return $this->respond($this->kycVerification->verify($request));
    }
}
