<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Auth\KeycloakLoginRequest;
use App\Http\Requests\Auth\LogoutAdminRequest;
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
     * Admin/Agent login via Keycloak token exchange (returns Sanctum token)
     *
     * Seule porte d'entrée du back-office : Keycloak (realm pki-portal, client
     * backoffice-stranger, issuer KC_STAFF_ISSUER) est l'annuaire du staff.
     * Un porteur de rôle staff inconnu de la base est provisionné à la volée ;
     * les rôles Spatie que lisent les policies sont réécrits depuis le JWT à
     * chaque connexion.
     * Le changement de mot de passe à la première connexion relève de l'action
     * requise UPDATE_PASSWORD côté Keycloak : aucun token n'est émis tant
     * qu'elle n'est pas jouée, donc l'application ne voit jamais ce cas.
     */
    public function loginAdminKeycloak(KeycloakLoginRequest $request): JsonResponse
    {
        return $this->respond($this->adminAuth->loginWithKeycloak(
            (string) $request->validated('access_token')
        ));
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
}
