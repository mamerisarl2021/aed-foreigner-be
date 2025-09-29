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

    public function finalizeRegistration(Request $request)
    {
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
            'type' => ['required', 'string', 'in:IN_PERSON,ONLINE'],
            'level' => ['required', 'string', 'in:SIMPLE,ADVANCED'],
            'selfie' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:6508',
            'recto' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:2048',
            'verso' => 'nullable|required_if:type,ONLINE|mimes:png,jpeg,jpg|max:2048',
            'similarity' => 'required_if:type,ONLINE|string',
            'liveness' => 'required_if:type,ONLINE|string',
            'exp_date' => 'nullable|string',
            'birth_date' => 'nullable|string',
            'kyc.name' => 'sometimes|string',
            'kyc.first_name' => 'sometimes|string',
            'kyc.phonenumber' => 'sometimes|string',
            'kyc.nationality' => 'sometimes|string',
            'kyc.document_type' => 'sometimes|string|in:PASSPORT,RESIDENCE_PERMIT,OTHER',
            'kyc.document_number' => 'sometimes|string',
            // Structure rules incluses
            'structure.name' => 'sometimes|required|string|max:255',
            'structure.ifu' => 'sometimes|required|string',
            'structure.attachements' => 'sometimes|array',
            'structure.attachements.*.name' => 'required_with:structure.attachements|string|max:255',
            'structure.attachements.*.status' => 'sometimes|in:SENT,VALIDATED,WAITING_MANAGER,REJECTED',
            'structure.attachements.*.message' => 'sometimes|string|nullable',
            'structure.attachements.*.files' => 'sometimes|array',
            'structure.attachements.*.files.*' => 'sometimes|file|mimes:pdf,docx,doc,xls,mp4,png,jpeg,jpg|max:10000',
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
                'type' => $request->input('type'),
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
                'level' => $request->input('level'),
                'user_id' => $user->id,
                'status' => 'PENDING',
            ]);

            // Création rapide de l'abonnement
            $this->createSubscriptionRecord($subscriptionValidation['data'], $user->id);

            $user->assignRole('client');

            // Structure (création rapide sans upload)
            $structureId = null;
            $structurePayload = $request->input('structure');
            if (is_array($structurePayload) && !empty($structurePayload['name']) && !empty($structurePayload['ifu'])) {
                $structure = Structure::create([
                    'name' => $structurePayload['name'],
                    'ifu' => $structurePayload['ifu'],
                    'manager_id' => $user->id,
                    'status' => 'PENDING',
                    'searchbase' => "ou=Employees-Virtual ID,ou={$structurePayload['name']},o=GOUV,c=BJ",
                ]);
                $structureId = $structure->id;

                // Créer les attachments sans fichiers (à traiter en async)
                if (!empty($structurePayload['attachements']) && is_array($structurePayload['attachements'])) {
                    foreach ($structurePayload['attachements'] as $idx => $attachmentData) {
                        Attachment::create([
                            'name' => $attachmentData['name'] ?? ('Pièce ' . ($idx + 1)),
                            'status' => 'PROCESSING', // Statut temporaire
                            'message' => $attachmentData['message'] ?? null,
                            'structure_id' => $structure->id,
                        ]);
                    }
                }
            }

            Cache::forget('foreigner_otp_' . $email);
            Cache::forget('foreigner_otp_valid_' . $email);
            $pending->update(['status' => 'COMPLETED']);

            DB::commit();

            // ÉTAPE 4: Jobs asynchrones APRÈS transaction réussie
            $this->dispatchPostRegistrationJobs($user, $request, $structureId, $uploadedFiles);

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
