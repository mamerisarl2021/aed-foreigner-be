<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ActivityLogAction;
use App\Exceptions\StaffKeycloakAdminException;
use App\Http\Resources\StaffUserDetailResource;
use App\Http\Resources\StaffUserListResource;
use App\Jobs\ResetPasswordJob;
use App\Jobs\WelcomeAgentJob;
use App\Models\StaffPasswordResetToken;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use App\Support\StaffKeycloakRoleMapper;
use App\Support\StaffRoleMapper;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminAuthService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly KeycloakJwtValidator $keycloakJwtValidator,
        private readonly StaffKeycloakAdminClient $staffKeycloakAdmin,
    ) {}

    public static function staffKeycloakEnabled(): bool
    {
        return (bool) config('keycloak.staff.enabled');
    }

    /** @return list<string> */
    private static function staffRoles(): array
    {
        return config('roles.staff', []);
    }

    public function updateAgent(User $user, array $input, ?User $actor = null): ServiceResult
    {
        try {
            if (self::staffKeycloakEnabled()) {
                $roleSlug = $this->staffSlugForSync($user, $input['role'] ?? null);
                if ($roleSlug === null) {
                    return ServiceResult::fail('Rôle staff invalide.', null, 400);
                }

                $this->staffKeycloakAdmin->syncUser($user->email, [
                    'email' => $input['email'] ?? $user->email,
                    'first_name' => $input['first_name'] ?? $user->first_name,
                    'name' => $input['name'] ?? $user->name,
                    'role' => $roleSlug,
                ]);
            }

            $user->update([
                'name' => $input['name'] ?? $user->name,
                'first_name' => $input['first_name'] ?? $user->first_name,
                'email' => $input['email'] ?? $user->email,
                'phonenumber' => $input['phonenumber'] ?? $user->phonenumber,
                'npi' => $input['npi'] ?? $user->npi,
            ]);

            if (array_key_exists('role', $input)) {
                $user->roles()->detach();
                $this->assignRoleFromCode($user, $input['role']);
            }

            $user->load('roles');

            $this->activityLog->record(
                ActivityLogAction::UtilisateurModifie,
                sprintf(
                    '%s a modifié le compte %s (%s).',
                    ActivityLogService::actorLabel($actor),
                    trim(($user->first_name ?? '').' '.($user->name ?? '')),
                    $user->email
                ),
                is_string($actor?->id) ? $actor->id : null,
                null,
                ['target_user_id' => $user->id],
            );

            return ServiceResult::ok('Agent mis à jour avec succès.', (new StaffUserDetailResource($user))->resolve());
        } catch (StaffKeycloakAdminException $e) {
            Log::error('Failed to sync agent to Keycloak: '.$e->getMessage());

            return ServiceResult::fail('Synchronisation Keycloak impossible.', null, $e->status());
        } catch (Exception $e) {
            Log::error('Failed to update agent: '.$e->getMessage());

            return ServiceResult::fail('Impossible de mettre à jour l\'agent, veuillez réessayer.', null, 500);
        }
    }

    public function deleteAgent(User $user, ?User $actor = null): ServiceResult
    {
        try {
            if (self::staffKeycloakEnabled()) {
                $this->staffKeycloakAdmin->deleteByEmail((string) $user->email);
            }

            $label = trim(($user->first_name ?? '').' '.($user->name ?? '')).' ('.$user->email.')';
            $targetId = $user->id;
            $user->roles()->detach();
            $user->delete();

            $this->activityLog->record(
                ActivityLogAction::UtilisateurSupprime,
                sprintf('%s a supprimé le compte %s.', ActivityLogService::actorLabel($actor), $label),
                is_string($actor?->id) ? $actor->id : null,
                null,
                ['target_user_id' => $targetId],
            );

            return ServiceResult::ok('Agent supprimé avec succès.', null);
        } catch (StaffKeycloakAdminException $e) {
            Log::error('Failed to delete agent on Keycloak: '.$e->getMessage());

            return ServiceResult::fail('Synchronisation Keycloak impossible.', null, $e->status());
        } catch (Exception $e) {
            Log::error('Failed to delete agent: '.$e->getMessage());

            return ServiceResult::fail('Impossible de supprimer l\'agent, veuillez réessayer.', null, 500);
        }
    }

    public function registerAgent(array $input, ?User $actor = null): ServiceResult
    {
        try {
            $user = User::create([
                'name' => $input['name'],
                'first_name' => $input['first_name'],
                'email' => $input['email'],
                'phonenumber' => $input['phonenumber'],
                'status' => 'ACTIVE',
                'must_change_password' => true,
            ]);
            $user->forceFill(['password' => Hash::make(Str::password(64))])->save();

            $this->assignRoleFromCode($user, $input['role']);
            $user->load('roles');

            if (self::staffKeycloakEnabled()) {
                try {
                    $roleSlug = $this->staffSlugForSync($user, $input['role']);
                    if ($roleSlug === null) {
                        $user->roles()->detach();
                        $user->delete();

                        return ServiceResult::fail('Rôle staff invalide.', null, 400);
                    }

                    $keycloakId = $this->staffKeycloakAdmin->syncUser($user->email, [
                        'email' => $user->email,
                        'first_name' => $user->first_name,
                        'name' => $user->name,
                        'role' => $roleSlug,
                    ]);
                    if ((bool) config('keycloak.staff.execute_actions_email')) {
                        $this->staffKeycloakAdmin->sendUpdatePasswordEmail($keycloakId);
                    } else {
                        WelcomeAgentJob::dispatch($user);
                    }
                } catch (StaffKeycloakAdminException $e) {
                    $user->roles()->detach();
                    $user->delete();
                    Log::error('Failed to sync new agent to Keycloak: '.$e->getMessage());

                    return ServiceResult::fail('Synchronisation Keycloak impossible.', null, $e->status());
                }
            } else {
                WelcomeAgentJob::dispatch($user);
            }

            $this->activityLog->record(
                ActivityLogAction::UtilisateurCree,
                sprintf(
                    '%s a créé le compte utilisateur %s %s (%s).',
                    ActivityLogService::actorLabel($actor),
                    $user->first_name,
                    $user->name,
                    $user->email
                ),
                is_string($actor?->id) ? $actor->id : null,
            );

            return ServiceResult::ok('Agent enregistré avec succès', (new StaffUserDetailResource($user))->resolve());
        } catch (Exception $e) {
            Log::error('Failed to register agent: '.$e->getMessage());

            return ServiceResult::fail('Impossible de créer le compte agent reessayer.', null, 500);
        }
    }

    public function loginDirect(User $user, bool $requirePassword = true): ServiceResult
    {
        if (! $user->hasAnyRole(self::staffRoles())) {
            return ServiceResult::fail("L'email fourni n'appartient pas à un agent ou un administrateur.", null, 403);
        }

        if ($user->status !== 'ACTIVE') {
            return ServiceResult::fail('Compte inactif. Contactez un administrateur.', null, 403);
        }

        if ($requirePassword && empty($user->password)) {
            return ServiceResult::fail('Mot de passe non défini. Contactez un administrateur.', null, 403);
        }

        $user->last_login_at = now();
        $user->save();

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
                'must_change_password' => (bool) $user->must_change_password,
            ]
        );
    }

    /**
     * Exchange a Keycloak access token (frontend OIDC on realm pki-portal /
     * client backoffice-stranger) for a Sanctum token. The local user must
     * already exist (email match); Spatie staff roles are replaced from the JWT.
     */
    public function loginWithKeycloak(string $accessToken): ServiceResult
    {
        if (! self::staffKeycloakEnabled()) {
            return ServiceResult::fail('Authentification Keycloak non activée.', null, 403);
        }

        try {
            $payload = $this->keycloakJwtValidator->validate($accessToken, 'keycloak.staff');
        } catch (\Throwable $e) {
            Log::warning('Staff Keycloak login rejected: '.$e->getMessage());

            return ServiceResult::fail('Token Keycloak invalide.', null, 401);
        }

        $email = $payload['email'] ?? $payload['preferred_username'] ?? null;
        if (! is_string($email) || $email === '') {
            return ServiceResult::fail('Le token Keycloak ne contient pas d\'email.', null, 401);
        }

        $user = User::where('email', strtolower(trim($email)))->first();
        if (! $user) {
            return ServiceResult::fail("L'email fourni n'appartient pas à un agent ou un administrateur.", null, 403);
        }

        if ($user->status !== 'ACTIVE') {
            return ServiceResult::fail('Compte inactif. Contactez un administrateur.', null, 403);
        }

        $slugs = StaffKeycloakRoleMapper::slugsFromJwt($payload);
        if ($slugs === []) {
            return ServiceResult::fail('Aucun rôle staff Keycloak n\'est associé à ce compte.', null, 403);
        }

        $user->syncRoles($slugs);
        $user->load('roles');

        return $this->loginDirect($user, requirePassword: false);
    }

    /**
     * @param  array{current_password: string, password: string}  $validatedData
     */
    public function changePassword(User $user, array $validatedData): ServiceResult
    {
        if (! Hash::check($validatedData['current_password'], $user->password)) {
            return ServiceResult::fail('Mot de passe actuel incorrect.', null, 400);
        }

        $user->password = Hash::make($validatedData['password']);
        $user->must_change_password = false;
        $user->save();

        // Revoke every Sanctum token so all sessions must re-authenticate.
        $user->tokens()->delete();

        $this->activityLog->record(
            ActivityLogAction::MotDePasseChange,
            sprintf('%s a modifié son mot de passe staff.', ActivityLogService::actorLabel($user)),
            is_string($user->id) ? $user->id : null,
        );

        return ServiceResult::ok('Mot de passe mis à jour avec succès. Veuillez vous reconnecter.', []);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listAgents(array $filters): ServiceResult
    {
        try {
            $perPage = min((int) ($filters['per_page'] ?? 15), 100);
            $roleFilter = StaffRoleMapper::slugFromCode(
                isset($filters['role']) && is_string($filters['role']) ? $filters['role'] : null
            );
            $allowedRoles = StaffRoleMapper::listableSlugs();

            $query = User::query()->whereHas('roles', function ($sub) use ($roleFilter, $allowedRoles) {
                $sub->whereIn('name', $allowedRoles);
                if ($roleFilter !== null && in_array($roleFilter, $allowedRoles, true)) {
                    $sub->where('name', $roleFilter);
                }
            })->with('roles');

            $q = $filters['q'] ?? null;
            if (is_string($q) && $q !== '') {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('first_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            }

            $orderBy = $filters['order_by'] ?? 'created_at';
            if (! in_array($orderBy, ['created_at', 'name', 'email', 'last_login_at'], true)) {
                $orderBy = 'created_at';
            }
            $orderDir = strtolower((string) ($filters['order_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
            $query->orderBy($orderBy, $orderDir);

            $agents = $query->paginate($perPage);
            $agents->getCollection()->transform(
                fn (User $user) => (new StaffUserListResource($user))->resolve()
            );

            return ServiceResult::ok('Liste des agents.', $this->flattenPagination($agents));
        } catch (Exception $e) {
            Log::error('Impossible de récupérer les agents: '.$e->getMessage());

            return ServiceResult::fail('Impossible de récupérer la liste des agents.', null, 500);
        }
    }

    public function showAgent(string $id): ServiceResult
    {
        try {
            $agent = User::whereHas('roles', function ($query) {
                $query->whereIn('name', StaffRoleMapper::listableSlugs());
            })->with('roles')->findOrFail($id);

            return ServiceResult::ok('Agent récupéré avec succès', (new StaffUserDetailResource($agent))->resolve());
        } catch (ModelNotFoundException $e) {
            Log::error('Agent not found: '.$e->getMessage());

            return ServiceResult::fail('Agent non trouvé.', null, 404);
        } catch (Exception $e) {
            Log::error('Failed to retrieve agent: '.$e->getMessage());

            return ServiceResult::fail('Impossible de récupérer l\'agent, veuillez réessayer.', null, 500);
        }
    }

    /**
     * @param  array{token: string, email: string, password: string}  $validatedData
     */
    public function resetPassword(array $validatedData): ServiceResult
    {
        try {
            $record = StaffPasswordResetToken::query()
                ->where('email', $validatedData['email'])
                ->first();

            if (! $record || ! Hash::check($validatedData['token'], $record->token)) {
                return ServiceResult::fail('Token invalide ou expiré.', null, 400);
            }

            $user = User::where('email', $validatedData['email'])->first();
            $user->password = Hash::make($validatedData['password']);
            $user->status = 'ACTIVE';
            $user->must_change_password = false;
            $user->save();

            $this->activityLog->record(
                ActivityLogAction::MotDePasseReinitialise,
                sprintf('%s a réinitialisé son mot de passe staff.', ActivityLogService::actorLabel($user)),
                is_string($user->id) ? $user->id : null,
            );

            // Revoke every Sanctum token so all sessions must re-authenticate.
            $user->tokens()->delete();

            $record->delete();

            return ServiceResult::ok('Mot de passe réinitialisé avec succès.', []);
        } catch (Exception $e) {
            return ServiceResult::fail('Impossible de mettre à jour le mot de passe', null, 401);
        }
    }

    public function sendPasswordResetLink(string $email): ServiceResult
    {
        try {
            $user = User::where('email', $email)->first();

            if (! $user || ! $user->hasAnyRole(self::staffRoles())) {
                return ServiceResult::fail(
                    'Vous ne disposez d\'aucun des privilièges requis pour la mise à jour du mot de passe sur cette interface',
                    null,
                    403
                );
            }

            $token = Str::random(60);

            StaffPasswordResetToken::query()->updateOrCreate(
                ['email' => $user->email],
                [
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ]
            );

            $resetLink = config('app.frontend_url').'/reset-password/'.$token.'/'.urlencode($user->email);
            ResetPasswordJob::dispatch($user, $resetLink);

            return ServiceResult::ok('Lien de réinitialisation envoyé avec succès.', null);
        } catch (Exception $e) {
            Log::error('Failed to send password reset link: '.$e->getMessage());

            return ServiceResult::fail('Impossible d\'envoyer le lien de réinitialisation, réessayer.', null, 400);
        }
    }

    private function assignRoleFromCode(User $user, ?string $role): void
    {
        $slug = StaffRoleMapper::slugFromCode($role) ?? config('roles.client');
        $user->assignRole($slug);
    }

    private function staffSlugForSync(User $user, mixed $roleCode = null): ?string
    {
        $staff = self::staffRoles();

        if (is_string($roleCode) && $roleCode !== '') {
            $slug = StaffRoleMapper::slugFromCode($roleCode);

            return is_string($slug) && in_array($slug, $staff, true) ? $slug : null;
        }

        foreach ($staff as $slug) {
            if ($user->hasRole($slug)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * @return array{data: mixed, pagination: array<string, mixed>}
     */
    private function flattenPagination(LengthAwarePaginator $paginator): array
    {
        $flattenedData = $paginator->toArray();
        $data = $flattenedData['data'];
        unset($flattenedData['data']);

        return array_merge(['data' => $data], ['pagination' => $flattenedData]);
    }
}
