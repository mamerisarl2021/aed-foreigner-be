<?php

namespace App\Http\Controllers;

use App\Jobs\AdvancedIdMidJob;
use App\Jobs\AdvancedIdRequestJob;
use App\Jobs\PlanifiedEmailJob;
use App\Jobs\RescheduledAppointmentJob;
use App\Jobs\ScheduledAppointmentJob;
use App\Jobs\SendOTPJob;
use App\Jobs\WelcomeUserJob;
use App\Models\Identity;
use App\Models\OTP;
use App\Models\PendingRegistration;
use Illuminate\Http\Request;
use App\Models\User;
use App\Rules\UniqueTypePerUser;
use App\Traits\AuthTrait;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use App\Jobs\SendInitLinkJob;

class UserController extends BaseController
{
    use AuthTrait;

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
                'valid_until' => $validUntil
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
            Cache::put('user_' . $npi, [
                'data' => $anipData
            ], 600);
        } else {
            Log::error('NPI inexistant');
            return $this->sendError('Le numéro personnel d\'identification renseigné n\'existe pas dans la base de donnée de l\'ANIP vérifiez bien qu\'il s\'agit du bon numéro et reéssayez.', null, 404);
        }

        return $this->sendResponse(
            "Un code OTP vous a été envoyé par e-mail. Il expire dans 5 minutes."
        );
    }

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

            if (!$existingOTP) {
                return $this->sendError('OTP invalide ou expiré.', null, 400);
            }

            $cachedData = Cache::get('user_' . $npi);

            if (!$cachedData) {
                return $this->sendError("Le code OTP n'est plus valide veuillez réessayer", null, 404);
            }

            $ttlSeconds = Carbon::now()->diffInSeconds(Carbon::parse($existingOTP->valid_until));
            Cache::put('user_' . $npi . '_validate_otp', true, $ttlSeconds > 0 ? $ttlSeconds : 300);

            return $this->sendResponse(
                'OTP valide.',
                $cachedData['data']
            );
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), null, 500);
        }
    }
    
    public function login(Request $request)
    {
        $this->validate($request, ['code' => 'required']);
        $code = $request->input('code');
        $response = $this->userInfo($code);

        return $response['status'] ?
            $this->sendResponse('Token obtenu avec succès!', $response['data']) :
            $this->sendError($response['message'], null, 401);
    }

    public function loginMobile(Request $request)
    {
        $this->validate($request, ['code' => 'required']);
        $code = $request->input('code');
        $response = $this->mobileUserInfo($code);
        return $response['status'] ?
            $this->sendResponse('Token obtenu avec succès!', $response['data']) :
            $this->sendError($response['message'], null, 401);
    }

    public function show($id)
    {
        try {
            $user = User::findOrFail($id);
            return $this->sendResponse('Utilisateur récupéré.', $user);
        } catch (\Exception $e) {
            Log::error('Fetching user failed: ' . $e->getMessage());
            return $this->sendError('Fetching user failed.', null, 500);
        }
    }

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
        } catch (\Exception $e) {
            Log::error('Searching users failed: ' . $e->getMessage());
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
        } catch (\Exception $e) {
            Log::error('Searching users failed: ' . $e->getMessage());
            return $this->sendError('Searching users failed.', null, 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'profile' => 'nullable|mimes:png,jpeg,jpg|max:2048',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('users')->ignore($id)
                ],
            ]);


            DB::beginTransaction();

            $user = User::findOrFail($id);

            // Initialiser les données à mettre à jour
            $updateData = [
                'email' => $request->input('email')
            ];

            // Gestion de l'upload de l'image de profil si fournie
            if ($request->hasFile('profile')) {
                $profilePath = Storage::cloud()->put('images', $request->file('profile'));
                if (!$profilePath) {
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
                'signatures'
            ]));
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            Log::warning("Erreur de validation lors de la mise à jour de l'utilisateur : ", $e->errors());
            return $this->sendError('Erreur de validation.', $e->errors(), 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Mise à jour de l'utilisateur échouée : " . $e->getMessage());
            return $this->sendError("Une erreur est survenue lors de la mise à jour de vos informations.", null, 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = User::findOrFail($id);
            $user->delete();
            return $this->sendResponse('User deleted successfully.', []);
        } catch (\Exception $e) {
            Log::error('Deleting user failed: ' . $e->getMessage());
            return $this->sendError('Deleting user failed.', null, 500);
        }
    }

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
        } catch (\Exception $e) {
            Log::error('Failed to update user statuses: ' . $e->getMessage());
            return $this->sendError('Failed to update user statuses.', null, 500);
        }
    }

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
                    $link = env('FRONT_URL') . "/init-account/all/$allToken/$user->npi";
                    WelcomeUserJob::dispatch($user->email, $user, $link, true);
                }
            });

            return $this->sendResponse("Le statut de l'identité à bien été mis à jour", $identityPayload);
        } catch (\Exception $e) {
            Log::error('Failed to update identity status: ' . $e->getMessage());
            return $this->sendError('Failed to update identity status.', null, 500);
        }
    }

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
                    'npi' => User::findOrFail($identityPayload['user_id'])->npi
                ]
            ];

            try {
                if ($identityPayload['status'] == 'APPROVED')
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
                                    'birth_date' => $birth_date
                                ]),
                                'status' => $identityPayload['user_id'] == null ? 'PENDING' : $identityPayload['status']
                            ]);

                            $email = optional(User::find($request->input('user_id')))->email;
                            $npi = optional(User::find($request->input('user_id')))->npi;

                            if (isset($output['has_user']) && $output['has_user'] === true) {
                                $allToken = Str::random(60);
                                DB::table('password_resets')->updateOrInsert(
                                    ['npi' => $npi, 'type' => 'all'],
                                    ['token' => $allToken, 'created_at' => Carbon::now(), 'type' => 'all']
                                );
                                $link = env('FRONT_URL') . "/init-account/all/$allToken/$npi";
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

                                $link = env('FRONT_URL') . "/init-account/none/$pinToken/$passwordToken/$allToken/$npi";
                                WelcomeUserJob::dispatch($email, User::findOrFail($identityPayload['user_id']), $link, true);
                            }
                        } else {
                            return $this->sendError($output['message'], $output, 400);
                        }
                    });
                else {
                    $identity = Identity::findOrFail($identityPayload['id']);
                    $identity->update([
                        'status' => $identityPayload['user_id'] == null ? 'PENDING' : $identityPayload['status']
                    ]);
                }
                DB::commit();

                return $this->sendResponse("Le statut de l'identité à bien été mis à jour", $identityPayload);
            } catch (\Exception $e) {
                Log::error('Failed to update identity status: ' . $e->getMessage());
                return $this->sendError('Failed to update identity status.', null, 500);
            }
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return $this->sendError('Erreur lors de la finalisation.', null, 500);
        }
    }

    public function setPassword(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
            'npi' => 'required|string',
            'type' => 'required|string|in:password,pin',
        ]);


        $user = $this->getUserWithNPI($request->input('npi'));
        if ($user['status']) {
            $output = $this->setDefaultPassword(["id" => $user['data']['id'], "password" => $request->input('password')], $request->input('type'));
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
            $link = env('FRONT_URL') . "/reset/{$type}/$token/$npi";

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
            if (!$isForeigner) {
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

                $selfiePath = null; $rectoPath = null; $versoPath = null;
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
                    'status' => 'PENDING'
                ]);

                $subscriptionCreated = $this->storeSubscription($transactionId, $user->id, 'FOREIGNER');
                if (!$subscriptionCreated['status']) {
                    return $this->sendError($subscriptionCreated['message'], $subscriptionCreated['data'], 500);
                }

                $user->assignRole('client');
                if ($request->input('type') === 'IN_PERSON') {
                    PlanifiedEmailJob::dispatch($user->email);
                } else {
                    AdvancedIdRequestJob::dispatch($user->email);
                }
                Cache::forget('foreigner_otp_valid_' . $pendingRegistration->email);
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
                    'profile' => $pendingRegistration->profile_path
                ]);


                $selfiePath = null; $rectoPath = null; $versoPath = null;
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
                        'birth_date' => $birth_date
                    ]),
                    'level' => 'ADVANCED',
                    'user_id' => $user->id,
                    'status' => 'PENDING'
                ]);

                $subscriptionCreated = $this->storeSubscription($transactionId, $user->id);
                if (!$subscriptionCreated['status']) {
                    return $this->sendError($subscriptionCreated['message'], $subscriptionCreated['data'], 500);
                }

                $pass_output = $this->setDefaultPassword(["id" => $output['data']['id'], "password" => $request->input('password')], "password");
                $pin_output = $this->setDefaultPassword(["id" => $output['data']['id'], "password" => $request->input('pin')], "pin");
                if ($pass_output["status"] && $pin_output["status"]) {
                    $final = $this->sendResponse(
                        "Bienvenue sur la plateforme d'enregistrement déléguée votre pin et votre mot de passe ont bien été enregistrés",
                        [...$output['data'], 'passOut' => [...$pass_output['data'], ...$pin_output['data']], 'user_id' => $user->id, 'phonenumber' => $user->phonenumber]
                    );
                } elseif ($pass_output["status"]) {
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
                Cache::forget('user_' . $npi);
                Cache::forget('user_' . $npi . '_validate_otp');

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
}
