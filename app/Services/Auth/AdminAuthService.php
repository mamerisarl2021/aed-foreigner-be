<?php

namespace App\Services\Auth;

use App\Jobs\ResetPasswordJob;
use App\Jobs\WelcomeAgentJob;
use App\Models\User;
use App\Services\ServiceResult;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminAuthService
{
    /** @return list<string> */
    private static function staffRoles(): array
    {
        return config('roles.staff', []);
    }

    /** @return list<string> */
    private static function listableStaffRoles(): array
    {
        return array_values(array_filter(
            self::staffRoles(),
            fn (string $role) => $role !== config('roles.administrateur_plateforme')
        ));
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

            return ServiceResult::ok('Agent mis à jour avec succès.', $user);
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

            WelcomeAgentJob::dispatch($user, $defaultPassword);

            return ServiceResult::ok('Agent enregistré avec succès', $user);
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

        $token = $user->createToken($user->email.'-'.now())->plainTextToken;

        return ServiceResult::ok(
            "Bienvenue sur la plateforme d'enregistrement déléguée!",
            [
                'user' => $user,
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
            $perPage = min((int) $request->get('perPage', 15), 100);
            $role = $request->get('role', null);
            $allowedRoles = self::listableStaffRoles();

            if ($role && in_array($role, $allowedRoles, true)) {
                $agents = User::whereHas('roles', function ($query) use ($role) {
                    $query->where('name', $role);
                })->with('roles')->paginate($perPage);
            } else {
                $agents = User::whereHas('roles', function ($query) use ($allowedRoles) {
                    $query->whereIn('name', $allowedRoles);
                })->with('roles')->paginate($perPage);
            }

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
                $query->whereIn('name', self::listableStaffRoles());
            })->with('roles')->findOrFail($id);

            return ServiceResult::ok('Agent récupéré avec succès', $agent);
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
        $slug = match ($role) {
            'AGENT' => config('roles.agent'),
            'RESPONSABLE_DE_VALIDATION' => config('roles.responsable_de_validation'),
            'MANAGER' => config('roles.manager'),
            'AUDITEUR' => config('roles.auditeur'),
            default => config('roles.client'),
        };

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
