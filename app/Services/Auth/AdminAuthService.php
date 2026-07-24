<?php

namespace App\Services\Auth;

use App\Enums\ActivityLogAction;
use App\Http\Resources\StaffUserDetailResource;
use App\Http\Resources\StaffUserListResource;
use App\Jobs\ResetPasswordJob;
use App\Jobs\WelcomeAgentJob;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use App\Support\StaffRoleMapper;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminAuthService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /** @return list<string> */
    private static function staffRoles(): array
    {
        return config('roles.staff', []);
    }

    public function updateAgent(User $user, array $input): ServiceResult
    {
        try {
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

            return ServiceResult::ok('Agent mis à jour avec succès.', (new StaffUserDetailResource($user))->resolve());
        } catch (Exception $e) {
            Log::error('Failed to update agent: '.$e->getMessage());

            return ServiceResult::fail('Impossible de mettre à jour l\'agent, veuillez réessayer.', null, 500);
        }
    }

    public function deleteAgent(User $user): ServiceResult
    {
        try {
            $user->roles()->detach();
            $user->delete();

            return ServiceResult::ok('Agent supprimé avec succès.', null);
        } catch (Exception $e) {
            Log::error('Failed to delete agent: '.$e->getMessage());

            return ServiceResult::fail('Impossible de supprimer l\'agent, veuillez réessayer.', null, 500);
        }
    }

    public function registerAgent(array $input): ServiceResult
    {
        try {
            $defaultPassword = Str::password(12);

            $user = User::create([
                'name' => $input['name'],
                'first_name' => $input['first_name'],
                'email' => $input['email'],
                'phonenumber' => $input['phonenumber'],
                'status' => 'ACTIVE',
                'must_change_password' => true,
            ]);
            $user->forceFill(['password' => Hash::make($defaultPassword)])->save();

            $this->assignRoleFromCode($user, $input['role']);
            $user->load('roles');

            WelcomeAgentJob::dispatch($user, $defaultPassword);

            $actorId = Auth::id();
            $this->activityLog->record(
                ActivityLogAction::UtilisateurCree,
                sprintf(
                    '%s a créé le compte utilisateur %s %s (%s).',
                    ActivityLogService::actorLabel(Auth::user()),
                    $user->first_name,
                    $user->name,
                    $user->email
                ),
                is_string($actorId) ? $actorId : null,
            );

            return ServiceResult::ok('Agent enregistré avec succès', (new StaffUserDetailResource($user))->resolve());
        } catch (Exception $e) {
            Log::error('Failed to register agent: '.$e->getMessage());

            return ServiceResult::fail('Impossible de créer le compte agent reessayer.', null, 500);
        }
    }

    public function loginDirect(User $user): ServiceResult
    {
        if (! $user->hasAnyRole(self::staffRoles())) {
            return ServiceResult::fail("L'email fourni n'appartient pas à un agent ou un administrateur.", null, 403);
        }

        if ($user->status !== 'ACTIVE') {
            return ServiceResult::fail('Compte inactif. Contactez un administrateur.', null, 403);
        }

        if (empty($user->password)) {
            return ServiceResult::fail('Mot de passe non défini. Contactez un administrateur.', null, 403);
        }

        $user->last_login_at = now();
        $user->save();

        $token = $user->createToken($user->email.'-'.now())->plainTextToken;

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

        return ServiceResult::ok('Mot de passe mis à jour avec succès.', []);
    }

    public function listAgents(Request $request): ServiceResult
    {
        try {
            $perPage = min((int) $request->input('per_page', $request->input('perPage', 15)), 100);
            $roleFilter = StaffRoleMapper::slugFromCode($request->input('role'));
            $allowedRoles = StaffRoleMapper::listableSlugs();

            $query = User::query()->whereHas('roles', function ($sub) use ($roleFilter, $allowedRoles) {
                if ($roleFilter) {
                    $sub->where('name', $roleFilter);
                } else {
                    $sub->whereIn('name', $allowedRoles);
                }
            })->with('roles');

            if ($request->filled('q')) {
                $q = $request->input('q');
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('first_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            }

            $orderBy = $request->input('order_by', 'created_at');
            if (! in_array($orderBy, ['created_at', 'name', 'email', 'last_login_at'], true)) {
                $orderBy = 'created_at';
            }
            $orderDir = strtolower($request->input('order_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
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
            $record = DB::table('password_reset_tokens')
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

            DB::table('password_reset_tokens')->where('email', $validatedData['email'])->delete();

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

            DB::table('password_reset_tokens')->updateOrInsert(
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
