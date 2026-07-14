<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\ApproveInPersonIdentityRequest;
use App\Http\Requests\User\CreateEmployeeRequest;
use App\Http\Requests\User\FinalizeRegistrationRequest;
use App\Http\Requests\User\LoginWithCodeRequest;
use App\Http\Requests\User\SendOtpRequest;
use App\Http\Requests\User\UpdateIdentityStatusRequest;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Http\Requests\User\VerifyOtpRequest;
use App\Jobs\WelcomeUserJob;
use App\Models\Identity;
use App\Models\PasswordResetToken;
use App\Models\StructureInvitation;
use App\Models\User;
use App\Services\Registration\UserRegistrationService;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends BaseController
{
    public function __construct(
        private readonly UserRegistrationService $registration,
    ) {}

    /**
     * @OA\Post(
     *      path="/api/v1/clients/send-otp",
     *      operationId="userSendOtp",
     *      tags={"User Auth"},
     *      summary="Send OTP to User (ANIP flow)",
     *      description="Generates and sends an OTP to a citizen based on their NPI.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"npi"},
     *
     *              @OA\Property(property="npi", type="string", example="1234567890")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="OTP sent successfully"),
     *      @OA\Response(response=404, description="NPI not found"),
     *      @OA\Response(response=422, description="Validation error")
     * )
     */
    public function sendOtp(SendOtpRequest $request)
    {
        return $this->respond($this->registration->sendOtp($request->input('npi')));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/clients/verify-otp",
     *      operationId="userVerifyOtp",
     *      tags={"User Auth"},
     *      summary="Verify User OTP",
     *      description="Verifies the OTP sent to the citizen.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"npi", "otp"},
     *
     *              @OA\Property(property="npi", type="string"),
     *              @OA\Property(property="otp", type="string")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="OTP verified"),
     *      @OA\Response(response=400, description="Invalid or expired OTP")
     * )
     */
    public function verifyOtp(VerifyOtpRequest $request)
    {
        return $this->respond($this->registration->verifyOtp(
            $request->input('npi'),
            $request->input('otp'),
        ));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/clients/login",
     *      operationId="userLogin",
     *      tags={"User Auth"},
     *      summary="User Login",
     *      description="Login via TrustedX authorization code.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"code"},
     *
     *              @OA\Property(property="code", type="string")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Logged in successfully"),
     *      @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function login(LoginWithCodeRequest $request)
    {
        return $this->respond($this->registration->login($request->input('code')));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/mobile/login",
     *      operationId="userMobileLogin",
     *      tags={"User Auth"},
     *      summary="User Mobile Login",
     *      description="Mobile login via TrustedX authorization code.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"code"},
     *
     *              @OA\Property(property="code", type="string")
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Logged in successfully"),
     *      @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function loginMobile(LoginWithCodeRequest $request)
    {
        return $this->respond($this->registration->loginMobile($request->input('code')));
    }

    /**
     * @OA\Get(
     *      path="/api/v1/users/{id}",
     *      operationId="getUser",
     *      tags={"Users"},
     *      summary="Get User Details",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\Response(response=200, description="Successful operation"),
     *      @OA\Response(response=404, description="User not found")
     * )
     */
    public function show($id)
    {
        try {
            $user = User::findOrFail($id);

            return $this->sendResponse('Utilisateur récupéré.', $user);
        } catch (Exception $e) {
            Log::error('Fetching user failed: '.$e->getMessage());

            return $this->sendError('Fetching user failed.', null, 500);
        }
    }

    /**
     * @OA\Get(
     *      path="/api/v1/users/search",
     *      operationId="searchUsers",
     *      tags={"Users"},
     *      summary="Search Users",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="query", in="query", required=true, @OA\Schema(type="string")),
     *      @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer")),
     *
     *      @OA\Response(response=200, description="Successful operation")
     * )
     */
    public function search(Request $request)
    {
        try {
            $query = $request->input('query');
            $limit = (int) $request->get('limit', 10);

            $users = User::where(function ($q) use ($query) {
                $q->where('email', 'LIKE', "%$query%")
                    ->orWhere('name', 'LIKE', "%$query%")
                    ->orWhere('npi', 'LIKE', "%$query%");
            })
                ->where('id', '!=', auth()->id())
                ->limit($limit)
                ->get();

            return $this->sendResponse('Résultats de recherche.', $users);
        } catch (Exception $e) {
            Log::error('Searching users failed: '.$e->getMessage());

            return $this->sendError('Searching users failed.', null, 500);
        }
    }

    public function searchPost(Request $request)
    {
        try {
            $query = $request->input('email');

            $users = User::where('email', 'LIKE', "%$query%")
                ->where('id', '!=', auth()->id())
                ->get();

            return $this->sendResponse('Résultats de recherche.', $users);
        } catch (Exception $e) {
            Log::error('Searching users failed: '.$e->getMessage());

            return $this->sendError('Searching users failed.', null, 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/v1/users/{id}",
     *      operationId="updateUser",
     *      tags={"Users"},
     *      summary="Update User Profile",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *
     *              @OA\Schema(
     *
     *                  @OA\Property(property="email", type="string"),
     *                  @OA\Property(property="profile", type="string", format="binary")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Profile updated successfully"),
     *      @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'profile' => 'nullable|mimes:png,jpeg,jpg|max:2048',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('users')->ignore($id),
                ],
            ]);

            $updateData = [
                'email' => $request->input('email'),
            ];
            $profile = $request->file('profile');

            $result = $this->registration->updateUser((int) $id, $updateData, $profile);

            if (! $result->success) {
                return $this->sendError($result->message, $result->data ?? [], $result->code);
            }

            return $this->sendResponse($result->message, $result->data ?? []);
        } catch (ValidationException $e) {
            Log::warning("Erreur de validation lors de la mise à jour de l'utilisateur : ", $e->errors());

            return $this->sendError('Erreur de validation.', $e->errors(), 422);
        } catch (Exception $e) {
            Log::error("Mise à jour de l'utilisateur échouée : ".$e->getMessage());

            return $this->sendError('Une erreur est survenue lors de la mise à jour de vos informations.', null, 500);
        }
    }

    /**
     * @OA\Delete(
     *      path="/api/v1/users/{id}",
     *      operationId="deleteUser",
     *      tags={"Users"},
     *      summary="Delete User",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *      @OA\Response(response=200, description="Deleted successfully")
     * )
     */
    public function destroy($id)
    {
        try {
            $user = User::findOrFail($id);
            $user->delete();

            return $this->sendResponse('User deleted successfully.', []);
        } catch (Exception $e) {
            Log::error('Deleting user failed: '.$e->getMessage());

            return $this->sendError('Deleting user failed.', null, 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/v1/management/users/update-status",
     *      operationId="updateUserStatus",
     *      tags={"Management"},
     *      summary="Update User Status (Bulk)",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"users"},
     *
     *              @OA\Property(property="users", type="array", @OA\Items(
     *                  @OA\Property(property="id", type="integer"),
     *                  @OA\Property(property="status", type="string", enum={"ACTIVE", "INACTIVE"})
     *              ))
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Statut mis à jour")
     * )
     */
    public function updateUserStatus(UpdateUserStatusRequest $request)
    {
        $users = $request->input('users');

        try {
            DB::transaction(function () use ($users) {
                foreach ($users as $userData) {
                    $user = User::findOrFail($userData['id']);
                    $status = $userData['status'];

                    $user->update([
                        'status' => $status,
                    ]);
                }
            });

            return $this->sendResponse("Le statut de l'utilisateur à bien été mis à jour", $users);
        } catch (Exception $e) {
            Log::error('Failed to update user statuses: '.$e->getMessage());

            return $this->sendError('Failed to update user statuses.', null, 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/v1/management/users/identity-status",
     *      operationId="updateIdentityStatus",
     *      tags={"Management"},
     *      summary="Update Identity Status",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"id", "status"},
     *
     *              @OA\Property(property="id", type="integer"),
     *              @OA\Property(property="status", type="string", enum={"APPROVED", "WAITING_MANAGER", "REJECTED", "PENDING"})
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Statut de l'identité mis à jour")
     * )
     */
    public function updateIdentityStatus(UpdateIdentityStatusRequest $request)
    {
        $identityPayload = $request->all();

        try {
            DB::transaction(function () use ($identityPayload) {
                $identity = Identity::findOrFail($identityPayload['id']);
                $status = $identityPayload['status'];

                $identity->update(['status' => $status]);

                if ($status === 'APPROVED') {
                    $user = $identity->user;
                    $allToken = Str::random(60);
                    PasswordResetToken::updateOrCreate(
                        ['npi' => $user->npi, 'type' => 'all'],
                        ['token' => $allToken, 'created_at' => Carbon::now(), 'type' => 'all']
                    );
                    $link = config('app.frontend_url')."/init-account/all/$allToken/$user->npi";
                    WelcomeUserJob::dispatch($user->email, $user, $link, true);
                }
            });

            return $this->sendResponse("Le statut de l'identité à bien été mis à jour", $identityPayload);
        } catch (Exception $e) {
            Log::error('Failed to update identity status: '.$e->getMessage());

            return $this->sendError('Failed to update identity status.', null, 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/v1/identity/approve",
     *      operationId="updateInPersonIdentityStatus",
     *      tags={"Management"},
     *      summary="Approve In-Person Identity",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *
     *              @OA\Schema(
     *                  required={"id", "status"},
     *
     *                  @OA\Property(property="id", type="integer"),
     *                  @OA\Property(property="status", type="string", enum={"APPROVED", "WAITING_MANAGER", "REJECTED", "PENDING"}),
     *                  @OA\Property(property="user_id", type="integer"),
     *                  @OA\Property(property="selfie", type="string", format="binary"),
     *                  @OA\Property(property="recto", type="string", format="binary"),
     *                  @OA\Property(property="verso", type="string", format="binary"),
     *                  @OA\Property(property="exp_date", type="string"),
     *                  @OA\Property(property="birth_date", type="string")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Identité mise à jour")
     * )
     */
    public function updateInPersonIdentityStatus(ApproveInPersonIdentityRequest $request)
    {
        return $this->respond($this->registration->approveInPersonIdentity($request->all(), $request));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/clients/set-password",
     *      operationId="setUserPassword",
     *      tags={"User Auth"},
     *      summary="Set User Password/Pin",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"password", "npi", "type"},
     *
     *              @OA\Property(property="password", type="string"),
     *              @OA\Property(property="npi", type="string"),
     *              @OA\Property(property="type", type="string", enum={"password", "pin"})
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Mot de passe mis à jour")
     * )
     */
    public function setPassword(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
            'npi' => 'required|string',
            'type' => 'required|string|in:password,pin',
        ]);

        return $this->respond($this->registration->setPassword(
            $request->input('npi'),
            $request->input('password'),
            $request->input('type'),
        ));
    }

    public function sendResetLink(string $npi, string $type)
    {
        return $this->respond($this->registration->sendResetLink($npi, $type));
    }

    /**
     * @OA\Post(
     *      path="/api/v1/finalize-registration",
     *      operationId="finalizeCitizenRegistration",
     *      tags={"Registration"},
     *      summary="Finalize Citizen Registration (ANIP flow)",
     *      description="Finalizes registration for citizens using NPI.",
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *
     *              @OA\Schema(
     *                  required={"registration_token", "transaction_id", "type", "level"},
     *
     *                  @OA\Property(property="registration_token", type="string"),
     *                  @OA\Property(property="transaction_id", type="string"),
     *                  @OA\Property(property="type", type="string", enum={"IN_PERSON", "ONLINE"}),
     *                  @OA\Property(property="level", type="string", enum={"SIMPLE", "ADVANCED"}),
     *                  @OA\Property(property="password", type="string", description="Required for non-foreigners"),
     *                  @OA\Property(property="pin", type="string", description="Required for non-foreigners"),
     *                  @OA\Property(property="selfie", type="string", format="binary"),
     *                  @OA\Property(property="recto", type="string", format="binary"),
     *                  @OA\Property(property="verso", type="string", format="binary"),
     *                  @OA\Property(property="similarity", type="string"),
     *                  @OA\Property(property="liveness", type="string")
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Registration success"),
     *      @OA\Response(response=422, description="Validation error")
     * )
     */
    public function finalizeRegistration(FinalizeRegistrationRequest $request)
    {
        $pendingRegistration = $request->pendingRegistration();
        if ($pendingRegistration === null) {
            return $this->sendError('Données invalides.', null, 422);
        }

        return $this->respond($this->registration->finalizeRegistration($request, $pendingRegistration));
    }

    /**
     * @OA\Get(
     *      path="/api/v1/invitations",
     *      operationId="listInvitations",
     *      tags={"Users"},
     *      summary="List user invitations",
     *      description="Returns a list of invitations for the authenticated user.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Response(
     *          response=200,
     *          description="Successful operation",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *          )
     *      )
     * )
     */
    public function listInvitations(Request $request)
    {
        try {
            $user = $request->user();

            // Récupérer les invitations de l'utilisateur
            $invitations = StructureInvitation::with(['structure', 'inviter'])
                ->where('user_id', $user->id)
                ->where('status', 'PENDING')
                ->where('expires_at', '>', Carbon::now())
                ->get()
                ->map(function ($invitation) {
                    return [
                        'id' => $invitation->id,
                        'structure' => [
                            'id' => $invitation->structure->id,
                            'name' => $invitation->structure->name,
                            'manager' => $invitation->structure->manager->name ?? 'N/A',
                        ],
                        'inviter' => $invitation->inviter->name ?? 'N/A',
                        'role' => $invitation->role,
                        'expires_at' => $invitation->expires_at,
                        'message' => $invitation->message,
                        'invitation_url' => url("/api/v1/invitations/{$invitation->token}/details"),
                    ];
                });

            return $this->sendResponse('Vos invitations récupérées avec succès.', $invitations);
        } catch (Exception $e) {
            Log::error('Erreur lors de la récupération des invitations : '.$e->getMessage());

            return $this->sendError('Impossible de récupérer vos invitations.', null, 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/v1/invitations/{invitation}/accept",
     *      operationId="acceptInvitation",
     *      tags={"Users"},
     *      summary="Accept an invitation",
     *      description="Accepts a structure invitation.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *          name="invitation",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Invitation accepted"
     *      ),
     *      @OA\Response(response=404, description="Invitation not found"),
     *      @OA\Response(response=400, description="Invitation expired or already processed")
     * )
     */
    public function acceptInvitation(Request $request, $invitationId)
    {
        $result = $this->registration->acceptInvitation((int) $invitationId, $request->user());

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }

    /**
     * @OA\Post(
     *      path="/api/v1/invitations/{invitation}/reject",
     *      operationId="rejectInvitation",
     *      tags={"Users"},
     *      summary="Reject an invitation",
     *      description="Rejects a structure invitation.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\Parameter(
     *          name="invitation",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="integer")
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Invitation rejected"
     *      ),
     *      @OA\Response(response=404, description="Invitation not found"),
     *      @OA\Response(response=400, description="Invitation expired or already processed")
     * )
     */
    public function rejectInvitation(Request $request, $invitationId)
    {
        try {
            $user = $request->user();

            $invitation = StructureInvitation::where('id', $invitationId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Vérifier que l'invitation est encore valide
            if ($invitation->status !== 'PENDING') {
                return $this->sendError('Cette invitation a déjà été traitée.', null, 400);
            }

            // Marquer l'invitation comme rejetée
            $invitation->reject();

            return $this->sendResponse('Invitation refusée avec succès.', [
                'structure' => $invitation->structure->only(['id', 'name']),
            ]);

        } catch (ModelNotFoundException $e) {
            return $this->sendError('Invitation non trouvée.', null, 404);
        } catch (Exception $e) {
            Log::error('Erreur lors du rejet de l\'invitation : '.$e->getMessage());

            return $this->sendError('Impossible de refuser l\'invitation.', null, 500);
        }
    }

    /**
     * @OA\Get(
     *      path="/api/v1/invitations/{token}/details",
     *      operationId="getInvitationDetails",
     *      tags={"Users"},
     *      summary="Get invitation details by token",
     *      description="Returns invitation details using the invitation token (public route).",
     *
     *      @OA\Parameter(
     *          name="token",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="string")
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Invitation details retrieved"
     *      ),
     *      @OA\Response(response=404, description="Invitation not found or expired")
     * )
     */
    public function getInvitationDetails($token)
    {
        try {
            $invitation = StructureInvitation::with(['structure', 'inviter'])
                ->where('token', $token)
                ->firstOrFail();

            // Vérifier que l'invitation est encore valide
            if (! $invitation->isPending()) {
                return $this->sendError('Cette invitation n\'est plus valide.', [
                    'status' => $invitation->status,
                    'expired' => $invitation->isExpired(),
                ], 400);
            }

            $data = [
                'id' => $invitation->id,
                'structure' => [
                    'id' => $invitation->structure->id,
                    'name' => $invitation->structure->name,
                    'manager' => $invitation->structure->manager->name ?? 'N/A',
                ],
                'inviter' => $invitation->inviter->name ?? 'N/A',
                'role' => $invitation->role,
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at,
                'message' => $invitation->message,
            ];

            return $this->sendResponse('Détails de l\'invitation récupérés.', $data);

        } catch (ModelNotFoundException $e) {
            return $this->sendError('Invitation non trouvée.', null, 404);
        } catch (Exception $e) {
            Log::error('Erreur lors de la récupération des détails de l\'invitation : '.$e->getMessage());

            return $this->sendError('Impossible de récupérer les détails de l\'invitation.', null, 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/v1/invitations/{token}/respond",
     *      operationId="respondToInvitation",
     *      tags={"Users"},
     *      summary="Respond to invitation by token",
     *      description="Accepts or rejects an invitation using the token (public route).",
     *
     *      @OA\Parameter(
     *          name="token",
     *          in="path",
     *          required=true,
     *
     *          @OA\Schema(type="string")
     *      ),
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"action"},
     *
     *              @OA\Property(
     *                  property="action",
     *                  type="string",
     *                  enum={"accept", "reject"},
     *                  example="accept"
     *              )
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Response processed"
     *      ),
     *      @OA\Response(response=404, description="Invitation not found"),
     *      @OA\Response(response=400, description="Invalid action or invitation expired")
     * )
     */
    public function respondToInvitation(Request $request, $token)
    {
        $validatedData = $request->validate([
            'action' => 'required|string|in:accept,reject',
        ]);

        $result = $this->registration->respondToInvitation($token, $validatedData['action']);

        if (! $result->success) {
            return $this->sendError($result->message, $result->data ?? [], $result->code);
        }

        return $this->sendResponse($result->message, $result->data ?? []);
    }

    /**
     * @OA\Post(
     *      path="/api/v1/employees/create",
     *      operationId="createEmployee",
     *      tags={"Users"},
     *      summary="Create a new employee user",
     *      description="Creates a new employee user and sends invitation to join structure.",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              required={"email", "structure_id"},
     *
     *              @OA\Property(property="email", type="string", format="email"),
     *              @OA\Property(property="structure_id", type="integer"),
     *              @OA\Property(property="name", type="string"),
     *              @OA\Property(property="phone", type="string"),
     *              @OA\Property(property="role", type="string", enum={"EMPLOYEE", "MANAGER_ASSISTANT", "VIEWER"}),
     *              @OA\Property(property="message", type="string", max=500)
     *          )
     *      ),
     *
     *      @OA\Response(response=200, description="Employee created and invitation sent"),
     *      @OA\Response(response=403, description="Forbidden"),
     *      @OA\Response(response=422, description="Validation error")
     * )
     */
    public function createEmployee(CreateEmployeeRequest $request)
    {
        return $this->respond($this->registration->createEmployee(
            $request->validated(),
            $request->user(),
        ));
    }
}
