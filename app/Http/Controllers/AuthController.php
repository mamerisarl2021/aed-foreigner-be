<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ChangeStaffPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterAgentRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use App\Http\Requests\Auth\UpdateAgentRequest;
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
     *      path="/api/v1/admin/login",
     *      operationId="adminLogin",
     *      tags={"Admin Auth"},
     *      summary="Admin/Agent Login (returns Sanctum token)",
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
     *      @OA\Response(response=200, description="Login successful with access_token"),
     *      @OA\Response(response=403, description="Forbidden (Not an agent or inactive account)")
     * )
     */
    public function loginAdmin(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $user = User::where('email', $request->user()->email)->first();

        return $this->respond($this->adminAuth->loginDirect($user));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/admin/password/change",
     *      operationId="adminChangePassword",
     *      tags={"Admin Auth"},
     *      summary="Change staff password (authenticated)",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"current_password", "password", "password_confirmation"},
     *
     *              @OA\Property(property="current_password", type="string", format="password"),
     *              @OA\Property(property="password", type="string", format="password"),
     *              @OA\Property(property="password_confirmation", type="string", format="password")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Password updated"),
     *      @OA\Response(response=400, description="Current password incorrect")
     * )
     */
    public function changePassword(ChangeStaffPasswordRequest $request): JsonResponse
    {
        return $this->respond($this->adminAuth->changePassword(
            $request->user(),
            $request->validated(),
        ));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/agents/{id}",
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
     *              @OA\Property(property="first_name", type="string"),
     *              @OA\Property(property="role", type="string", enum={"AGENT","RESPONSABLE_DE_VALIDATION","MANAGER","AUDITEUR"}),
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
     *      path="/api/v1/admins/logout",
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
     *      path="/api/v1/agents/register",
     *      operationId="registerAgent",
     *      tags={"Admin Auth"},
     *      summary="Register New Agent",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"name", "first_name", "phonenumber", "email", "role"},
     *
     *              @OA\Property(property="name", type="string", description="Nom"),
     *              @OA\Property(property="first_name", type="string", description="Prénoms"),
     *              @OA\Property(property="role", type="string", enum={"AGENT","RESPONSABLE_DE_VALIDATION","MANAGER","AUDITEUR"}),
     *              @OA\Property(property="phonenumber", type="string"),
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

    /**
     * @OA\Get(
     *      path="/api/v1/agents",
     *      operationId="listAgents",
     *      tags={"Admin"},
     *      summary="List staff users (admin)",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="q", in="query", @OA\Schema(type="string")),
     *      @OA\Parameter(name="role", in="query", @OA\Schema(type="string", enum={"AGENT","RESPONSABLE_DE_VALIDATION","MANAGER","AUDITEUR"})),
     *      @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", default=15)),
     *
     *      @OA\Response(response=200, description="Staff list")
     * )
     */
    public function listAgents(Request $request): JsonResponse
    {
        return $this->respondPaginated($this->adminAuth->listAgents($request));
    }

    public function showAgent($id): JsonResponse
    {
        return $this->respond($this->adminAuth->showAgent($id));
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
