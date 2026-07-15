<?php

namespace App\Http\Controllers;

use App\Services\SigningIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SigningIdentityController extends BaseController
{
    public function __construct(
        private readonly SigningIdentityService $signingIdentityService,
    ) {}

    /**
     * @OA\Get(
     *      path="/api/v1/signing-identities",
     *      operationId="getSigningIdentities",
     *      tags={"Certificates"},
     *      summary="List Signing Identities",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="token", in="query", required=true, @OA\Schema(type="string")),
     *
     *      @OA\Response(response=200, description="Successful operation"),
     *      @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function getSigningIdentities(Request $request): JsonResponse
    {
        $result = $this->signingIdentityService->getSigningIdentities(
            $request->input('token'),
            $request->input('labels'),
            $request->input('user_id'),
            $request->input('domain'),
        );

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }

    /**
     * @OA\Get(
     *      path="/api/v1/signing-identities/{identityId}",
     *      operationId="getSigningIdentity",
     *      tags={"Certificates"},
     *      summary="Get Signing Identity Details",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="identityId", in="path", required=true, @OA\Schema(type="string")),
     *      @OA\Parameter(name="token", in="query", required=true, @OA\Schema(type="string")),
     *
     *      @OA\Response(response=200, description="Successful operation")
     * )
     */
    public function getSigningIdentity(Request $request, $identityId): JsonResponse
    {
        $result = $this->signingIdentityService->getSigningIdentity(
            $request->input('token'),
            (string) $identityId,
        );

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }

    /**
     * @OA\Put(
     *      path="/api/v1/signing-identities/{identityId}/status",
     *      operationId="updateSigningIdentityStatus",
     *      tags={"Certificates"},
     *      summary="Update Signing Identity Status",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="identityId", in="path", required=true, @OA\Schema(type="string")),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"value", "reason"},
     *
     *              @OA\Property(property="value", type="string"),
     *              @OA\Property(property="reason", type="string")
     *          )
     *      ),
     *
     *      @OA\Response(response=204, description="Success"),
     *      @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function updateSigningIdentityStatus(Request $request, $identityId): JsonResponse
    {
        $result = $this->signingIdentityService->updateSigningIdentityStatus(
            (string) $identityId,
            $request->input('value'),
            $request->input('reason'),
        );

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/signing-identities/{identityId}",
     *      operationId="deleteSigningIdentity",
     *      tags={"Certificates"},
     *      summary="Delete Signing Identity",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="identityId", in="path", required=true, @OA\Schema(type="string")),
     *
     *      @OA\Response(response=200, description="Deleted successfully")
     * )
     */
    public function deleteSigningIdentity($identityId): JsonResponse
    {
        $result = $this->signingIdentityService->deleteSigningIdentity((string) $identityId);

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }

    /**
     * @OA\Post(
     *      path="/api/v1/signing-identities/provision",
     *      operationId="provisionSignature",
     *      tags={"Certificates"},
     *      summary="Provision Certificate (Signature)",
     *      description="Starts the certificate issuance process on TrustedX RAP.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"code", "type", "subscription_id"},
     *
     *              @OA\Property(property="code", type="string"),
     *              @OA\Property(property="type", type="string", enum={"citizen", "employee"}),
     *              @OA\Property(property="subscription_id", type="integer")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Process finished or in progress"),
     *      @OA\Response(response=400, description="Validation or Access error"),
     *      @OA\Response(response=403, description="Forbidden (Manager approval or account inactive)")
     * )
     */
    public function provisionSignature(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'code' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'subscription_id' => 'required|integer',
        ]);

        $result = $this->signingIdentityService->provisionSignature(
            $validatedData['code'],
            $validatedData['type'],
            (int) $validatedData['subscription_id'],
        );

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }
}
