<?php

namespace App\Services\Auth;

use App\Jobs\ResetPasswordJob;
use App\Jobs\SendOTPJob;
use App\Jobs\WelcomeAgentJob;
use App\Models\OTP;
use App\Models\User;
use App\Services\ServiceResult;
use Carbon\Carbon;
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

    public function issueOtpAfterLogin(User $user): ServiceResult
    {
        if (! $user->hasAnyRole(self::staffRoles())) {
            return ServiceResult::fail("L'email fourni n'appartient pas à un agent ou un administrateur.", null, 403);
        }

        $this->storeAndDispatchOtp($user->email);

        return ServiceResult::ok('OTP envoyé avec succès.', []);
    }

    public function sendOtp(string $email): ServiceResult
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->hasAnyRole(self::staffRoles())) {
            return ServiceResult::fail("L'email fourni n'appartient pas à un agent.", null, 403);
        }

        $this->storeAndDispatchOtp($email);

        return ServiceResult::ok('OTP envoyé avec succès.', []);
    }

    public function verifyOtp(string $email, string $otp): ServiceResult
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->hasAnyRole(self::staffRoles())) {
            return ServiceResult::fail("L'email fourni n'appartient pas à un agent.", null, 403);
        }

        $existingOTP = OTP::where('email', $email)
            ->where('otp', $otp)
            ->where('valid_until', '>=', Carbon::now())
            ->first();

        if (! $existingOTP) {
            return ServiceResult::fail('OTP invalide ou expiré.', null, 403);
        }

        $existingOTP->delete();

        $token = $user->createToken($user->email.'-'.now())->plainTextToken;

        return ServiceResult::ok(
            "Bienvenue sur la plateforme d'enregistrement déléguée! Vous nous avez manqué!",
            [
                'user' => $user,
                'roles' => $user->getRoleNames(),
                'access_token' => $token,
            ]
        );
    }

    public function updateAgent(User $user, array $input): ServiceResult
    {
        try {
            $user->update([
                'name' => $input['name'] ?? $user->name,
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
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'phonenumber' => $input['phonenumber'],
                'npi' => $input['npi'],
                'password' => Hash::make(''),
                'status' => 'INACTIVE',
            ]);

            $this->assignRoleFromCode($user, $input['role'] ?? null);

            $token = Str::random(60);

            DB::table('password_reset_tokens')->insert([
                'email' => $user->email,
                'token' => Hash::make($token),
                'created_at' => now(),
            ]);

            $resetLink = config('app.frontend_url').'/backoffice/agent/init-password?token='.$token.'&email='.urlencode($user->email);
            WelcomeAgentJob::dispatch($user, $resetLink);

            return ServiceResult::ok('Agent enregistré avec succès', $user);
        } catch (Exception $e) {
            Log::error('Failed to register agent: '.$e->getMessage());

            return ServiceResult::fail('Impossible de créer le compte agent reessayer.', null, 500);
        }
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

    public function showAgent(int $id): ServiceResult
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

    private function storeAndDispatchOtp(string $email): void
    {
        $otp = implode('', array_map(fn () => (string) mt_rand(0, 9), range(1, 6)));
        $validUntil = Carbon::now()->addMinutes(5);

        $existingOTP = OTP::where('email', $email)->first();
        if ($existingOTP) {
            $existingOTP->update(['otp' => $otp, 'valid_until' => $validUntil]);
        } else {
            OTP::create([
                'email' => $email,
                'otp' => $otp,
                'valid_until' => $validUntil,
            ]);
        }

        SendOTPJob::dispatch($email, $otp);
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
