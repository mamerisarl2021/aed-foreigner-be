<?php

namespace App\Http\Controllers;

use App\Jobs\ForeignerFinalizedJob;
use App\Jobs\ForeignerInitRegistrationJob;
use App\Jobs\ForeignerOtpJob;
use App\Jobs\AdvancedIdRequestJob;
use App\Jobs\PlanifiedEmailJob;
use App\Jobs\ProcessStructureFilesJob;
use App\Models\Identity;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Throwable;
// Ajouts pour la création d'entreprise
use App\Models\Structure;
use App\Models\Attachment;
use App\Models\StructurePackage;
use App\Traits\AttachmentTrait;

class ForeignerEnrollmentController extends BaseController
{
    use AttachmentTrait;

    /**
     * @OA\Post(
     *      path="/api/foreigner/send-otp",
     *      operationId="sendOtp",
     *      tags={"Enrollment"},
     *      summary="Send OTP to email",
     *      description="Sends an OTP to the provided email address for verification.",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"email"},
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com")
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="OTP sent successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="OTP envoyé à votre adresse email."),
     *              @OA\Property(property="data", type="object",
     *                  @OA\Property(property="email", type="string", example="user@example.com")
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response=422,
     *          description="Validation error"
     *      )
     * )
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Données invalides.', $validator->errors(), 422);
        }

        $email = strtolower(trim($request->input('email')));
        $otp = (string) random_int(100000, 999999);
        $ttl = 5; // minutes

        Cache::put('foreigner_otp_' . $email, $otp, now()->addMinutes($ttl));
        ForeignerOtpJob::dispatch($email, $otp, $ttl);

        return $this->sendResponse('OTP envoyé à votre adresse email.', ['email' => $email]);
    }

    /**
     * @OA\Post(
     *      path="/api/foreigner/verify-otp",
     *      operationId="verifyOtp",
     *      tags={"Enrollment"},
     *      summary="Verify OTP",
     *      description="Verifies the OTP sent to the email.",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"email", "otp"},
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *              @OA\Property(property="otp", type="string", example="123456")
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="OTP verified successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="OTP vérifié.")
     *          )
     *      ),
     *      @OA\Response(
     *          response=400,
     *          description="Invalid OTP"
     *      )
     * )
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|string',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Données invalides.', $validator->errors(), 422);
        }

        $email = strtolower(trim($request->input('email')));
        $otp = $request->input('otp');
        $expected = Cache::get('foreigner_otp_' . $email);
        if (!$expected || $expected !== $otp) {
            return $this->sendError('OTP invalide ou expiré.', null, 400);
        }

        Cache::put('foreigner_otp_valid_' . $email, true, now()->addMinutes(10));
        return $this->sendResponse('OTP vérifié.', ['email' => $email]);
    }

    /**
     * @OA\Post(
     *      path="/api/foreigner/register/init",
     *      operationId="initRegistration",
     *      tags={"Enrollment"},
     *      summary="Initialize Registration",
     *      description="Initializes the registration process, returning a registration token.",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  required={"email"},
     *                  @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *                  @OA\Property(property="profile", type="string", format="binary")
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Registration initialized",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="data", type="object",
     *                  @OA\Property(property="registration_token", type="string"),
     *                  @OA\Property(property="expires_at", type="string", format="date-time"),
     *                  @OA\Property(property="link", type="string")
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response=400,
     *          description="OTP not verified"
     *      )
     * )
     */
    public function initRegistration(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'profile' => 'sometimes|file|mimes:png,jpeg,jpg|max:2048',
            // 'transaction_id' => 'required|string',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Données invalides.', $validator->errors(), 422);
        }

        $email = strtolower(trim($request->input('email')));
        if (!Cache::get('foreigner_otp_valid_' . $email)) {
            return $this->sendError("Veuillez d'abord vérifier votre OTP.", null, 400);
        }

        $profilePath = null;
        if ($request->hasFile('profile')) {
            $profilePath = Storage::cloud()->put('profiles', $request->file('profile'));
        }

        $token = Str::random(64);
        $expiresAt = Carbon::now()->addHours(6);

        // $transactionId = $request->input('transaction_id');
        // $subscriptionValidation = $this->validateSubscription($transactionId);
        // if (!$subscriptionValidation['status']) {
        //     return $this->sendError($subscriptionValidation['message'], $subscriptionValidation['data'], 500);
        // }

        PendingRegistration::create([
            'npi' => '',
            'registration_token' => $token,
            'email' => $email,
            'status' => 'PENDING',
            'expires_at' => $expiresAt,
            'profile_path' => $profilePath,
            'user_data' => [
                'is_foreigner' => true,
                'form' => [],
                'cached_data' => [],
            ],
        ]);

        $front = env('FRONT_URL', config('app.url'));
        $link = rtrim($front, '/') . '/register/foreigner/' . $token;
        ForeignerInitRegistrationJob::dispatch($email, $link);

        return $this->sendResponse('Inscription initialisée.', [
            'registration_token' => $token,
            'expires_at' => $expiresAt->toISOString(),
            'link' => $link,
        ]);
    }

    /**
     * @OA\Post(
     *      path="/api/foreigner/register/finalize",
     *      operationId="finalizeRegistration",
     *      tags={"Enrollment"},
     *      summary="Finalize Registration",
     *      description="Finalizes the registration with full details and documents.",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *              @OA\Schema(
     *                  required={"registration_token", "transaction_id"},
     *                  @OA\Property(property="registration_token", type="string"),
     *                  @OA\Property(property="transaction_id", type="string"),
     *                  @OA\Property(property="selfie", type="string", format="binary", description="Required"),
     *                  @OA\Property(property="recto", type="string", format="binary", description="Required"),
     *                  @OA\Property(property="verso", type="string", format="binary", description="Required"),
     *                  @OA\Property(property="similarity", type="string", description="Required"),
     *                  @OA\Property(property="liveness", type="string", description="Required"),
     *                  @OA\Property(property="exp_date", type="string", format="date", description="Expiration date of document"),
     *                  @OA\Property(property="birth_date", type="string", format="date", description="Birth date"),
     *                  @OA\Property(property="kyc[name]", type="string"),
     *                  @OA\Property(property="kyc[first_name]", type="string"),
     *                  @OA\Property(property="kyc[phonenumber]", type="string"),
     *                  @OA\Property(property="kyc[nationality]", type="string"),
     *                  @OA\Property(property="kyc[document_type]", type="string", enum={"PASSPORT", "RESIDENCE_PERMIT", "OTHER"}),
     *                  @OA\Property(property="kyc[document_number]", type="string")
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Registration finalized",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="data", type="object",
     *                  @OA\Property(property="user_id", type="integer"),
     *                  @OA\Property(property="phonenumber", type="string")
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response=422,
     *          description="Validation error"
     *      ),
     *      @OA\Response(
     *          response=500,
     *          description="Internal server error"
     *      )
     * )
     */
    public function finalizeRegistration(Request $request)
    {
        // Force type and level for Foreigner flow
        $request->merge([
            'type' => 'ONLINE',
            'level' => 'ADVANCED'
        ]);

        // Validation préliminaire AVANT transaction
        $basic = Validator::make($request->all(), [
            'registration_token' => 'required|string|exists:pending_registrations,registration_token',
        ]);
        if ($basic->fails()) {
            return $this->sendError('Données invalides.', $basic->errors(), 422);
        }

        $pending = PendingRegistration::where('registration_token', $request->registration_token)
            ->where('status', 'PENDING')
            ->where('expires_at', '>', Carbon::now())
            ->firstOrFail();

        $isForeigner = $pending->user_data['is_foreigner'] ?? false;
        if (!$isForeigner) {
            return $this->sendError('Flux non étranger non pris en charge ici.', null, 400);
        }

        // Validation complète AVANT transaction
        $rules = [
            'transaction_id' => 'required|string',
            // 'type' and 'level' are auto-set
            'selfie' => 'nullable|required|mimes:png,jpeg,jpg|max:6508',
            'recto' => 'nullable|required|mimes:png,jpeg,jpg|max:2048',
            'verso' => 'nullable|required|mimes:png,jpeg,jpg|max:2048',
            'similarity' => 'required|string',
            'liveness' => 'required|string',
            'exp_date' => 'nullable|string',
            'birth_date' => 'nullable|string',
            'kyc.name' => 'sometimes|string',
            'kyc.first_name' => 'sometimes|string',
            'kyc.phonenumber' => 'sometimes|string',
            'kyc.nationality' => 'sometimes|string',
            'kyc.document_type' => 'sometimes|string|in:PASSPORT,RESIDENCE_PERMIT,OTHER',
            'kyc.document_number' => 'sometimes|string',
        ];
        
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->sendError('Données invalides.', $validator->errors(), 422);
        }

        $email = $pending->email;
        if (!Cache::get('foreigner_otp_valid_' . $email)) {
            return $this->sendError("Veuillez d'abord vérifier votre OTP.", null, 400);
        }

        // ÉTAPE 1: Upload des fichiers AVANT transaction (opération la plus coûteuse)
        $uploadedFiles = $this->uploadFilesAsync($request);
        
        // ÉTAPE 2: Validation de l'abonnement AVANT transaction
        $subscriptionValidation = $this->validateSubscription($request->input('transaction_id'));
        if (!$subscriptionValidation['status']) {
            return $this->sendError($subscriptionValidation['message'], $subscriptionValidation['data'], 500);
        }

        // ÉTAPE 3: Transaction DB rapide (uniquement création des enregistrements)
        DB::beginTransaction();
        try {
            $kyc = $request->input('kyc', []);
            $user = User::create([
                'npi' => null,
                'email' => $email,
                'name' => $kyc['name'] ?? null,
                'first_name' => $kyc['first_name'] ?? null,
                'phonenumber' => $kyc['phonenumber'] ?? null,
                'nationality' => $kyc['nationality'] ?? null,
                'profile' => $pending->profile_path,
            ]);

            Identity::create([
                'type' => 'ONLINE', // Hardcoded
                'proof' => json_encode([
                    'selfiePath' => $uploadedFiles['selfie'] ?? null,
                    'rectoPath' => $uploadedFiles['recto'] ?? null,
                    'versoPath' => $uploadedFiles['verso'] ?? null,
                    'liveness' => $request->input('liveness'),
                    'similarity' => $request->input('similarity'),
                    'exp_date' => $request->input('exp_date', ''),
                    'birth_date' => $request->input('birth_date', ''),
                    'document_type' => $kyc['document_type'] ?? null,
                    'document_number' => $kyc['document_number'] ?? null,
                    'nationality' => $kyc['nationality'] ?? null,
                ]),
                'level' => 'ADVANCED', // Hardcoded
                'user_id' => $user->id,
                'status' => 'PENDING',
            ]);

            // Création rapide de l'abonnement
            $this->createSubscriptionRecord($subscriptionValidation['data'], $user->id);

            $user->assignRole('client');

            // NOTE: Structure creation logic REMOVED to separate P1 (User) from P2 (Company)

            Cache::forget('foreigner_otp_' . $email);
            Cache::forget('foreigner_otp_valid_' . $email);
            $pending->update(['status' => 'COMPLETED']);

            DB::commit();

            // ÉTAPE 4: Jobs asynchrones APRÈS transaction réussie
            $this->dispatchPostRegistrationJobs($user, $request, null, $uploadedFiles);

            return $this->sendResponse('Inscription finalisée.', [
                'user_id' => $user->id,
                'phonenumber' => $user->phonenumber,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Registration failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'email' => $email,
            ]);
            
            // Nettoyer les fichiers uploadés en cas d'erreur
            $this->cleanupUploadedFiles($uploadedFiles);
            
            return $this->sendError('Erreur lors de la finalisation.', null, 500);
        }
    }

    private function uploadFilesAsync(Request $request): array
    {
        $uploadedFiles = [];
        
        if ($request->input('type') === 'ONLINE') {
            // Upload en parallèle si possible, sinon séquentiel optimisé
            if ($request->hasFile('selfie')) {
                $uploadedFiles['selfie'] = Storage::cloud()->put('selfies', $request->file('selfie'));
            }
            if ($request->hasFile('recto')) {
                $uploadedFiles['recto'] = Storage::cloud()->put('images', $request->file('recto'));
            }
            if ($request->hasFile('verso')) {
                $uploadedFiles['verso'] = Storage::cloud()->put('images', $request->file('verso'));
            }
        }
        
        return $uploadedFiles;
    }

    private function validateSubscription(string $transactionId): array
    {
        try {
            // $state = $this->kkiaPayment($transactionId); // Pour la prod
            $state = collect([json_encode(['package' => 7, 'amount' => 5000])]); // Test

            $payload = json_decode($state[0], true);

            if (StructurePackage::where('id', $payload['package'])
                ->where('prix', (int) $payload['amount'])
                ->count() != 1
            ) {
                return [
                    'status' => false,
                    'message' => 'Ooops tentative de fraude détectée!',
                    'data' => [],
                ];
            }

            return [
                'status' => true,
                'message' => 'Abonnement validé.',
                'data' => $payload,
            ];
        } catch (Throwable $e) {
            Log::error('Subscription validation failed: ' . $e->getMessage());
            return [
                'status' => false,
                'message' => "Échec de la validation de l'abonnement.",
                'data' => null,
            ];
        }
    }

    private function createSubscriptionRecord(array $payload, string $userId): void
    {
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
    }

    private function dispatchPostRegistrationJobs(User $user, Request $request, ?int $structureId, array $uploadedFiles): void
    {
        $type = $request->input('type');
        
        // Jobs d'email
        if ($type === 'IN_PERSON') {
            PlanifiedEmailJob::dispatch($user->email);
        } else {
            AdvancedIdRequestJob::dispatch($user->email);
        }
        ForeignerFinalizedJob::dispatch($user->email, $type);
        
        // Trigger Regula Analysis for ONLINE enrollments
        if ($type === 'ONLINE') {
            Log::info("Dispatching Regula analysis for user {$user->id}");
            // Retrieve identity created in this transaction. 
            // Since we don't pass identity ID to this method, we might need to find it 
            // or better, pass identity ID to this method. 
            // However, looking at the code, we can find it via user_id.
            $identity = Identity::where('user_id', $user->id)->latest()->first();
            if ($identity) {
                \App\Jobs\RegulaAnalysisJob::dispatch($identity->id);
            }
        }

        // Job pour traiter les fichiers de structure en async si nécessaire
        if ($structureId && $request->has('structure.attachements')) {
            ProcessStructureFilesJob::dispatch($structureId, $request->input('structure.attachements'), $request->allFiles());
        }
    }

    private function cleanupUploadedFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $path) {
            if ($path && Storage::cloud()->exists($path)) {
                Storage::cloud()->delete($path);
            }
        }
    }
}
