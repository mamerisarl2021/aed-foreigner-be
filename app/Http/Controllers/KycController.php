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
     * Sync KYC / liveness verification
     *
     * Diagram §2.3. Requires both OTP channels verified. Stores KYC session in cache for submit.
     */
    public function verify(VerifyKycRequest $request): JsonResponse
    {
        return $this->respond($this->kycVerification->verify($request));
    }
}
