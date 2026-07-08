<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Jobs\ResetPasswordJob;
use App\Jobs\SendOTPJob;
use App\Jobs\WelcomeAgentJob;
use App\Models\OTP;
use App\Models\User;
use App\Traits\AuthTrait;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends BaseController
{
    use AuthTrait;

    /**
     * Handle an incoming authentication request.
     */
    /**
     * @OA\Post(
     *      path="/api/admin/login",
     *      operationId="adminLogin",
     *      tags={"Admin Auth"},
     *      summary="Admin/Agent Login (Step 1: Request OTP)",
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
     *      @OA\Response(response=200, description="OTP sent to email"),
     *      @OA\Response(response=403, description="Forbidden (Not an agent)")
     * )
     */
    public function loginAdmin(LoginRequest $request): JsonResponse
    {
        // Authenticate the user
        $request->authenticate();

        // Get the authenticated user
        $user = $request->user();
        $email = $user->email;
        $user = User::where('email', $email)->first();

        $agentRoles = ['superviseur', 'auditeur', 'admin', 'tech_one', 'tech_two', 'tech_three'];

        // Check if the user has the 'agent' role
        if (! $user->hasAnyRole($agentRoles)) {
            return $this->sendError("L'email fourni n'appartient pas à un agent ou un administrateur.", null, 403);
        }

        // Generate a 6-digit OTP
        $otp = implode('', array_map(function () {
            return mt_rand(0, 9);
        }, range(1, 6)));

        // Set OTP validity to 5 minutes
        $validityMinutes = 5;
        $validUntil = Carbon::now()->addMinutes($validityMinutes);

        // Check if OTP already exists for this email
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

        // Dispatch job to send OTP via email
        SendOTPJob::dispatch($email, $otp);

        // Return success response
        return $this->sendResponse('OTP envoyé avec succès.', []);
    }

    /**
     * @OA\Post(
     *      path="/api/agents/{id}",
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
     *              @OA\Property(property="role", type="string", enum={"LEVEL1","LEVEL2","LEVEL3","SUPERVISEUR","AUDITEUR"}),
     *              @OA\Property(property="phonenumber", type="string"),
     *              @OA\Property(property="email", type="string", format="email")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Agent updated")
     * )
     */
    public function updateAgent(Request $request, $id): JsonResponse
    {
        // Find the user by ID
        $user = User::find($id);
        if (! $user) {
            return $this->sendError('Agent introuvable.', null, 404);
        }

        // Validate the request
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'string', 'in:LEVEL1,LEVEL2,LEVEL3,SUPERVISEUR,AUDITEUR'],
            'phonenumber' => ['sometimes', 'string', 'max:15'],
            'npi' => ['sometimes', 'string', 'max:10', 'unique:users,npi,'.$user->id],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ]);

        // Return validation errors
        if ($validator->fails()) {
            return $this->sendError('Format de donnée invalide.', ['errors' => $validator->errors()], 422);
        }

        try {
            // Update the user with new values
            $user->update([
                'name' => $request->input('name', $user->name),
                'email' => $request->input('email', $user->email),
                'phonenumber' => $request->input('phonenumber', $user->phonenumber),
                'npi' => $request->input('npi', $user->npi),
            ]);

            // Update the role if provided
            if ($request->has('role')) {
                $user->roles()->detach(); // Remove old roles
                $role = $request->input('role');
                switch ($role) {
                    case 'LEVEL1':
                        $user->assignRole('tech_one');
                        break;
                    case 'LEVEL2':
                        $user->assignRole('tech_two');
                        break;
                    case 'LEVEL3':
                        $user->assignRole('tech_three');
                        break;
                    case 'SUPERVISEUR':
                        $user->assignRole('superviseur');
                        break;
                    case 'AUDITEUR':
                        $user->assignRole('auditeur');
                        break;
                    default:
                        $user->assignRole('client');
                        break;
                }
            }

            return $this->sendResponse('Agent mis à jour avec succès.', $user);
        } catch (Exception $e) {
            // Log error
            Log::error('Failed to update agent: '.$e->getMessage());

            return $this->sendError('Impossible de mettre à jour l\'agent, veuillez réessayer.', null, 500);
        }
    }

    public function deleteAgent($id): JsonResponse
    {
        // Find the user by ID
        $user = User::find($id);
        if (! $user) {
            return $this->sendError('Agent introuvable.', null, 404);
        }

        try {
            // Detach roles before deleting to prevent foreign key issues (if necessary)
            $user->roles()->detach();

            // Delete the user
            $user->delete();

            return $this->sendResponse('Agent supprimé avec succès.', null);
        } catch (Exception $e) {
            // Log error
            Log::error('Failed to delete agent: '.$e->getMessage());

            return $this->sendError('Impossible de supprimer l\'agent, veuillez réessayer.', null, 500);
        }
    }

    /**
     * Destroy an authenticated session.
     */
    /**
     * @OA\Post(
     *      path="/api/admins/logout",
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
        // Revoke the token that was used to authenticate the current request
        $token = $request->user()->token();
        $token->revoke();

        // Optionally, you can delete all tokens
        // $request->user()->tokens->each(function ($token) {
        //     $token->revoke();
        // });

        return $this->sendResponse('Déconnexion réussie.', []);
    }

    /**
     * @OA\Post(
     *      path="/api/agents/register",
     *      operationId="registerAgent",
     *      tags={"Admin Auth"},
     *      summary="Register New Agent",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"name", "phonenumber", "npi", "email"},
     *
     *              @OA\Property(property="name", type="string"),
     *              @OA\Property(property="role", type="string"),
     *              @OA\Property(property="phonenumber", type="string"),
     *              @OA\Property(property="npi", type="string"),
     *              @OA\Property(property="email", type="string", format="email")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Agent registered")
     * )
     */
    public function registerAgent(Request $request): JsonResponse
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['sometimes', 'string', 'in:LEVEL1,LEVEL2,LEVEL3,SUPERVISEUR,AUDITEUR'],
            'phonenumber' => ['required', 'string', 'max:15'],
            'npi' => ['required', 'string', 'max:10', 'unique:users,npi'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        // Return validation errors
        if ($validator->fails()) {
            return $this->sendError('Format de donnée invalide.', ['errors' => $validator->errors()], 422);
        }
        try {
            // Create the user
            $user = User::create([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'phonenumber' => $request->input('phonenumber'),
                'npi' => $request->input('npi'),
                'password' => Hash::make(''),
                'status' => 'INACTIVE',
            ]);

            // Assign the role
            $role = $request->input('role');
            switch ($role) {
                case 'LEVEL1':
                    $user->assignRole('tech_one');
                    break;
                case 'LEVEL2':
                    $user->assignRole('tech_two');
                    break;
                case 'LEVEL3':
                    $user->assignRole('tech_three');
                    break;
                case 'SUPERVISEUR':
                    $user->assignRole('superviseur');
                    break;
                case 'AUDITEUR':
                    $user->assignRole('auditeur');
                    break;
                default:
                    $user->assignRole('client');
                    break;
            }

            // Générer un token unique
            $token = Str::random(60);

            // Enregistrer le token dans la table `password_reset_tokens`
            DB::table('password_reset_tokens')->insert([
                'email' => $user->email,
                'token' => Hash::make($token),
                'created_at' => now(),
            ]);

            // Envoyer un e-mail à l'utilisateur avec le lien de réinitialisation
            $resetLink = config('app.frontend_url').'/backoffice/agent/init-password?token='.$token.'&email='.urlencode($user->email);

            // Dispatch welcome email job
            WelcomeAgentJob::dispatch($user, $resetLink);

            return $this->sendResponse('Agent enregistré avec succès', $user);
        } catch (Exception $e) {
            // Log error
            Log::error('Failed to register agent: '.$e->getMessage());

            return $this->sendError('Impossible de créer le compte agent reessayer.', null, 500);
        }
    }

    public function listAgents(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('perPage', 9999999999999);
            $role = $request->get('role', null);
            $allowedRoles = ['superviseur', 'auditeur', 'tech_one', 'tech_two', 'tech_three'];

            // Check if a specific role is provided and is valid
            if ($role && in_array($role, $allowedRoles)) {
                // Query users with the specified role
                $agents = User::whereHas('roles', function ($query) use ($role) {
                    $query->where('name', $role);
                })->with('roles')->paginate($perPage);
            } else {
                // Query users with any of the allowed roles
                $agents = User::whereHas('roles', function ($query) use ($allowedRoles) {
                    $query->whereIn('name', $allowedRoles);
                })->with('roles')->paginate($perPage);
            }

            // Prepare the data without nested 'data' key to avoid duplication
            $flattenedData = $agents->toArray();
            $data = $flattenedData['data'];
            unset($flattenedData['data']);

            // Merge the remaining pagination data with the actual agent data
            $response = array_merge(['data' => $data], ['pagination' => $flattenedData]);

            // Send the paginated response
            return $this->sendPaginatedResponse('Liste des agents.', $response);
        } catch (Exception $e) {
            // Log error
            Log::error('Impossible de récupérer les agents: '.$e->getMessage());

            return $this->sendError('Impossible de récupérer la liste des agents.', null, 500);
        }
    }

    public function showAgent($id): JsonResponse
    {
        try {
            // Find user by ID
            $agent = User::whereHas('roles', function ($query) {
                $query->whereIn('name', ['superviseur', 'auditeur', 'tech_one', 'tech_two', 'tech_three']);
            })->with('roles')->findOrFail($id);

            return $this->sendResponse('Agent récupéré avec succès', $agent);
        } catch (ModelNotFoundException $e) {
            // Log error
            Log::error('Agent not found: '.$e->getMessage());

            return $this->sendError('Agent non trouvé.', null, 404);
        } catch (Exception $e) {
            // Log error
            Log::error('Failed to retrieve agent: '.$e->getMessage());

            return $this->sendError('Impossible de récupérer l\'agent, veuillez réessayer.', null, 500);
        }
    }

    public function sendOtp(Request $request)
    {
        // Validate that the email exists
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        // Retrieve the user by email
        $email = $request->input('email');
        $user = User::where('email', $email)->first();

        $agentRoles = ['superviseur', 'auditeur', 'admin', 'tech_one', 'tech_two', 'tech_three'];

        // Check if the user has the 'agent' role
        if (! $user->hasAnyRole($agentRoles)) {
            return $this->sendError("L'email fourni n'appartient pas à un agent.", null, 403);
        }

        // Generate a 6-digit OTP
        $otp = implode('', array_map(function () {
            return mt_rand(0, 9);
        }, range(1, 6)));

        // Set OTP validity to 5 minutes
        $validityMinutes = 5;
        $validUntil = Carbon::now()->addMinutes($validityMinutes);

        // Check if OTP already exists for this email
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

        // Dispatch job to send OTP via email
        SendOTPJob::dispatch($email, $otp);

        // Return success response
        return $this->sendResponse('OTP envoyé avec succès.', []);
    }

    /**
     * @OA\Post(
     *      path="/api/admins/verify-otp",
     *      operationId="adminVerifyOtp",
     *      tags={"Admin Auth"},
     *      summary="Admin/Agent Verify OTP (Step 2: Get Token)",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"email", "otp"},
     *
     *              @OA\Property(property="email", type="string", format="email"),
     *              @OA\Property(property="otp", type="string")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Token obtained"),
     *      @OA\Response(response=403, description="Forbidden or Invalid OTP")
     * )
     */
    public function verifyOtp(Request $request)
    {
        // Validate the input for email and OTP
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string',
        ]);

        $email = $request->input('email');
        $otp = $request->input('otp');

        // Check if the user exists
        $user = User::where('email', $email)->first();

        $agentRoles = ['superviseur', 'auditeur', 'admin', 'tech_one', 'tech_two', 'tech_three'];

        // Check if the user has the 'agent' role
        if (! $user->hasAnyRole($agentRoles)) {
            return $this->sendError("L'email fourni n'appartient pas à un agent.", null, 403);
        }

        // Verify if the OTP exists and is still valid
        $existingOTP = OTP::where('email', $email)
            ->where('otp', $otp)
            ->where('valid_until', '>=', Carbon::now())
            ->first();

        if ($existingOTP) {
            // Optionally, you can delete the OTP from the database after verification
            $existingOTP->delete();

            // Generate a token for the user
            $token = $user->createToken($user->email.'-'.now())->plainTextToken;
            $roles = $user->getRoleNames(); // Get user roles

            // Prepare the response data
            $data = [
                'user' => $user,
                'roles' => $roles,
                'access_token' => $token,
            ];

            return $this->sendResponse("Bienvenue sur la plateforme d'enregistrement déléguée! Vous nous avez manqué!", $data);
        }

        return $this->sendError('OTP invalide ou expiré.', null, 403);
    }

    public function resetPassword(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'token' => 'required',
                'email' => 'required|email',
                'password' => 'required|confirmed|min:8',
            ]);

            // Rechercher le token dans la base de données
            $record = DB::table('password_reset_tokens')
                ->where('email', $validatedData['email'])
                ->first();

            if (! $record || ! Hash::check($validatedData['token'], $record->token)) {
                return $this->sendError('Token invalide ou expiré.', null, 400);
            }

            // Mettre à jour le mot de passe de l'utilisateur
            $user = User::where('email', $validatedData['email'])->first();
            $user->password = Hash::make($validatedData['password']);
            $user->status = 'ACTIVE';
            $user->save();

            // Supprimer le token après utilisation
            DB::table('password_reset_tokens')->where('email', $validatedData['email'])->delete();

            return $this->sendResponse('Mot de passe réinitialisé avec succès.', []);
        } catch (Exception $e) {
            return $this->sendError('Impossible de mettre à jour le mot de passe', null, 401);
        }
    }

    public function sendPasswordResetLink(Request $request): JsonResponse
    {
        // Valider l'email
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'exists:users,email'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Email invalide ou inexistant.', ['errors' => $validator->errors()], 422);
        }

        $agentRoles = ['superviseur', 'auditeur', 'admin', 'tech_one', 'tech_two', 'tech_three'];

        try {
            // Récupérer l'utilisateur
            $user = User::where('email', $request->input('email'))->first();

            if ($user->hasAnyRole($agentRoles)) {
                // Générer un token unique
                $token = Str::random(60);

                DB::table('password_reset_tokens')->updateOrInsert(
                    ['email' => $user->email], // Critère de correspondance
                    [
                        'token' => Hash::make($token), // Valeurs à insérer ou mettre à jour
                        'created_at' => now(),
                    ]
                );

                // Générer le lien de réinitialisation
                $resetLink = config('app.frontend_url').'/reset-password/'.$token.'/'.urlencode($user->email);

                // Envoyer l'e-mail
                ResetPasswordJob::dispatch($user, $resetLink);

                return $this->sendResponse('Lien de réinitialisation envoyé avec succès.', null);
            } else {
                return $this->sendError('Vous ne disposez d\'aucun des privilièges requis pour la mise à jour du mot de passe sur cette interface', null, 403);
            }
        } catch (Exception $e) {
            Log::error('Failed to send password reset link: '.$e->getMessage());

            return $this->sendError('Impossible d\'envoyer le lien de réinitialisation, réessayer.', null, 400);
        }
    }
}
