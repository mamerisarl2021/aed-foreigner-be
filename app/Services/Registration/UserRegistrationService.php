<?php

namespace App\Services\Registration;

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
use App\Models\UserPackage;
use App\Models\UserSubscription;
use App\Services\ANIP\AnipSimulatorService;
use App\Services\PKI\TrustedXClientService;
use App\Services\ServiceResult;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kkiapay\Kkiapay;
use Throwable;

class UserRegistrationService
{
    public function __construct(
        private readonly TrustedXClientService $trustedXClient,
        private readonly AnipSimulatorService $anipSimulator,
    ) {}

    public function sendOtp(string $npi): ServiceResult
    {
        $otp = implode('', array_map(fn () => (string) mt_rand(0, 9), range(1, 6)));
        $validUntil = Carbon::now()->addMinutes(5);

        $existingOTP = OTP::where('npi', $npi)->first();
        if ($existingOTP) {
            $existingOTP->update(['otp' => $otp, 'valid_until' => $validUntil]);
        } else {
            OTP::create([
                'npi' => $npi,
                'otp' => $otp,
                'valid_until' => $validUntil,
            ]);
        }

        $anipData = $this->anipSimulator->getUserData($npi);
        if (! $anipData['status']) {
            Log::error('NPI inexistant');

            return ServiceResult::fail(
                'Le numéro personnel d\'identification renseigné n\'existe pas dans la base de donnée de l\'ANIP vérifiez bien qu\'il s\'agit du bon numéro et reéssayez.',
                null,
                404
            );
        }

        SendOTPJob::dispatch($anipData['data']['email'], $otp);
        Cache::put('user_'.$npi, ['data' => $anipData], 600);

        return ServiceResult::ok('Un code OTP vous a été envoyé par e-mail. Il expire dans 5 minutes.');
    }

    public function verifyOtp(string $npi, string $otp): ServiceResult
    {
        try {
            $existingOTP = OTP::where('npi', $npi)
                ->where('otp', $otp)
                ->where('valid_until', '>=', Carbon::now())
                ->first();

            if (! $existingOTP) {
                return ServiceResult::fail('OTP invalide ou expiré.', null, 400);
            }

            $cachedData = Cache::get('user_'.$npi);
            if (! $cachedData) {
                return ServiceResult::fail("Le code OTP n'est plus valide veuillez réessayer", null, 404);
            }

            $ttlSeconds = Carbon::now()->diffInSeconds(Carbon::parse($existingOTP->valid_until));
            Cache::put('user_'.$npi.'_validate_otp', true, $ttlSeconds > 0 ? $ttlSeconds : 300);

            return ServiceResult::ok('OTP valide.', $cachedData['data']);
        } catch (Exception $e) {
            return ServiceResult::fail($e->getMessage(), null, 500);
        }
    }

    public function login(string $code): ServiceResult
    {
        $response = $this->trustedXClient->userInfo($code);

        return $response['status']
            ? ServiceResult::ok('Token obtenu avec succès!', $response['data'])
            : ServiceResult::fail($response['message'], null, 401);
    }

    public function loginMobile(string $code): ServiceResult
    {
        $response = $this->trustedXClient->mobileUserInfo($code);

        return $response['status']
            ? ServiceResult::ok('Token obtenu avec succès!', $response['data'])
            : ServiceResult::fail($response['message'], null, 401);
    }

    public function setPassword(string $npi, string $password, string $type): ServiceResult
    {
        $user = $this->trustedXClient->getUserWithNPI($npi);
        if (! $user['status']) {
            return ServiceResult::fail($user['message'], null, 400);
        }

        $output = $this->trustedXClient->setDefaultPassword(
            ['id' => $user['data']['id'], 'password' => $password],
            $type
        );

        if (! $output['status']) {
            return ServiceResult::fail($output['message'], null, 400);
        }

        return ServiceResult::ok(
            'Votre mot de passe a bien été mis à jour.',
            [...$user['data'], ...$output['data']]
        );
    }

    public function sendResetLink(string $npi, string $type): ServiceResult
    {
        $token = Str::random(60);

        DB::table('password_resets')->updateOrInsert(
            ['npi' => $npi, 'type' => $type],
            ['token' => $token, 'created_at' => Carbon::now(), 'type' => $type]
        );

        $user = $this->trustedXClient->getUserWithNPI($npi);
        if (! $user['status']) {
            return ServiceResult::fail($user['message'], null, 400);
        }

        $link = config('app.frontend_url')."/reset/{$type}/$token/$npi";
        $typeLabel = $type === 'password' ? 'mot de passe' : 'pin';
        $email = User::whereNpi($npi)->first()->email;

        SendInitLinkJob::dispatch($email, $link, $typeLabel);

        return ServiceResult::ok(
            "Un lien vous a été envoyé par MAIL consultez le pour mettre à jour votre $typeLabel.",
            []
        );
    }

    public function finalizeRegistration(Request $request, PendingRegistration $pendingRegistration): ServiceResult
    {
        DB::beginTransaction();
        try {
            $isForeigner = $pendingRegistration->user_data['is_foreigner'] ?? false;
            $transactionId = $request->input('transaction_id');
            $userData = $pendingRegistration->user_data['cached_data'];
            $npi = $userData['data']['npi'] ?? null;

            if ($isForeigner) {
                return $this->finalizeForeignerRegistration($request, $pendingRegistration, $transactionId, $npi);
            }

            return $this->finalizeCitizenRegistration($request, $pendingRegistration, $transactionId, $userData, $npi);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());

            return ServiceResult::fail('Erreur lors de la finalisation.', null, 500);
        }
    }

    public function approveInPersonIdentity(array $identityPayload, Request $request): ServiceResult
    {
        DB::beginTransaction();
        try {
            $userData = [
                'data' => [
                    'npi' => User::findOrFail($identityPayload['user_id'])->npi,
                ],
            ];

            if ($identityPayload['status'] === 'APPROVED') {
                $result = $this->approveInPersonIdentityApproved($identityPayload, $userData, $request);
                if (! $result->success) {
                    DB::rollBack();

                    return $result;
                }
            } else {
                $identity = Identity::findOrFail($identityPayload['id']);
                $identity->update([
                    'status' => $identityPayload['user_id'] == null ? 'PENDING' : $identityPayload['status'],
                ]);
            }

            DB::commit();

            return ServiceResult::ok("Le statut de l'identité à bien été mis à jour", $identityPayload);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());

            return ServiceResult::fail('Erreur lors de la finalisation.', null, 500);
        }
    }

    /**
     * @param  array<string, mixed>  $validatedData
     */
    public function createEmployee(array $validatedData, User $manager): ServiceResult
    {
        DB::beginTransaction();
        try {
            $structure = Structure::findOrFail($validatedData['structure_id']);

            if ($structure->manager_id !== $manager->id) {
                return ServiceResult::fail(
                    'Vous n\'êtes pas autorisé à créer des employés pour cette structure.',
                    null,
                    403
                );
            }

            if ($structure->status !== 'APPROVED') {
                return ServiceResult::fail(
                    'La structure doit être validée avant de créer des employés.',
                    null,
                    400
                );
            }

            $user = User::create([
                'email' => $validatedData['email'],
                'name' => $validatedData['name'],
                'phonenumber' => $validatedData['phone'] ?? null,
                'status' => 'CREATED',
            ]);

            $user->assignRole('client');

            $structure->employees()->attach($user->id, [
                'role' => $validatedData['role'] ?? 'EMPLOYEE',
                'status' => 'ACTIVE',
                'joined_at' => Carbon::now(),
                'invitation_message' => $validatedData['message'] ?? 'Créé par le manager',
            ]);

            $invitation = StructureInvitation::create([
                'structure_id' => $structure->id,
                'user_id' => $user->id,
                'invited_by' => $manager->id,
                'email' => $user->email,
                'token' => bin2hex(random_bytes(32)),
                'role' => $validatedData['role'] ?? 'EMPLOYEE',
                'status' => 'ACCEPTED',
                'expires_at' => Carbon::now()->addDays(7),
                'accepted_at' => Carbon::now(),
                'message' => $validatedData['message'] ?? null,
            ]);

            DB::commit();

            SendStructureInvitationEmail::dispatch($invitation);

            return ServiceResult::ok('Employé créé et directement affilié à la structure.', [
                'user' => $user->only(['id', 'email', 'name', 'status']),
                'structure' => $structure->only(['id', 'name']),
                'role' => $validatedData['role'] ?? 'EMPLOYEE',
                'joined_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la création de l\'employé : '.$e->getMessage());

            return ServiceResult::fail($e->getMessage() ?: 'Impossible de créer l\'employé.', null, 500);
        }
    }

    private function finalizeForeignerRegistration(
        Request $request,
        PendingRegistration $pendingRegistration,
        string $transactionId,
        ?string $npi,
    ): ServiceResult {
        $form = $pendingRegistration->user_data['form'] ?? [];
        $kyc = $request->input('kyc', []);

        $user = User::create([
            'npi' => $npi,
            'email' => $pendingRegistration->email,
            'name' => $kyc['name'] ?? ($form['name'] ?? null),
            'first_name' => $kyc['first_name'] ?? ($form['first_name'] ?? null),
            'phonenumber' => $kyc['phonenumber'] ?? ($form['phonenumber'] ?? null),
            'nationality' => $kyc['nationality'] ?? ($form['nationality'] ?? null),
            'profile' => $pendingRegistration->profile_path,
        ]);

        $uploaded = $this->uploadIdentityFiles($request);

        Identity::create([
            'type' => $request->input('type'),
            'proof' => json_encode([
                'selfiePath' => $uploaded['selfie'],
                'rectoPath' => $uploaded['recto'],
                'versoPath' => $uploaded['verso'],
                'liveness' => $request->input('liveness'),
                'similarity' => $request->input('similarity'),
                'exp_date' => $request->input('exp_date') ?? '',
                'birth_date' => $request->input('birth_date') ?? '',
                'document_type' => $kyc['document_type'] ?? ($form['document_type'] ?? null),
                'document_number' => $kyc['document_number'] ?? ($form['document_number'] ?? null),
                'nationality' => $kyc['nationality'] ?? ($form['nationality'] ?? null),
            ]),
            'level' => 'ADVANCED',
            'user_id' => $user->id,
            'status' => 'PENDING',
        ]);

        $subscriptionCreated = $this->storeSubscription($transactionId, $user->id);
        if (! $subscriptionCreated['status']) {
            DB::rollBack();

            return ServiceResult::fail($subscriptionCreated['message'], $subscriptionCreated['data'], 500);
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

        return ServiceResult::ok('Inscription finalisée.', [
            'user_id' => $user->id,
            'phonenumber' => $user->phonenumber,
        ]);
    }

    /**
     * @param  array<string, mixed>  $userData
     */
    private function finalizeCitizenRegistration(
        Request $request,
        PendingRegistration $pendingRegistration,
        string $transactionId,
        array $userData,
        ?string $npi,
    ): ServiceResult {
        $output = $this->trustedXClient->register($userData);

        if (! $output['status']) {
            DB::rollBack();

            return ServiceResult::fail($output['message'], $output, 400);
        }

        $user = User::create([
            ...$userData['data'],
            'email' => $pendingRegistration->email,
            'profile' => $pendingRegistration->profile_path,
        ]);

        $uploaded = $this->uploadIdentityFiles($request);

        Identity::create([
            'type' => $request->input('type'),
            'proof' => json_encode([
                'selfiePath' => $uploaded['selfie'],
                'rectoPath' => $uploaded['recto'],
                'versoPath' => $uploaded['verso'],
                'liveness' => $request->input('liveness'),
                'similarity' => $request->input('similarity'),
                'exp_date' => $request->input('exp_date') ?? '',
                'birth_date' => $request->input('birth_date') ?? '',
            ]),
            'level' => 'ADVANCED',
            'user_id' => $user->id,
            'status' => 'PENDING',
        ]);

        $subscriptionCreated = $this->storeSubscription($transactionId, $user->id);
        if (! $subscriptionCreated['status']) {
            DB::rollBack();

            return ServiceResult::fail($subscriptionCreated['message'], $subscriptionCreated['data'], 500);
        }

        $passOutput = $this->trustedXClient->setDefaultPassword(
            ['id' => $output['data']['id'], 'password' => $request->input('password')],
            'password'
        );
        $pinOutput = $this->trustedXClient->setDefaultPassword(
            ['id' => $output['data']['id'], 'password' => $request->input('pin')],
            'pin'
        );

        if (! $passOutput['status'] && ! $pinOutput['status']) {
            DB::rollBack();

            return ServiceResult::fail(
                "Nous n'avons pas pu enregistrer votre pin ni votre mot de passe. Veuillez réessayer.",
                null,
                500
            );
        }

        $user->assignRole('client');

        if ($request->input('type') === 'IN_PERSON') {
            PlanifiedEmailJob::dispatch($user->email);
        } else {
            AdvancedIdRequestJob::dispatch($user->email);
        }

        Cache::forget('user_'.$npi);
        Cache::forget('user_'.$npi.'_validate_otp');
        $pendingRegistration->update(['status' => 'COMPLETED']);
        DB::commit();

        if ($passOutput['status'] && $pinOutput['status']) {
            return ServiceResult::ok(
                "Bienvenue sur la plateforme d'enregistrement déléguée votre pin et votre mot de passe ont bien été enregistrés",
                [...$output['data'], 'passOut' => [...$passOutput['data'], ...$pinOutput['data']], 'user_id' => $user->id, 'phonenumber' => $user->phonenumber]
            );
        }

        return ServiceResult::ok(
            "Bienvenue sur la plateforme d'enregistrement déléguée nous n'avons pas pu enregistrer votre pin cependant votre identité à bien été créée il vous suffira de lancer la procédure de mise à jour pour que l'opération soit effective.",
            [$output['data']]
        );
    }

    /**
     * @param  array<string, mixed>  $identityPayload
     * @param  array<string, mixed>  $userData
     */
    private function approveInPersonIdentityApproved(
        array $identityPayload,
        array $userData,
        Request $request,
    ): ServiceResult {
        $output = $this->trustedXClient->register($userData);
        Log::info('Output from register: ', $output);

        if (! $output['status']) {
            return ServiceResult::fail($output['message'], $output, 400);
        }

        $uploaded = $this->uploadIdentityFiles($request, forceOnline: true);

        $expDate = $request->input('exp_date') ? Carbon::parse($request->input('exp_date'))->format('Y-m-d') : null;
        $birthDate = $request->input('birth_date') ? Carbon::parse($request->input('birth_date'))->format('Y-m-d') : null;

        $identity = Identity::findOrFail($identityPayload['id']);
        $identity->update([
            'proof' => json_encode([
                'selfiePath' => $uploaded['selfie'],
                'rectoPath' => $uploaded['recto'],
                'versoPath' => $uploaded['verso'],
                'exp_date' => $expDate,
                'birth_date' => $birthDate,
            ]),
            'status' => $identityPayload['user_id'] == null ? 'PENDING' : $identityPayload['status'],
        ]);

        $email = optional(User::find($request->input('user_id')))->email;
        $npi = optional(User::find($request->input('user_id')))->npi;
        $targetUser = User::findOrFail($identityPayload['user_id']);

        if (isset($output['has_user']) && $output['has_user'] === true) {
            $allToken = Str::random(60);
            DB::table('password_resets')->updateOrInsert(
                ['npi' => $npi, 'type' => 'all'],
                ['token' => $allToken, 'created_at' => Carbon::now(), 'type' => 'all']
            );
            $link = config('app.frontend_url')."/init-account/all/$allToken/$npi";
            WelcomeUserJob::dispatch($email, $targetUser, $link, true);
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

            $link = config('app.frontend_url')."/init-account/none/$pinToken/$passwordToken/$allToken/$npi";
            WelcomeUserJob::dispatch($email, $targetUser, $link, true);
        }

        return ServiceResult::ok('Approved', null);
    }

    /**
     * @return array{selfie: string|null, recto: string|null, verso: string|null}
     */
    private function uploadIdentityFiles(Request $request, bool $forceOnline = false): array
    {
        $paths = ['selfie' => null, 'recto' => null, 'verso' => null];

        if ($forceOnline || $request->input('type') === 'ONLINE') {
            if ($request->hasFile('selfie')) {
                $paths['selfie'] = Storage::cloud()->put('selfies', $request->file('selfie'));
            }
            if ($request->hasFile('recto')) {
                $paths['recto'] = Storage::cloud()->put('images', $request->file('recto'));
            }
            if ($request->hasFile('verso')) {
                $paths['verso'] = Storage::cloud()->put('images', $request->file('verso'));
            }
        }

        return $paths;
    }

    /**
     * @return array{status: bool, message: string, data: mixed}
     */
    private function storeSubscription(string $transactionId, int $userId): array
    {
        try {
            $state = $this->kkiaPayment($transactionId);
            $payload = json_decode($state[0], true);

            if (UserPackage::where('id', $payload['package'])
                ->where('prix', (int) $payload['amount'])
                ->count() != 1
            ) {
                Log::alert('Failed to create user subscription');

                return [
                    'status' => false,
                    'message' => 'Ooops tentative de fraude détectée!',
                    'data' => [],
                ];
            }

            UserSubscription::where('user_id', $userId)
                ->where('type', 'CITIZEN')
                ->update(['current' => false]);

            UserSubscription::create([
                'user_id' => $userId,
                'current' => true,
                'status' => 'SENT',
                'package_id' => $payload['package'],
                'type' => 'CITIZEN',
            ]);

            return [
                'status' => true,
                'message' => 'Abonnement créé.',
                'data' => $payload,
            ];
        } catch (Throwable $e) {
            Log::error('Subscription storage failed: '.$e->getMessage());

            return [
                'status' => false,
                'message' => "Échec de la création de l'abonnement.",
                'data' => null,
            ];
        }
    }

    private function kkiaPayment(string $transId): Collection
    {
        $kkiapay = new Kkiapay(
            config('kkiapay.public_key'),
            config('kkiapay.private_key'),
            config('kkiapay.secret'),
            config('kkiapay.sandbox')
        );

        $payment = $kkiapay->verifyTransaction($transId);

        return collect($payment->state);
    }

    /**
     * Update user profile information.
     */
    public function updateUser(int $id, array $updateData, ?UploadedFile $profile): ServiceResult
    {
        DB::beginTransaction();
        try {
            $user = User::findOrFail($id);

            if ($profile) {
                $profilePath = Storage::cloud()->put('images', $profile);
                if (! $profilePath) {
                    DB::rollBack();

                    return ServiceResult::fail("Échec du téléchargement de l'image.", null, 500);
                }
                $updateData['profile'] = $profilePath;
            }

            $user->update($updateData);
            DB::commit();

            return ServiceResult::ok('Vos informations ont bien été mises à jour!', $user->load([
                'cases',
                'structures',
                'userSubscriptions',
                'identities',
                'signatures',
            ]));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Mise à jour de l'utilisateur échouée : ".$e->getMessage());

            return ServiceResult::fail('Une erreur est survenue lors de la mise à jour de vos informations.', null, 500);
        }
    }

    /**
     * Accept a structure invitation.
     */
    public function acceptInvitation(int $invitationId, User $user): ServiceResult
    {
        DB::beginTransaction();
        try {
            $invitation = StructureInvitation::where('id', $invitationId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            if ($invitation->status !== 'PENDING' || $invitation->expires_at <= Carbon::now()) {
                DB::rollBack();

                return ServiceResult::fail('Cette invitation n\'est plus valide.', null, 400);
            }

            $invitation->accept();

            $invitation->structure->employees()->attach($user->id, [
                'role' => $invitation->role,
                'status' => 'ACTIVE',
                'joined_at' => Carbon::now(),
                'invitation_message' => $invitation->message,
            ]);

            if ($user->status !== 'ACTIVE') {
                $user->update(['status' => 'ACTIVE']);
            }

            DB::commit();

            return ServiceResult::ok('Invitation acceptée avec succès.', [
                'structure' => $invitation->structure->only(['id', 'name']),
                'role' => $invitation->role,
            ]);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return ServiceResult::fail('Invitation non trouvée.', null, 404);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de l\'acceptation de l\'invitation : '.$e->getMessage());

            return ServiceResult::fail('Impossible d\'accepter l\'invitation.', null, 500);
        }
    }

    /**
     * Respond to an invitation using a token.
     */
    public function respondToInvitation(string $token, string $action): ServiceResult
    {
        DB::beginTransaction();
        try {
            $invitation = StructureInvitation::with(['structure', 'user'])
                ->where('token', $token)
                ->firstOrFail();

            if (! $invitation->isPending()) {
                DB::rollBack();

                return ServiceResult::fail('Cette invitation n\'est plus valide.', null, 400);
            }

            if ($action === 'accept') {
                $invitation->accept();

                $invitation->structure->employees()->attach($invitation->user_id, [
                    'role' => $invitation->role,
                    'status' => 'ACTIVE',
                    'joined_at' => Carbon::now(),
                    'invitation_message' => $invitation->message,
                ]);

                $user = User::find($invitation->user_id);
                if ($user && $user->status !== 'ACTIVE') {
                    $user->update(['status' => 'ACTIVE']);
                }

                $message = 'Invitation acceptée avec succès.';
            } else {
                $invitation->reject();
                $message = 'Invitation refusée avec succès.';
            }

            DB::commit();

            return ServiceResult::ok($message, [
                'action' => $action,
                'structure' => $invitation->structure->only(['id', 'name']),
                'role' => $invitation->role,
            ]);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return ServiceResult::fail('Invitation non trouvée.', null, 404);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors du traitement de la réponse : '.$e->getMessage());

            return ServiceResult::fail('Impossible de traiter votre réponse.', null, 500);
        }
    }
}
