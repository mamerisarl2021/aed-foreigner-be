<?php

namespace App\Http\Controllers;

use App\Jobs\AdvancedIdRequestJob;
use App\Jobs\PlanifiedEmailJob;
use App\Jobs\SendInitLinkJob;
use App\Jobs\SendOTPJob;
use App\Jobs\SendStructureInvitationEmail;
use App\Jobs\WelcomeUserJob;
use App\Models\Identity;
use App\Models\OTP;
use App\Models\PendingRegistration;
use App\Models\Structure;
use App\Models\StructureInvitation;
use App\Models\User;
use App\Rules\UniqueTypePerUser;
use App\Traits\AuthTrait;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends BaseController
{
    use AuthTrait;

    /**
     * @OA\Post(
     *      path="/api/clients/send-otp",
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
    public function sendOtp(Request $request)
    {
        $request->validate([
            'npi' => 'required|unique:users',
        ]);
        $npi = $request->input('npi');

        $validityMinutes = 5;
        $otp = implode('', array_map(function () {
            return mt_rand(0, 9);
        }, range(1, 6)));

        $validUntil = Carbon::now()->addMinutes($validityMinutes);
        $existingOTP = OTP::where('npi', $npi)
            ->first();
        if ($existingOTP) {
            $existingOTP->update(['otp' => $otp, 'valid_until' => $validUntil]);
        } else {
            OTP::create([
                'npi' => $npi,
                'otp' => $otp,
                'valid_until' => $validUntil,
            ]);
        }

        $anipData = $this->getUserData($npi);
        if ($anipData['status']) {
            $phoneNumber = $anipData['data']['phonenumber'];
            $email = $anipData['data']['email'];
            // SendSmsJob::dispatch($phoneNumber, "Votre code OTP pour poursuivre votre inscription est le suivant: $otp");
            // dd($phoneNumber);
            // SendOTPJob::dispatch('anagoarmandine@gmail.com', $otp);
            SendOTPJob::dispatch($email, $otp);
            Cache::put('user_'.$npi, [
                'data' => $anipData,
            ], 600);
        } else {
            Log::error('NPI inexistant');

            return $this->sendError('Le numéro personnel d\'identification renseigné n\'existe pas dans la base de donnée de l\'ANIP vérifiez bien qu\'il s\'agit du bon numéro et reéssayez.', null, 404);
        }

        return $this->sendResponse(
            'Un code OTP vous a été envoyé par e-mail. Il expire dans 5 minutes.'
        );
    }

    /**
     * @OA\Post(
     *      path="/api/clients/verify-otp",
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
    public function verifyOtp(Request $request)
    {
        try {
            $request->validate([
                'npi' => 'required|string',
                'otp' => 'required|string',
            ]);

            $npi = $request->input('npi');
            $otp = $request->input('otp');

            $existingOTP = OTP::where('npi', $npi)
                ->where('otp', $otp)
                ->where('valid_until', '>=', Carbon::now())
                ->first();

            if (! $existingOTP) {
                return $this->sendError('OTP invalide ou expiré.', null, 400);
            }

            $cachedData = Cache::get('user_'.$npi);

            if (! $cachedData) {
                return $this->sendError("Le code OTP n'est plus valide veuillez réessayer", null, 404);
            }

            $ttlSeconds = Carbon::now()->diffInSeconds(Carbon::parse($existingOTP->valid_until));
            Cache::put('user_'.$npi.'_validate_otp', true, $ttlSeconds > 0 ? $ttlSeconds : 300);

            return $this->sendResponse(
                'OTP valide.',
                $cachedData['data']
            );
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), null, 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/clients/login",
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
    public function login(Request $request)
    {
        $this->validate($request, ['code' => 'required']);
        $code = $request->input('code');
        $response = $this->userInfo($code);

        return $response['status'] ?
            $this->sendResponse('Token obtenu avec succès!', $response['data']) :
            $this->sendError($response['message'], null, 401);
    }

    /**
     * @OA\Post(
     *      path="/api/mobile/login",
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
    public function loginMobile(Request $request)
    {
        $this->validate($request, ['code' => 'required']);
        $code = $request->input('code');
        $response = $this->mobileUserInfo($code);

        return $response['status'] ?
            $this->sendResponse('Token obtenu avec succès!', $response['data']) :
            $this->sendError($response['message'], null, 401);
    }

    /**
     * @OA\Get(
     *      path="/api/users/{id}",
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
     *      path="/api/users/search",
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
     *      path="/api/users/{id}",
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

            DB::beginTransaction();

            $user = User::findOrFail($id);

            // Initialiser les données à mettre à jour
            $updateData = [
                'email' => $request->input('email'),
            ];

            // Gestion de l'upload de l'image de profil si fournie
            if ($request->hasFile('profile')) {
                $profilePath = Storage::cloud()->put('images', $request->file('profile'));
                if (! $profilePath) {
                    return $this->sendError("Échec du téléchargement de l'image.", null, 500);
                }
                $updateData['profile'] = $profilePath;
            }

            // Mettre à jour les données de l'utilisateur
            $user->update($updateData);

            DB::commit();

            return $this->sendResponse('Vos informations ont bien été mises à jour!', $user->load([
                'cases',
                'structures',
                'userSubscriptions',
                'identities',
                'signatures',
            ]));
        } catch (ValidationException $e) {
            DB::rollBack();
            Log::warning("Erreur de validation lors de la mise à jour de l'utilisateur : ", $e->errors());

            return $this->sendError('Erreur de validation.', $e->errors(), 422);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Mise à jour de l'utilisateur échouée : ".$e->getMessage());

            return $this->sendError('Une erreur est survenue lors de la mise à jour de vos informations.', null, 500);
        }
    }

    /**
     * @OA\Delete(
     *      path="/api/users/{id}",
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
     *      path="/api/management/users/update-status",
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
    public function updateUserStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'users' => 'required|array',
            'users.*.id' => 'required|integer|exists:users,id',
            'users.*.status' => 'required|in:ACTIVE,INACTIVE',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Erreur de validation des données', $validator->errors(), 400);
        }

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
     *      path="/api/management/users/identity-status",
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
    public function updateIdentityStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer|exists:identities,id',
            'status' => 'required|in:APPROVED,WAITING_MANAGER,REJECTED,PENDING',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Erreur de validation des données', $validator->errors(), 400);
        }

        $identityPayload = $request->all();

        try {
            DB::transaction(function () use ($identityPayload) {
                $identity = Identity::findOrFail($identityPayload['id']);
                $status = $identityPayload['status'];

                $identity->update(['status' => $status]);

                if ($status === 'APPROVED') {
                    $user = $identity->user;
                    $allToken = Str::random(60);
                    DB::table('password_resets')->updateOrInsert(
                        ['npi' => $user->npi, 'type' => 'all'],
                        ['token' => $allToken, 'created_at' => Carbon::now(), 'type' => 'all']
                    );
                    $link = env('FRONT_URL')."/init-account/all/$allToken/$user->npi";
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
     *      path="/api/identity/approve",
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
    public function updateInPersonIdentityStatus(Request $request)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'exp_date' => 'nullable|string',
                'birth_date' => 'nullable|string',
                'selfie' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:6508',
                'recto' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:2048',
                'verso' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:2048',
                'user_id' => 'sometimes|integer|exists:users,id',
                'type' => 'nullable|string|in:IN_PERSON,ONLINE',
                'id' => 'required|integer|exists:identities,id',
                'status' => 'required|in:APPROVED,WAITING_MANAGER,REJECTED,PENDING',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Erreur de validation des données', $validator->errors(), 400);
            }

            $identityPayload = $request->all();
            $userData = [
                'data' => [
                    'npi' => User::findOrFail($identityPayload['user_id'])->npi,
                ],
            ];

            try {
                if ($identityPayload['status'] == 'APPROVED') {
                    DB::transaction(function () use ($identityPayload, $userData, $request) {
                        $output = $this->register($userData);
                        Log::info('Output from register: ', $output);

                        if ($output['status']) {
                            $selfiePath = $request->file('selfie') ? Storage::cloud()->put('selfies', $request->file('selfie')) : null;
                            $rectoPath = $request->file('recto') ? Storage::cloud()->put('images', $request->file('recto')) : null;
                            $versoPath = $request->file('verso') ? Storage::cloud()->put('images', $request->file('verso')) : null;

                            $exp_date = $request->input('exp_date') ? Carbon::parse($request->input('exp_date'))->format('Y-m-d') : null;
                            $birth_date = $request->input('birth_date') ? Carbon::parse($request->input('birth_date'))->format('Y-m-d') : null;

                            $identity = Identity::findOrFail($identityPayload['id']);

                            $identity->update([
                                'proof' => json_encode([
                                    'selfiePath' => $selfiePath,
                                    'rectoPath' => $rectoPath,
                                    'versoPath' => $versoPath,
                                    'exp_date' => $exp_date,
                                    'birth_date' => $birth_date,
                                ]),
                                'status' => $identityPayload['user_id'] == null ? 'PENDING' : $identityPayload['status'],
                            ]);

                            $email = optional(User::find($request->input('user_id')))->email;
                            $npi = optional(User::find($request->input('user_id')))->npi;

                            if (isset($output['has_user']) && $output['has_user'] === true) {
                                $allToken = Str::random(60);
                                DB::table('password_resets')->updateOrInsert(
                                    ['npi' => $npi, 'type' => 'all'],
                                    ['token' => $allToken, 'created_at' => Carbon::now(), 'type' => 'all']
                                );
                                $link = env('FRONT_URL')."/init-account/all/$allToken/$npi";
                                WelcomeUserJob::dispatch($email, User::findOrFail($identityPayload['user_id']), $link, true);
                            } else {
                                $allToken = Str::random(60);
                                $pinToken = Str::random(60);
                                $passwordToken = Str::random(60);

                                DB::table('password_resets')->updateOrInsert(
                                    ['npi' => $npi, 'type' => 'pin'],
                                    ['token' => $pinToken, 'created_at' => Carbon::now(), 'type' => 'pin']
                                );
                                DB::table('password_resets')->updateOrInsert(
                                    ['npi' => $npi, 'type' => 'password'],
                                    ['token' => $passwordToken, 'created_at' => Carbon::now(), 'type' => 'password']
                                );
                                DB::table('password_resets')->updateOrInsert(
                                    ['npi' => $npi, 'type' => 'all'],
                                    ['token' => $allToken, 'created_at' => Carbon::now(), 'type' => 'all']
                                );

                                $link = env('FRONT_URL')."/init-account/none/$pinToken/$passwordToken/$allToken/$npi";
                                WelcomeUserJob::dispatch($email, User::findOrFail($identityPayload['user_id']), $link, true);
                            }
                        } else {
                            return $this->sendError($output['message'], $output, 400);
                        }
                    });
                } else {
                    $identity = Identity::findOrFail($identityPayload['id']);
                    $identity->update([
                        'status' => $identityPayload['user_id'] == null ? 'PENDING' : $identityPayload['status'],
                    ]);
                }
                DB::commit();

                return $this->sendResponse("Le statut de l'identité à bien été mis à jour", $identityPayload);
            } catch (Exception $e) {
                Log::error('Failed to update identity status: '.$e->getMessage());

                return $this->sendError('Echec de la mise à jour.', null, 500);
            }
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());

            return $this->sendError('Erreur lors de la finalisation.', null, 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/clients/set-password",
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

        $user = $this->getUserWithNPI($request->input('npi'));
        if ($user['status']) {
            $output = $this->setDefaultPassword(['id' => $user['data']['id'], 'password' => $request->input('password')], $request->input('type'));
            if ($output['status']) {
                $phoneNumber = User::whereNpi($request->input('npi'))->first()->phonenumber;
                // SendSmsJob::dispatch($phoneNumber, "Votre mot de passe vient d'être modifié si vous n'êtes pas à l'origine de cette modification; nous vous prions de signaler cette opération et de procéder à la mise à jour de vos informations.");

                $final = $this->sendResponse(
                    'Votre mot de passe a bien été mis à jour.',
                    [...$user['data'], ...$output['data']]
                );
            } else {
                $final = $this->sendError($output['message'], null, 400);
            }
        } else {
            $final = $this->sendError($user['message'], null, 400);
        }

        return $final;
    }

    public function sendResetLink(string $npi, string $type)
    {
        $token = Str::random(60);

        DB::table('password_resets')->updateOrInsert(
            ['npi' => $npi, 'type' => $type],
            ['token' => $token, 'created_at' => Carbon::now(), 'type' => $type]
        );

        $user = $this->getUserWithNPI($npi);
        if ($user['status']) {
            $link = env('FRONT_URL')."/reset/{$type}/$token/$npi";

            $phoneNumber = User::whereNpi($npi)->first()->phonenumber;
            $email = User::whereNpi($npi)->first()->email;
            $typeLabel = $type == 'password' ? 'mot de passe' : 'pin';

            // SendSmsJob::dispatch($phoneNumber, "Une demande de mise à jour de votre $typeLabel à été initialisée pour votre compte. Utilisez ce lien pour le mettre à jour : \n $link");
            SendInitLinkJob::dispatch($email, $link, $typeLabel);

            $final = $this->sendResponse(
                "Un lien vous a été envoyé par MAIL consultez le pour mettre à jour votre $typeLabel.",
                []
            );
        } else {
            $final = $this->sendError($user['message'], null, 400);
        }

        return $final;
    }

    /**
     * @OA\Post(
     *      path="/api/finalize-registration",
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
    public function finalizeRegistration(Request $request)
    {
        DB::beginTransaction();
        try {
            // 1) Valider d'abord uniquement le token pour récupérer le contexte (étranger ou non)
            $basic = Validator::make($request->all(), [
                'registration_token' => 'required|string|exists:pending_registrations,registration_token',
            ]);
            if ($basic->fails()) {
                return $this->sendError('Données invalides.', $basic->errors(), 422);
            }

            // Récupérer l'enregistrement en attente et vérifier sa validité
            $pendingRegistration = PendingRegistration::where('registration_token', $request->registration_token)
                ->where('status', 'PENDING')
                ->where('expires_at', '>', Carbon::now())
                ->firstOrFail();

            $isForeigner = $pendingRegistration->user_data['is_foreigner'] ?? false;

            // 2) Construire dynamiquement les règles selon le contexte
            $rules = [
                'transaction_id' => 'required|string',
                'exp_date' => 'nullable|string',
                'birth_date' => 'nullable|string',
                'similarity' => 'required_if:type,ONLINE|string',
                'liveness' => 'required_if:type,ONLINE|string',
                'level' => ['required', 'string', 'in:SIMPLE,ADVANCED', new UniqueTypePerUser($request->user_id, $request->level)],
                'type' => ['required', 'string', 'in:IN_PERSON,ONLINE'],
                'selfie' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:6508',
                'recto' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:2048',
                'verso' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:2048',
            ];
            if (! $isForeigner) {
                $rules = array_merge($rules, [
                    'password' => 'required|string',
                    'pin' => 'required',
                ]);
            } else {
                // Champs KYC issus de l’OCR au finalize (facultatifs mais pris en compte s’ils sont présents)
                $rules = array_merge($rules, [
                    'kyc.name' => 'sometimes|string',
                    'kyc.first_name' => 'sometimes|string',
                    'kyc.phonenumber' => 'sometimes|string',
                    'kyc.nationality' => 'sometimes|string',
                    'kyc.document_type' => 'sometimes|string|in:PASSPORT,RESIDENCE_PERMIT,OTHER',
                    'kyc.document_number' => 'sometimes|string',
                ]);
            }

            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return $this->sendError('Données invalides.', $validator->errors(), 422);
            }

            $transactionId = $request->input('transaction_id');

            // Données utilisateur pré-enregistrées
            $userData = $pendingRegistration->user_data['cached_data'];
            $npi = $userData['data']['npi'] ?? null;

            if ($isForeigner) {
                // Fusionner les données OCR KYC
                $form = $pendingRegistration->user_data['form'] ?? [];
                $kyc = $request->input('kyc', []);

                $name = $kyc['name'] ?? ($form['name'] ?? null);
                $firstName = $kyc['first_name'] ?? ($form['first_name'] ?? null);
                $phone = $kyc['phonenumber'] ?? ($form['phonenumber'] ?? null);
                $nationality = $kyc['nationality'] ?? ($form['nationality'] ?? null);
                $docType = $kyc['document_type'] ?? ($form['document_type'] ?? null);
                $docNumber = $kyc['document_number'] ?? ($form['document_number'] ?? null);

                $user = User::create([
                    'npi' => $npi,
                    'email' => $pendingRegistration->email,
                    'name' => $name,
                    'first_name' => $firstName,
                    'phonenumber' => $phone,
                    'nationality' => $nationality,
                    'profile' => $pendingRegistration->profile_path,
                ]);

                $selfiePath = null;
                $rectoPath = null;
                $versoPath = null;
                if ($request->input('type') === 'ONLINE') {
                    $selfiePath = $request->file('selfie') ? Storage::cloud()->put('selfies', $request->file('selfie')) : null;
                    $rectoPath = $request->file('recto') ? Storage::cloud()->put('images', $request->file('recto')) : null;
                    $versoPath = $request->file('verso') ? Storage::cloud()->put('images', $request->file('verso')) : null;
                }

                $type = $request->input('type');
                $similarity = $request->input('similarity');
                $exp_date = $request->input('exp_date') !== null ? $request->input('exp_date') : '';
                $birth_date = $request->input('birth_date') !== null ? $request->input('birth_date') : '';
                $liveness = $request->input('liveness');

                Identity::create([
                    'type' => $type,
                    'proof' => json_encode([
                        'selfiePath' => $selfiePath,
                        'rectoPath' => $rectoPath,
                        'versoPath' => $versoPath,
                        'liveness' => $liveness,
                        'similarity' => $similarity,
                        'exp_date' => $exp_date,
                        'birth_date' => $birth_date,
                        'document_type' => $docType,
                        'document_number' => $docNumber,
                        'nationality' => $nationality,
                    ]),
                    'level' => 'ADVANCED',
                    'user_id' => $user->id,
                    'status' => 'PENDING',
                ]);

                $subscriptionCreated = $this->storeSubscription($transactionId, $user->id, 'FOREIGNER');
                if (! $subscriptionCreated['status']) {
                    return $this->sendError($subscriptionCreated['message'], $subscriptionCreated['data'], 500);
                }

                $user->assignRole('client');
                if ($request->input('type') === 'IN_PERSON') {
                    PlanifiedEmailJob::dispatch($user->email);
                } else {
                    AdvancedIdRequestJob::dispatch($user->email);
                }
                Cache::forget('foreigner_otp_valid_'.$pendingRegistration->email);
                $pendingRegistration->update(['status' => 'COMPLETED']);

                DB::commit();

                return $this->sendResponse('Inscription finalisée.', [
                    'user_id' => $user->id,
                    'phonenumber' => $user->phonenumber,
                ]);
            }

            // Flux citoyen ANIP (existant)
            $output = $this->register($userData);

            if ($output['status']) {
                // Créer l'utilisateur
                $user = User::create([
                    ...$userData['data'],
                    'email' => $pendingRegistration->email,
                    'profile' => $pendingRegistration->profile_path,
                ]);

                $selfiePath = null;
                $rectoPath = null;
                $versoPath = null;
                if ($request->input('type') === 'ONLINE') {
                    $selfiePath = $request->file('selfie') ? Storage::cloud()->put('selfies', $request->file('selfie')) : null;
                    $rectoPath = $request->file('recto') ? Storage::cloud()->put('images', $request->file('recto')) : null;
                    $versoPath = $request->file('verso') ? Storage::cloud()->put('images', $request->file('verso')) : null;
                }

                $type = $request->input('type');
                $similarity = $request->input('similarity');
                $exp_date = $request->input('exp_date') !== null ? $request->input('exp_date') : '';
                $birth_date = $request->input('birth_date') !== null ? $request->input('birth_date') : '';
                $liveness = $request->input('liveness');

                Identity::create([
                    'type' => $type,
                    'proof' => json_encode([
                        'selfiePath' => $selfiePath,
                        'rectoPath' => $rectoPath,
                        'versoPath' => $versoPath,
                        'liveness' => $liveness,
                        'similarity' => $similarity,
                        'exp_date' => $exp_date,
                        'birth_date' => $birth_date,
                    ]),
                    'level' => 'ADVANCED',
                    'user_id' => $user->id,
                    'status' => 'PENDING',
                ]);

                $subscriptionCreated = $this->storeSubscription($transactionId, $user->id);
                if (! $subscriptionCreated['status']) {
                    return $this->sendError($subscriptionCreated['message'], $subscriptionCreated['data'], 500);
                }

                $pass_output = $this->setDefaultPassword(['id' => $output['data']['id'], 'password' => $request->input('password')], 'password');
                $pin_output = $this->setDefaultPassword(['id' => $output['data']['id'], 'password' => $request->input('pin')], 'pin');
                if ($pass_output['status'] && $pin_output['status']) {
                    $final = $this->sendResponse(
                        "Bienvenue sur la plateforme d'enregistrement déléguée votre pin et votre mot de passe ont bien été enregistrés",
                        [...$output['data'], 'passOut' => [...$pass_output['data'], ...$pin_output['data']], 'user_id' => $user->id, 'phonenumber' => $user->phonenumber]
                    );
                } elseif ($pass_output['status']) {
                    $final = $this->sendResponse(
                        "Bienvenue sur la plateforme d'enregistrement déléguée nous n'avons pas pu enregistrer votre pin cependant votre identité à bien été créée il vous suffira de lancer la procédure de mise à jour pour que l'opération soit effective.",
                        [$output['data']]
                    );
                } else {
                    return $this->sendError(
                        "Nous n'avons pas pu enregistrer votre pin ni votre mot de passe. Veuillez réessayer.",
                        null,
                        500
                    );
                }
                $user->assignRole('client');
                // Notifier selon le type de vérification
                if ($request->input('type') === 'IN_PERSON') {
                    PlanifiedEmailJob::dispatch($user->email);
                } else {
                    AdvancedIdRequestJob::dispatch($user->email);
                }
                Cache::forget('user_'.$npi);
                Cache::forget('user_'.$npi.'_validate_otp');

                // Marquer l'inscription comme terminée
                $pendingRegistration->update(['status' => 'COMPLETED']);

                DB::commit();

                return $final;
            } else {
                $final = $this->sendError($output['message'], $output, 400);
            }
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());

            return $this->sendError('Erreur lors de la finalisation.', null, 500);
        }
    }

    /**
     * @OA\Get(
     *      path="/api/invitations",
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
    public function listInvitations()
    {
        try {
            $user = auth()->user();

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
     *      path="/api/invitations/{invitation}/accept",
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
    public function acceptInvitation($invitationId)
    {
        DB::beginTransaction();
        try {
            $user = auth()->user();

            $invitation = StructureInvitation::where('id', $invitationId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Vérifier que l'invitation est encore valide
            if ($invitation->status !== 'PENDING' || $invitation->expires_at <= Carbon::now()) {
                return $this->sendError('Cette invitation n\'est plus valide.', null, 400);
            }

            // Marquer l'invitation comme acceptée
            $invitation->accept();

            // Ajouter l'utilisateur à la structure
            $invitation->structure->employees()->attach($user->id, [
                'role' => $invitation->role,
                'status' => 'ACTIVE',
                'joined_at' => Carbon::now(),
                'invitation_message' => $invitation->message,
            ]);

            // Activer l'utilisateur si ce n'est pas déjà fait
            if ($user->status !== 'ACTIVE') {
                $user->update(['status' => 'ACTIVE']);
            }

            DB::commit();

            return $this->sendResponse('Invitation acceptée avec succès.', [
                'structure' => $invitation->structure->only(['id', 'name']),
                'role' => $invitation->role,
            ]);

        } catch (ModelNotFoundException $e) {
            return $this->sendError('Invitation non trouvée.', null, 404);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de l\'acceptation de l\'invitation : '.$e->getMessage());

            return $this->sendError('Impossible d\'accepter l\'invitation.', null, 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/invitations/{invitation}/reject",
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
    public function rejectInvitation($invitationId)
    {
        try {
            $user = auth()->user();

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
     *      path="/api/invitations/{token}/details",
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
     *      path="/api/invitations/{token}/respond",
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
        DB::beginTransaction();
        try {
            $validatedData = $request->validate([
                'action' => 'required|string|in:accept,reject',
            ]);

            $invitation = StructureInvitation::with(['structure', 'user'])
                ->where('token', $token)
                ->firstOrFail();

            // Vérifier que l'invitation est encore valide
            if (! $invitation->isPending()) {
                return $this->sendError('Cette invitation n\'est plus valide.', null, 400);
            }

            if ($validatedData['action'] === 'accept') {
                // Marquer comme acceptée
                $invitation->accept();

                // Ajouter l'utilisateur à la structure
                $invitation->structure->employees()->attach($invitation->user_id, [
                    'role' => $invitation->role,
                    'status' => 'ACTIVE',
                    'joined_at' => Carbon::now(),
                    'invitation_message' => $invitation->message,
                ]);

                // Activer l'utilisateur si ce n'est pas déjà fait
                $user = User::find($invitation->user_id);
                if ($user && $user->status !== 'ACTIVE') {
                    $user->update(['status' => 'ACTIVE']);
                }

                $message = 'Invitation acceptée avec succès.';
            } else {
                // Marquer comme rejetée
                $invitation->reject();
                $message = 'Invitation refusée avec succès.';
            }

            DB::commit();

            return $this->sendResponse($message, [
                'action' => $validatedData['action'],
                'structure' => $invitation->structure->only(['id', 'name']),
                'role' => $invitation->role,
            ]);

        } catch (ModelNotFoundException $e) {
            return $this->sendError('Invitation non trouvée.', null, 404);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors du traitement de la réponse : '.$e->getMessage());

            return $this->sendError('Impossible de traiter votre réponse.', null, 500);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/employees/create",
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
    public function createEmployee(Request $request)
    {
        DB::beginTransaction();
        try {
            $validatedData = $request->validate([
                'email' => 'required|email|unique:users,email',
                'structure_id' => 'required|integer|exists:structures,id',
                'name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:20',
                'role' => 'sometimes|string|in:EMPLOYEE,MANAGER_ASSISTANT,VIEWER',
                'message' => 'sometimes|string|max:500',
            ]);

            $manager = auth()->user();
            $structure = Structure::findOrFail($validatedData['structure_id']);

            // Vérifier que le manager est bien le propriétaire de la structure
            if ($structure->manager_id !== $manager->id) {
                return $this->sendError('Vous n\'êtes pas autorisé à créer des employés pour cette structure.', null, 403);
            }

            // Vérifier que la structure est validée
            if ($structure->status !== 'APPROVED') {
                return $this->sendError('La structure doit être validée avant de créer des employés.', null, 400);
            }

            // 1. Créer le nouvel utilisateur avec statut CREATED
            $user = User::create([
                'email' => $validatedData['email'],
                'name' => $validatedData['name'],
                'phonenumber' => $validatedData['phone'] ?? null,
                'status' => 'CREATED',
                // 'npi' => 'EMP_' . time() . '_' . rand(1000, 9999),
                // 'password' => bcrypt(Str::random(32)),
            ]);

            $user->assignRole('client');

            // 2. CRÉER L'ENTRÉE DANS structure_users AVEC STATUT ACTIVE (DIRECTEMENT)
            $structure->employees()->attach($user->id, [
                'role' => $validatedData['role'] ?? 'EMPLOYEE',
                'status' => 'ACTIVE', // DIRECTEMENT ACTIF
                'joined_at' => Carbon::now(),
                'invitation_message' => $validatedData['message'] ?? 'Créé par le manager',
            ]);

            // 3. CRÉER UNE INVITATION "AUTO-ACCEPTED" (pour historique seulement)
            $invitation = StructureInvitation::create([
                'structure_id' => $structure->id,
                'user_id' => $user->id,
                'invited_by' => $manager->id,
                'email' => $user->email,
                'token' => bin2hex(random_bytes(32)),
                'role' => $validatedData['role'] ?? 'EMPLOYEE',
                'status' => 'ACCEPTED', // DIRECTEMENT ACCEPTÉE
                'expires_at' => Carbon::now()->addDays(7),
                'accepted_at' => Carbon::now(), // Date d'acceptation maintenant
                'message' => $validatedData['message'] ?? null,
            ]);

            // 4. ENVOYER UN EMAIL D'ACTIVATION (pas d'invitation)

            DB::commit();

            SendStructureInvitationEmail::dispatch($invitation);

            return $this->sendResponse('Employé créé et directement affilié à la structure.', [
                'user' => $user->only(['id', 'email', 'name', 'status']),
                'structure' => $structure->only(['id', 'name']),
                'role' => $validatedData['role'] ?? 'EMPLOYEE',
                'joined_at' => Carbon::now()->toDateTimeString(),
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la création de l\'employé : '.$e->getMessage());

            return $this->sendError($e->getMessage() ?? 'Impossible de créer l\'employé.', null, 500);
        }
    }
}
