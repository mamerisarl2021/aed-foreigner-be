<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ChangeStaffPasswordRequest;
use App\Http\Requests\Auth\DeleteAgentRequest;
use App\Http\Requests\Auth\KeycloakLoginRequest;
use App\Http\Requests\Auth\ListAgentsRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterAgentRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use App\Http\Requests\Auth\ShowAgentRequest;
use App\Http\Requests\Auth\UpdateAgentRequest;
use App\Models\User;
use App\Services\Auth\AdminAuthService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Auth')]
class AuthController extends BaseController
{
    public function __construct(
        private readonly AdminAuthService $adminAuth,
    ) {}

    /**
     * Admin/Agent login (returns Sanctum token)
     *
     * Disabled (403) when STAFF_KEYCLOAK_ENABLED=true — use POST /admin/login/keycloak instead.
     */
    public function loginAdmin(LoginRequest $request): JsonResponse
    {
        if (AdminAuthService::staffKeycloakEnabled()) {
            return $this->sendError('Authentification via Keycloak requise.', null, 403);
        }

        $request->authenticate();

        $user = User::where('email', $request->user()->email)->first();

        return $this->respond($this->adminAuth->loginDirect($user));
    }

    /**
     * Admin/Agent login via Keycloak token exchange (returns Sanctum token)
     *
     * Exchange a Keycloak access token (obtained by the frontend through OIDC login)
     * for a Sanctum token. Only available when STAFF_KEYCLOAK_ENABLED=true.
     */
    public function loginAdminKeycloak(KeycloakLoginRequest $request): JsonResponse
    {
        return $this->respond($this->adminAuth->loginWithKeycloak($request->input('access_token')));
    }

    /**
     * Change staff password (authenticated)
     *
     * Disabled (403) when STAFF_KEYCLOAK_ENABLED=true — passwords are managed in Keycloak.
     */
    public function changePassword(ChangeStaffPasswordRequest $request): JsonResponse
    {
        if (AdminAuthService::staffKeycloakEnabled()) {
            return $this->sendError('Les mots de passe sont gérés via Keycloak.', null, 403);
        }

        return $this->respond($this->adminAuth->changePassword(
            $request->user(),
            $request->validated(),
        ));
    }

    /**
     * Update staff user details (admin only)
     */
    public function updateAgent(UpdateAgentRequest $request): JsonResponse
    {
        $this->authorize('manageStaff', User::class);

        $validated = $request->validated();
        $id = (string) $validated['id'];
        unset($validated['id']);

        $user = User::query()->find($id);
        if (! $user) {
            return $this->sendError('Agent introuvable.', null, 404);
        }

        return $this->respond($this->adminAuth->updateAgent($user, $validated));
    }

    /**
     * Delete a staff user (admin only)
     */
    public function deleteAgent(DeleteAgentRequest $request): JsonResponse
    {
        $this->authorize('manageStaff', User::class);

        $user = User::query()->find((string) $request->validated('id'));
        if (! $user) {
            return $this->sendError('Agent introuvable.', null, 404);
        }

        return $this->respond($this->adminAuth->deleteAgent($user));
    }

    /**
     * Admin logout (revokes current token)
     */
    public function logoutAdmin(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->sendResponse('Déconnexion réussie.', []);
    }

    /**
     * Register a new staff user (admin only)
     */
    public function registerAgent(RegisterAgentRequest $request): JsonResponse
    {
        $this->authorize('manageStaff', User::class);

        return $this->respond($this->adminAuth->registerAgent($request->validated(), $request->user()));
    }

    /**
     * List staff users (admin only)
     *
     * Optional role filter. Defaults: per_page=15 (max 100).
     */
    public function listAgents(ListAgentsRequest $request): JsonResponse
    {
        $this->authorize('manageStaff', User::class);

        return $this->respondPaginated($this->adminAuth->listAgents($request));
    }

    /**
     * Staff user detail (admin only)
     */
    public function showAgent(ShowAgentRequest $request): JsonResponse
    {
        $this->authorize('manageStaff', User::class);

        return $this->respond($this->adminAuth->showAgent((string) $request->validated('id')));
    }

    /**
     * Reset staff password using an emailed token
     *
     * Disabled (403) when STAFF_KEYCLOAK_ENABLED=true — passwords are managed in Keycloak.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        if (AdminAuthService::staffKeycloakEnabled()) {
            return $this->sendError('Les mots de passe sont gérés via Keycloak.', null, 403);
        }

        return $this->respond($this->adminAuth->resetPassword($request->validated()));
    }

    /**
     * Send staff password reset link
     *
     * Disabled (403) when STAFF_KEYCLOAK_ENABLED=true — passwords are managed in Keycloak.
     */
    public function sendPasswordResetLink(SendPasswordResetLinkRequest $request): JsonResponse
    {
        if (AdminAuthService::staffKeycloakEnabled()) {
            return $this->sendError('Les mots de passe sont gérés via Keycloak.', null, 403);
        }

        return $this->respond($this->adminAuth->sendPasswordResetLink($request->input('email')));
    }
}
