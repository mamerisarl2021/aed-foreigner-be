<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\SendFinalisationOtpRequest;
use App\Http\Requests\Enrollment\ShowFinalisationRequest;
use App\Http\Requests\Enrollment\StoreFinalisationRequest;
use App\Http\Requests\Enrollment\VerifyFinalisationOtpRequest;
use App\Http\Resources\FinalisationResource;
use App\Services\Enrollment\ForeignerFinalizationService;
use App\Services\ServiceResult;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;

#[Group('Enrollment - Physique')]
final class FinalisationController extends BaseController
{
    public function __construct(
        private readonly ForeignerFinalizationService $finalizationService,
    ) {}

    /**
     * Validate finalisation invitation token
     *
     * Open the secure link from the approval email (`?token=`). Optional `npi` (legacy links)
     * must match the invitation when present; digits only. Success data: demande_id, numero_suivi, email, statut.
     * The applicant then types the generated NPI (not numero_suivi) on the form.
     * Token TTL: 60 minutes from issuance.
     */
    public function show(ShowFinalisationRequest $request): JsonResponse
    {
        $npi = $request->validated('npi');

        return $this->respondPayload($this->finalizationService->showByToken(
            (string) $request->validated('token'),
            is_string($npi) ? $npi : null,
        ));
    }

    /**
     * Send finalisation email OTP
     *
     * Body: npi (typed digits, generated NPI) + token (from the invitation link).
     * The NPI must match the invitation. Demand must be APPROUVEE.
     * Sends a 6-digit OTP to the enrollment email (AED OTP, not TrustedX MFA).
     * Guest-capable: no Sanctum token required; an existing session does not block the call.
     */
    public function sendOtp(SendFinalisationOtpRequest $request): JsonResponse
    {
        return $this->respondPayload($this->finalizationService->sendOtp(
            (string) $request->validated('npi'),
            (string) $request->validated('token'),
        ));
    }

    /**
     * Verify finalisation email OTP
     *
     * Body: npi (digits), token, otp (6 digits). On success stores a short-lived OTP proof (~15 min)
     * required before POST /enrolements/finalisation.
     * Success data: npi, demande_id, otp_verified=true.
     */
    public function verifyOtp(VerifyFinalisationOtpRequest $request): JsonResponse
    {
        return $this->respondPayload($this->finalizationService->verifyOtp(
            (string) $request->validated('npi'),
            (string) $request->validated('otp'),
            (string) $request->validated('token'),
        ));
    }

    /**
     * Complete enrollment (password + security questions)
     *
     * Body: npi (digits), token (invitation), password (min 8),
     * security_questions (min 2 items with question + answer).
     * Requires prior POST …/finalisation/otp/verify for the same NPI.
     * No client PIN — server generates a 4-digit PIN for TrustedX in a queued job.
     * Success 202: TrustedX provisioning is async; poll POST /enrolements/suivi until statut ENROLEE.
     * Immediate data: demande_id, numero_suivi, npi, statut APPROUVEE.
     */
    #[ScrambleResponse(202, description: 'Finalisation en cours. Poll POST /enrolements/suivi until statut ENROLEE.')]
    public function store(StoreFinalisationRequest $request): JsonResponse
    {
        /** @var array<int, array{question: string, answer: string}> $questions */
        $questions = $request->validated('security_questions');

        return $this->respondPayload($this->finalizationService->finalize(
            (string) $request->validated('npi'),
            (string) $request->validated('token'),
            (string) $request->validated('password'),
            $questions,
        ));
    }

    private function respondPayload(ServiceResult $result): JsonResponse
    {
        if (! $result->success) {
            return $this->respond($result);
        }

        return $this->sendResponse(
            $result->message,
            new FinalisationResource($result->data),
            $result->code,
        );
    }
}
