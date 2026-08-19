<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ChangeStaffPasswordRequest;
use App\Http\Requests\Auth\KeycloakLoginRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\LogoutAdminRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use App\Models\User;
use App\Services\Auth\AdminAuthService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

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

        /** @var User $user */
        $user = $request->user();

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
        $this->authorize('changeStaffPassword', User::class);

        if (AdminAuthService::staffKeycloakEnabled()) {
            return $this->sendError('Les mots de passe sont gérés via Keycloak.', null, 403);
        }

        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->adminAuth->changePassword($user, $request->validated()));
    }

    /**
     * Admin logout (revokes current token)
     *
     * Canonical path: POST /admin/logout. Historical alias POST /admins/logout is kept.
     */
    public function logoutAdmin(LogoutAdminRequest $request): JsonResponse
    {
        $this->authorize('logoutStaff', User::class);

        /** @var User $user */
        $user = $request->user();

        return $this->respond($this->adminAuth->logout($user));
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
