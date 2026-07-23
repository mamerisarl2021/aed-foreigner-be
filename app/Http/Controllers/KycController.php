<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Enrollment\KycVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

final class KycController extends BaseController
{
    public function __construct(
        private readonly KycVerificationService $kycVerification,
    ) {}

    /**
     * @OA\Post(
     *      path="/api/v1/kyc/verify",
     *      operationId="enrollmentKycVerify",
     *      tags={"Enrollment - KYC"},
     *      summary="Sync KYC / liveness verification",
     *      description="Diagram §2.3. Requires both OTP channels verified. Stores KYC session in cache for submit.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *
     *              @OA\Schema(
     *                  required={"email", "phonenumber"},
     *
     *                  @OA\Property(property="email", type="string", format="email"),
     *                  @OA\Property(property="phonenumber", type="string"),
     *                  @OA\Property(property="liveness", type="number", format="float", example=0.95),
     *                  @OA\Property(property="similarity", type="number", format="float", example=0.88),
     *                  @OA\Property(property="selfie", type="string", format="binary"),
     *                  @OA\Property(property="recto", type="string", format="binary"),
     *                  @OA\Property(property="verso", type="string", format="binary")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="KYC valide"),
     *      @OA\Response(response=400, description="OTP non vérifié ou KYC échoué"),
     *      @OA\Response(response=422, description="Validation error")
     * )
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'phonenumber' => 'required|string',
            'liveness' => 'nullable',
            'similarity' => 'nullable|numeric',
            'selfie' => 'nullable|file',
            'recto' => 'nullable|file',
            'verso' => 'nullable|file',
        ]);

        return $this->respond($this->kycVerification->verify($request));
    }
}
