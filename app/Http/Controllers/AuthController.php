<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityLogAction;
use App\Http\Requests\Auth\ChangeStaffPasswordRequest;
use App\Http\Requests\Auth\DeleteAgentRequest;
use App\Http\Requests\Auth\KeycloakLoginRequest;
use App\Http\Requests\Auth\ListAgentsRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\LogoutAdminRequest;
use App\Http\Requests\Auth\RegisterAgentRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use App\Http\Requests\Auth\ShowAgentRequest;
use App\Http\Requests\Auth\UpdateAgentRequest;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\Auth\AdminAuthService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;

#[Group('Admin Auth')]
class AuthController extends BaseController
{
    public function __construct(
        private readonly AdminAuthService $adminAuth,
        private readonly ActivityLogService $activityLog,
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
     * Exchange a Keycloak access token from realm pki-portal / client
     * backoffice-stranger (issuer KC_STAFF_ISSUER) for a Sanctum token.
     * The local user must already exist (email match); Spatie staff roles are
     * synced from the JWT. Only available when STAFF_KEYCLOAK_ENABLED=true.
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
    #[PathParameter('id', description: 'Staff user UUID.', type: 'string', format: 'uuid')]
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

        return $this->respond($this->adminAuth->updateAgent($user, $validated, $request->user()));
    }

    /**
     * Delete a staff user (admin only)
     */
    #[PathParameter('id', description: 'Staff user UUID.', type: 'string', format: 'uuid')]
    public function deleteAgent(DeleteAgentRequest $request): JsonResponse
    {
        $this->authorize('manageStaff', User::class);

        $user = User::query()->find((string) $request->validated('id'));
        if (! $user) {
            return $this->sendError('Agent introuvable.', null, 404);
        }

        return $this->respond($this->adminAuth->deleteAgent($user, $request->user()));
    }

    /**
     * Admin logout (revokes current token)
     */
    public function logoutAdmin(LogoutAdminRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        $this->activityLog->record(
            ActivityLogAction::DeconnexionAdmin,
            sprintf('%s s\'est déconnecté(e) de l\'espace staff.', ActivityLogService::actorLabel($user)),
            $user->id,
        );

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
     * Optional role filter (`AGENT`, `RESPONSABLE_DE_VALIDATION`, `MANAGER`).
     * Defaults: per_page=15 (max 100), order_by=created_at, order_dir=desc.
     */
    public function listAgents(ListAgentsRequest $request): JsonResponse
    {
        $this->authorize('manageStaff', User::class);

        return $this->respondPaginated($this->adminAuth->listAgents($request->validated()));
    }

    /**
     * Staff user detail (admin only)
     */
    #[PathParameter('id', description: 'Staff user UUID.', type: 'string', format: 'uuid')]
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
