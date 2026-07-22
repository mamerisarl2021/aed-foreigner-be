<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Enrollment\SubmitMoraleEnrollmentRequest;
use App\Http\Requests\Enrollment\VerifyMoraleEmailRequest;
use App\Http\Requests\Enrollment\VerifyMoralePhoneOtpRequest;
use App\Http\Resources\EnrollmentRequestResource;
use App\Models\EnrollmentRequest;
use App\Services\Enrollment\PersonneMoraleEnrollmentService;
use Illuminate\Http\JsonResponse;

class PersonneMoraleEnrollmentController extends BaseController
{
    public function __construct(
        private readonly PersonneMoraleEnrollmentService $moraleEnrollment,
    ) {}

    public function submit(SubmitMoraleEnrollmentRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        return $this->respond($this->moraleEnrollment->submit($user, $request));
    }

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
            return $this->sendResponse($result->message, new EnrollmentRequestResource($result->data));
        }

        return $this->sendError($result->message, $result->data ?? [], $result->code);
    }

    public function verifyEmail(VerifyMoraleEmailRequest $request, int $id): JsonResponse
    {
        return $this->respond($this->moraleEnrollment->verifyEmail($id, $request->input('token')));
    }

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

    public function verifyPhoneOtp(VerifyMoralePhoneOtpRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        return $this->respond($this->moraleEnrollment->verifyPhoneOtp($user, $id, $request->input('otp')));
    }
}
