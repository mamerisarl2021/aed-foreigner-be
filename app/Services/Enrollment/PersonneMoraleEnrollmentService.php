<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\ActivityLogAction;
use App\Enums\EnrollmentStatus;
use App\Jobs\ForeignerFinalizedJob;
use App\Jobs\MoraleEmailVerificationJob;
use App\Jobs\SendSmsJob;
use App\Jobs\UploadEnrollmentFilesJob;
use App\Models\EnrollmentRequest;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PersonneMoraleEnrollmentService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /** @var list<string> */
    private const OPEN_MORALE_STATUSES = [
        'AWAITING_CONTACT_VERIFICATION',
        EnrollmentStatus::EnAttente->value,
        EnrollmentStatus::ValidationAgent->value,
        EnrollmentStatus::RejetAgent->value,
        EnrollmentStatus::Approuvee->value,
    ];

    /** @var list<string> */
    private const ENROLLED_MORALE_STATUSES = [
        EnrollmentStatus::Approuvee->value,
        EnrollmentStatus::Enrolee->value,
    ];

    public function submit(User $user, Request $request): ServiceResult
    {
        if (! $this->hasFinalizedPhysiqueEnrollment($user)) {
            return ServiceResult::fail(
                'Vous devez avoir finalisé votre enrôlement personne physique avant de soumettre une demande morale.',
                null,
                403
            );
        }

        if ($this->hasOpenMoraleRequest($user)) {
            return ServiceResult::fail(
                'Vous avez déjà une demande personne morale en cours.',
                null,
                409
            );
        }

        $registrationNumber = strtoupper(trim((string) $request->input('registration_number')));
        $country = strtoupper(trim((string) $request->input('country_of_incorporation')));

        if ($this->isCompanyAlreadyEnrolled($registrationNumber, $country)) {
            return ServiceResult::fail(
                'Une entreprise correspondant à ces informations est déjà enrôlée.',
                null,
                409
            );
        }

        $email = strtolower(trim($request->input('email')));
        $phone = $this->normalizePhone((string) $request->input('phonenumber'));
        $uploadedFiles = $this->uploadMoraleFiles($request);
        $verificationToken = Str::random(64);
        $verificationHours = max(1, (int) config('enrollment.morale.email_verification_hours', 24));

        DB::beginTransaction();
        try {
            $enrollment = EnrollmentRequest::create([
                'email' => $email,
                'phonenumber' => $phone,
                'kyc_data' => [
                    'legal_name' => $request->input('legal_name'),
                    'legal_form' => $request->input('legal_form'),
                    'country_of_incorporation' => $request->input('country_of_incorporation'),
                    'registration_number' => $request->input('registration_number'),
                    'incorporation_date' => $request->input('incorporation_date'),
                    'headquarters_address' => $request->input('headquarters_address'),
                    'activity_sector' => $request->input('activity_sector'),
                    'legal_representative_name' => $request->input('legal_representative_name'),
                    'legal_representative_first_name' => $request->input('legal_representative_first_name'),
                    'is_legal_representative' => filter_var($request->input('is_legal_representative'), FILTER_VALIDATE_BOOLEAN),
                ],
                'documents' => $uploadedFiles,
                'status' => 'AWAITING_CONTACT_VERIFICATION',
                'type' => 'PERSONNE_MORALE',
                'submitted_by_user_id' => $user->id,
                'email_verification_token' => hash('sha256', $verificationToken),
                'verification_deadline_at' => now()->addHours($verificationHours),
            ]);

            DB::commit();

            $this->assignDemandeurAuthentifieRole($user);

            $this->dispatchPostSubmissionJobs($enrollment, $uploadedFiles, $verificationToken);

            $legalName = (string) ($enrollment->kyc_data['legal_name'] ?? $enrollment->email);
            $this->activityLog->record(
                ActivityLogAction::DemandeIdentiteMorale,
                sprintf('%s a initié une demande d\'identité morale pour %s.', ActivityLogService::actorLabel($user), $legalName),
                $user->id,
                $enrollment->id,
            );

            return ServiceResult::ok('Demande enregistrée. Veuillez vérifier l\'email officiel et le téléphone de l\'entreprise.', [
                'enrollment_request_id' => $enrollment->id,
                'status' => $enrollment->status,
                'verification_deadline_at' => $enrollment->verification_deadline_at?->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Morale enrollment submission failed: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->id,
            ]);
            $this->cleanupUploadedFiles($uploadedFiles);

            return ServiceResult::fail('Erreur lors de la soumission de la demande morale.', null, 500);
        }
    }

    public function show(User $user, int $id): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->submitted_by_user_id !== $user->id) {
            return ServiceResult::fail('Accès non autorisé.', null, 403);
        }

        return ServiceResult::ok('Détail de la demande morale.', $enrollment);
    }

    public function verifyEmail(int $id, string $token): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if (! $enrollment->isPersonneMorale()) {
            return ServiceResult::fail('Demande invalide.', null, 422);
        }

        if ($enrollment->email_verified_at !== null) {
            return ServiceResult::ok('Email déjà vérifié.', [
                'enrollment_request_id' => $enrollment->id,
                'email_verified' => true,
                'phone_verified' => $enrollment->phone_verified_at !== null,
                'status' => $enrollment->status,
            ]);
        }

        if ($enrollment->verification_deadline_at !== null && $enrollment->verification_deadline_at->isPast()) {
            return ServiceResult::fail('Le lien de vérification email a expiré.', null, 400);
        }

        if (! hash_equals((string) $enrollment->email_verification_token, hash('sha256', $token))) {
            return ServiceResult::fail('Token de vérification email invalide.', null, 400);
        }

        $enrollment->email_verified_at = now();
        $enrollment->email_verification_token = null;
        $enrollment->save();

        $this->promoteToPendingIfVerified($enrollment);

        return ServiceResult::ok('Email officiel vérifié.', [
            'enrollment_request_id' => $enrollment->id,
            'email_verified' => true,
            'phone_verified' => $enrollment->phone_verified_at !== null,
            'status' => $enrollment->fresh()->status,
        ]);
    }

    public function sendPhoneOtp(User $user, int $id): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->submitted_by_user_id !== $user->id) {
            return ServiceResult::fail('Accès non autorisé.', null, 403);
        }

        if ($enrollment->email_verified_at === null) {
            return ServiceResult::fail('Veuillez d\'abord vérifier l\'email officiel de l\'entreprise.', null, 422);
        }

        if ($enrollment->phone_verified_at !== null) {
            return ServiceResult::ok('Téléphone déjà vérifié.', [
                'phonenumber' => $enrollment->phonenumber,
                'phone_verified' => true,
            ]);
        }

        $otp = (string) random_int(100000, 999999);
        $ttl = max(1, (int) config('enrollment.morale.phone_otp_ttl_minutes', 5));

        Cache::put("morale_otp_phone_{$enrollment->id}", hash('sha256', $otp), now()->addMinutes($ttl));
        Cache::forget("morale_otp_phone_attempts_{$enrollment->id}");
        SendSmsJob::dispatch(
            $enrollment->phonenumber,
            "Votre code OTP AED (entreprise) est : {$otp} (valide {$ttl} minutes)."
        );

        return ServiceResult::ok('OTP envoyé au téléphone officiel de l\'entreprise.', [
            'phonenumber' => $enrollment->phonenumber,
            'channel' => 'phone',
        ]);
    }

    public function verifyPhoneOtp(User $user, int $id, string $otp): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->submitted_by_user_id !== $user->id) {
            return ServiceResult::fail('Accès non autorisé.', null, 403);
        }

        if ($enrollment->email_verified_at === null) {
            return ServiceResult::fail('Veuillez d\'abord vérifier l\'email officiel de l\'entreprise.', null, 422);
        }

        $attemptsKey = "morale_otp_phone_attempts_{$enrollment->id}";
        if ((int) Cache::get($attemptsKey, 0) >= 5) {
            return ServiceResult::fail('Trop de tentatives. Demandez un nouveau code OTP.', null, 429);
        }

        $expected = Cache::get("morale_otp_phone_{$enrollment->id}");
        if (! is_string($expected) || ! hash_equals($expected, hash('sha256', $otp))) {
            Cache::add($attemptsKey, 0, 300);
            Cache::increment($attemptsKey);

            return ServiceResult::fail('OTP invalide ou expiré.', null, 400);
        }

        Cache::forget("morale_otp_phone_{$enrollment->id}");
        Cache::forget($attemptsKey);
        $enrollment->phone_verified_at = now();
        $enrollment->save();

        $this->promoteToPendingIfVerified($enrollment);

        return ServiceResult::ok('Téléphone officiel vérifié. Votre demande entre en file de traitement.', [
            'enrollment_request_id' => $enrollment->id,
            'status' => $enrollment->fresh()->status,
        ]);
    }

    public function normalizePhone(string $phonenumber): string
    {
        return preg_replace('/[^\d+]/', '', trim($phonenumber)) ?? '';
    }

    private function hasFinalizedPhysiqueEnrollment(User $user): bool
    {
        if ($user->status !== 'ACTIVE') {
            return false;
        }

        return EnrollmentRequest::query()
            ->where('type', 'PERSONNE_PHYSIQUE')
            ->where('email', $user->email)
            ->where('status', EnrollmentStatus::Enrolee->value)
            ->exists();
    }

    private function hasOpenMoraleRequest(User $user): bool
    {
        return EnrollmentRequest::query()
            ->where('type', 'PERSONNE_MORALE')
            ->where('submitted_by_user_id', $user->id)
            ->whereIn('status', self::OPEN_MORALE_STATUSES)
            ->exists();
    }

    private function isCompanyAlreadyEnrolled(string $registrationNumber, string $country): bool
    {
        return EnrollmentRequest::query()
            ->where('type', 'PERSONNE_MORALE')
            ->whereIn('status', self::ENROLLED_MORALE_STATUSES)
            ->get()
            ->contains(function (EnrollmentRequest $row) use ($registrationNumber, $country) {
                $kyc = $row->kyc_data ?? [];

                return strtoupper(trim((string) ($kyc['registration_number'] ?? ''))) === $registrationNumber
                    && strtoupper(trim((string) ($kyc['country_of_incorporation'] ?? ''))) === $country;
            });
    }

    /**
     * @return array<string, string|null>
     */
    private function uploadMoraleFiles(Request $request): array
    {
        $uploadedFiles = [];
        $keys = ['trade_register_extract', 'statutes', 'procuration'];

        foreach ($keys as $key) {
            if ($request->hasFile($key)) {
                $uploadedFiles[$key] = $request->file($key)?->store('tmp/enrollments/morale', 'local');
            }
        }

        return $uploadedFiles;
    }

    /**
     * @param  array<string, string|null>  $uploadedFiles
     */
    private function dispatchPostSubmissionJobs(
        EnrollmentRequest $enrollment,
        array $uploadedFiles,
        string $plainVerificationToken,
    ): void {
        $chain = [];

        if ($uploadedFiles !== []) {
            $chain[] = new UploadEnrollmentFilesJob($enrollment->id, $uploadedFiles);
        }

        $verificationLink = config('app.frontend_url')
            ."/morale/verify-email/{$enrollment->id}/{$plainVerificationToken}";

        MoraleEmailVerificationJob::dispatch(
            $enrollment->email,
            (string) ($enrollment->kyc_data['legal_name'] ?? 'Entreprise'),
            $verificationLink,
        );

        $chain[] = new ForeignerFinalizedJob($enrollment->email, 'PERSONNE_MORALE');

        if ($chain !== []) {
            Bus::chain($chain)->dispatch();
        }
    }

    private function assignDemandeurAuthentifieRole(User $user): void
    {
        $role = config('roles.demandeur_authentifie');

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }

    private function promoteToPendingIfVerified(EnrollmentRequest $enrollment): void
    {
        $enrollment->refresh();

        if ($enrollment->status !== 'AWAITING_CONTACT_VERIFICATION') {
            return;
        }

        if (! $enrollment->isContactVerificationComplete()) {
            return;
        }

        $enrollment->status = EnrollmentStatus::EnAttente->value;
        $enrollment->sla_deadline_at = now()->addHours((int) config('enrollment.sla.max_hours', 72));
        $enrollment->save();
    }

    /**
     * @param  array<string, string|null>  $uploadedFiles
     */
    private function cleanupUploadedFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $path) {
            if ($path && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
