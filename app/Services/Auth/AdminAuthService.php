<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ActivityLogAction;
use App\Http\Resources\StaffUserDetailResource;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use App\Support\StaffKeycloakRoleMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keycloak est le seul annuaire du staff.
 *
 * L'application ne crée, ne modifie et ne supprime plus aucun compte staff :
 * elle projette en base ce que porte le JWT, le temps d'accrocher les clés
 * étrangères (jetons Sanctum, journaux d'activité, dossiers assignés) et les
 * rôles Spatie que lisent les policies.
 */
class AdminAuthService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly KeycloakJwtValidator $keycloakJwtValidator,
    ) {}

    /**
     * Échange un access token Keycloak (realm pki-portal, client
     * backoffice-stranger) contre un jeton Sanctum.
     *
     * L'admission se décide sur le JWT, jamais sur la base : un porteur de rôle
     * staff inconnu localement est provisionné à la volée. Sans ce provisioning,
     * la plateforme serait inaccessible — plus aucun compte ne peut être créé
     * depuis l'application.
     */
    public function loginWithKeycloak(string $accessToken): ServiceResult
    {
        try {
            $payload = $this->keycloakJwtValidator->validate($accessToken, 'keycloak.staff');
        } catch (\Throwable $e) {
            Log::warning('Staff Keycloak login rejected: '.$e->getMessage());

            return ServiceResult::fail('Token Keycloak invalide.', null, 401);
        }

        // Keycloak décide qui est staff : ce contrôle passe avant toute lecture
        // de la base, sinon un rôle retiré côté realm resterait sans effet.
        $slugs = StaffKeycloakRoleMapper::slugsFromJwt($payload);
        if ($slugs === []) {
            return ServiceResult::fail('Aucun rôle staff Keycloak n\'est associé à ce compte.', null, 403);
        }

        $email = strtolower(trim(
            $this->claim($payload, 'email') ?? $this->claim($payload, 'preferred_username') ?? ''
        ));
        if ($email === '') {
            return ServiceResult::fail('Le token Keycloak ne contient pas d\'email.', null, 401);
        }

        $subject = $this->claim($payload, 'sub');
        if ($subject === null) {
            return ServiceResult::fail('Le token Keycloak ne contient pas de sujet.', null, 401);
        }

        try {
            $user = DB::transaction(fn () => $this->projectKeycloakUser($payload, $subject, $email));
        } catch (\Throwable $e) {
            Log::error('Staff Keycloak provisioning failed: '.$e->getMessage(), [
                'keycloak_id' => $subject,
            ]);

            return ServiceResult::fail('Impossible de synchroniser le compte staff.', null, 500);
        }

        $this->syncStaffRoles($user, $slugs);

        $user->last_login_at = now();
        $user->save();
        $user->load('roles');

        $token = $user->createToken($user->email.'-'.now())->plainTextToken;

        $this->activityLog->record(
            ActivityLogAction::ConnexionAdmin,
            sprintf('%s s\'est connecté(e) à l\'espace staff.', ActivityLogService::actorLabel($user)),
            is_string($user->id) ? $user->id : null,
        );

        return ServiceResult::ok(
            "Bienvenue sur la plateforme d'enregistrement déléguée!",
            [
                'user' => (new StaffUserDetailResource($user))->resolve(),
                'roles' => $user->getRoleNames(),
                'access_token' => $token,
            ]
        );
    }

    public function logout(User $user): ServiceResult
    {
        $this->activityLog->record(
            ActivityLogAction::DeconnexionAdmin,
            sprintf('%s s\'est déconnecté(e) de l\'espace staff.', ActivityLogService::actorLabel($user)),
            $user->id,
        );

        $user->currentAccessToken()?->delete();

        return ServiceResult::ok('Déconnexion réussie.', []);
    }

    /**
     * Retrouve ou crée la ligne locale, puis la réaligne sur le JWT.
     *
     * Le rattachement se fait sur `sub` en priorité : l'email reste un repli
     * pour les comptes antérieurs à cette colonne, et se voit backfillé au
     * passage. Un compte désactivé côté application est réactivé — la
     * désactivation se fait dans Keycloak, qui n'émet alors plus de token.
     *
     * @param  array<string, mixed>  $payload
     */
    private function projectKeycloakUser(array $payload, string $subject, string $email): User
    {
        $user = User::query()->where('keycloak_id', $subject)->first();
        $created = false;

        if ($user === null) {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            // Compte Keycloak recréé sous le même email : on rebascule la ligne
            // locale sur le nouveau sujet. Refuser laisserait le compte
            // définitivement bloqué, l'application n'ayant plus aucun écran
            // d'administration des comptes staff.
            if ($user !== null && is_string($user->keycloak_id) && $user->keycloak_id !== $subject) {
                Log::warning('Staff Keycloak subject changed for an existing email.', [
                    'email' => $email,
                    'previous_keycloak_id' => $user->keycloak_id,
                    'new_keycloak_id' => $subject,
                ]);
            }
        }

        if ($user === null) {
            $user = new User;
            $created = true;
        }

        $user->fill(array_filter([
            'name' => $this->claim($payload, 'family_name'),
            'first_name' => $this->claim($payload, 'given_name'),
        ], fn (?string $value) => $value !== null));

        $user->email = $email;
        $user->keycloak_id = $subject;
        $user->status = 'ACTIVE';
        $user->save();

        if ($created) {
            $this->activityLog->record(
                ActivityLogAction::UtilisateurCree,
                sprintf('Compte staff %s provisionné depuis Keycloak.', $email),
                is_string($user->id) ? $user->id : null,
                null,
                ['keycloak_id' => $subject],
            );
        }

        return $user;
    }

    /**
     * Réplique les rôles du JWT et coupe les sessions ouvertes si le périmètre
     * a changé : sans ça, un rôle retiré dans Keycloak resterait actif jusqu'à
     * l'expiration du jeton Sanctum.
     *
     * @param  list<string>  $slugs
     */
    private function syncStaffRoles(User $user, array $slugs): void
    {
        $previous = $user->getRoleNames()->all();

        $user->syncRoles($slugs);

        sort($previous);
        $current = $slugs;
        sort($current);

        if ($previous !== [] && $previous !== $current) {
            $user->tokens()->delete();

            $this->activityLog->record(
                ActivityLogAction::UtilisateurModifie,
                sprintf('Rôles staff de %s resynchronisés depuis Keycloak.', $user->email),
                is_string($user->id) ? $user->id : null,
                null,
                ['from' => $previous, 'to' => $current],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function claim(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
