<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterAgentRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendAdminOtpRequest;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use App\Http\Requests\Auth\UpdateAgentRequest;
use App\Http\Requests\Auth\VerifyAdminOtpRequest;
use App\Models\User;
use App\Services\Auth\AdminAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends BaseController
{
    public function __construct(
        private readonly AdminAuthService $adminAuth,
    ) {}

    /**
     * @OA\Post(
     *      path="/api/admin/login",
     *      operationId="adminLogin",
     *      tags={"Admin Auth"},
     *      summary="Admin/Agent Login (Step 1: Request OTP)",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"email", "password"},
     *
     *              @OA\Property(property="email", type="string", format="email"),
     *              @OA\Property(property="password", type="string", format="password")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="OTP sent to email"),
     *      @OA\Response(response=403, description="Forbidden (Not an agent)")
     * )
     */
    public function loginAdmin(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $user = User::where('email', $request->user()->email)->first();

        return $this->respond($this->adminAuth->issueOtpAfterLogin($user));
    }

    /**
     * @OA\Post(
     *      path="/api/agents/{id}",
     *      operationId="updateAgent",
     *      tags={"Admin Auth"},
     *      summary="Update Agent Details",
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="name", type="string"),
     *              @OA\Property(property="role", type="string", enum={"LEVEL1","LEVEL2","LEVEL3","SUPERVISEUR","AUDITEUR"}),
     *              @OA\Property(property="phonenumber", type="string"),
     *              @OA\Property(property="email", type="string", format="email")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Agent updated")
     * )
     */
    public function updateAgent(UpdateAgentRequest $request, $id): JsonResponse
    {
        $user = User::find($id);
        if (! $user) {
            return $this->sendError('Agent introuvable.', null, 404);
        }

        return $this->respond($this->adminAuth->updateAgent($user, $request->all()));
    }

    public function deleteAgent($id): JsonResponse
    {
        $user = User::find($id);
        if (! $user) {
            return $this->sendError('Agent introuvable.', null, 404);
        }

        return $this->respond($this->adminAuth->deleteAgent($user));
    }

    /**
     * @OA\Post(
     *      path="/api/admins/logout",
     *      operationId="adminLogout",
     *      tags={"Admin Auth"},
     *      summary="Admin Logout",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Response(response=200, description="Logged out")
     * )
     */
    public function logoutAdmin(Request $request): JsonResponse
    {
        $request->user()->token()->revoke();

        return $this->sendResponse('Déconnexion réussie.', []);
    }

    /**
     * @OA\Post(
     *      path="/api/agents/register",
     *      operationId="registerAgent",
     *      tags={"Admin Auth"},
     *      summary="Register New Agent",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"name", "phonenumber", "npi", "email"},
     *
     *              @OA\Property(property="name", type="string"),
     *              @OA\Property(property="role", type="string"),
     *              @OA\Property(property="phonenumber", type="string"),
     *              @OA\Property(property="npi", type="string"),
     *              @OA\Property(property="email", type="string", format="email")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Agent registered")
     * )
     */
    public function registerAgent(RegisterAgentRequest $request): JsonResponse
    {
        return $this->respond($this->adminAuth->registerAgent($request->all()));
    }

    public function listAgents(Request $request): JsonResponse
    {
        return $this->respondPaginated($this->adminAuth->listAgents($request));
    }

    public function showAgent($id): JsonResponse
    {
        return $this->respond($this->adminAuth->showAgent((int) $id));
    }

    public function sendOtp(SendAdminOtpRequest $request)
    {
        return $this->respond($this->adminAuth->sendOtp($request->input('email')));
    }

    /**
     * @OA\Post(
     *      path="/api/admins/verify-otp",
     *      operationId="adminVerifyOtp",
     *      tags={"Admin Auth"},
     *      summary="Admin/Agent Verify OTP (Step 2: Get Token)",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"email", "otp"},
     *
     *              @OA\Property(property="email", type="string", format="email"),
     *              @OA\Property(property="otp", type="string")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Token obtained"),
     *      @OA\Response(response=403, description="Forbidden or Invalid OTP")
     * )
     */
    public function verifyOtp(VerifyAdminOtpRequest $request)
    {
        return $this->respond($this->adminAuth->verifyOtp(
            $request->input('email'),
            $request->input('otp'),
        ));
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        return $this->respond($this->adminAuth->resetPassword($request->validated()));
    }

    public function sendPasswordResetLink(SendPasswordResetLinkRequest $request): JsonResponse
    {
        return $this->respond($this->adminAuth->sendPasswordResetLink($request->input('email')));
    }
}
