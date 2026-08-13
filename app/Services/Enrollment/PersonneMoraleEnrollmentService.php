<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\ActivityLogAction;
use App\Enums\EnrollmentStatus;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\MoraleEmailVerificationJob;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\SendSmsJob;
use App\Jobs\UploadEnrollmentFilesJob;
use App\Models\EnrolledCompany;
use App\Models\EnrollmentRequest;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use App\Support\NotificationRecipient;
use App\Support\TrackingCodeAllocator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        private readonly KycVerificationService $kycVerification,
    ) {}

    /**
     * Une demande morale bloque les suivantes tant qu'elle n'est pas close.
     *
     * @return list<string>
     */
    private static function openMoraleStatuses(): array
    {
        return [
            EnrollmentStatus::AwaitingContactVerification->value,
            ...EnrollmentStatus::open(),
            EnrollmentStatus::ACorriger->value,
        ];
    }

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

        if (! $this->kycVerification->isVerifiedForUser($user)) {
            return ServiceResult::fail('Veuillez d\'abord valider le KYC.', null, 400);
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
        $phone = PhoneNumber::normalize((string) $request->input('phonenumber'));
        $uploadedFiles = $this->uploadMoraleFiles($request);
        $verificationToken = Str::random(64);
        $verificationHours = max(1, (int) config('enrollment.morale.email_verification_hours', 24));
        $kycSession = $this->kycVerification->consumeVerificationForUser($user) ?? [];

        DB::beginTransaction();
        try {
            $enrollment = EnrollmentRequest::create([
                'tracking_code' => TrackingCodeAllocator::next(),
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
                'liveness' => $this->stringOrNull($kycSession['liveness'] ?? null),
                'similarity' => $this->stringOrNull($kycSession['similarity'] ?? null),
                'risk_score' => $this->stringOrNull($kycSession['risk_score'] ?? null),
                'analysis_details' => is_array($kycSession['analysis_details'] ?? null)
                    ? $kycSession['analysis_details']
                    : null,
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
                'demande_id' => $enrollment->id,
                'numero_suivi' => $enrollment->tracking_code,
                'statut' => $enrollment->status->value,
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

    /**
     * @return LengthAwarePaginator<int, EnrollmentRequest>
     */
    public function listOwn(User $user, int $perPage): LengthAwarePaginator
    {
        return EnrollmentRequest::query()
            ->where('type', 'PERSONNE_MORALE')
            ->where('submitted_by_user_id', $user->id)
            ->with('enrolledCompany')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function show(User $user, string $id): ServiceResult
    {
        $enrollment = EnrollmentRequest::query()->with(['submittedBy', 'enrolledCompany'])->findOrFail($id);

        if ($enrollment->submitted_by_user_id !== $user->id) {
            return ServiceResult::fail('Accès non autorisé.', null, 403);
        }

        return ServiceResult::ok('Détail de la demande morale.', $enrollment);
    }

    public function correct(User $user, EnrollmentRequest $enrollment, Request $request): ServiceResult
    {
        if ($enrollment->submitted_by_user_id !== $user->id) {
            return ServiceResult::fail('Accès non autorisé.', null, 403);
        }

        if (! $enrollment->isPersonneMorale() || $enrollment->status !== EnrollmentStatus::ACorriger) {
            return ServiceResult::fail('Cette demande ne peut pas être corrigée.', null, 422);
        }

        if ($enrollment->correction_deadline_at !== null && $enrollment->correction_deadline_at->isPast()) {
            return ServiceResult::fail('Le délai de correction est dépassé.', null, 422);
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

        $uploadedFiles = $this->uploadMoraleFiles($request);
        $existingDocs = is_array($enrollment->documents) ? $enrollment->documents : [];
        $documents = array_merge($existingDocs, array_filter($uploadedFiles));

        $kyc = is_array($enrollment->kyc_data) ? $enrollment->kyc_data : [];
        $kyc['legal_name'] = $request->input('legal_name');
        $kyc['legal_form'] = $request->input('legal_form');
        $kyc['country_of_incorporation'] = $request->input('country_of_incorporation');
        $kyc['registration_number'] = $request->input('registration_number');
        $kyc['incorporation_date'] = $request->input('incorporation_date');
        $kyc['headquarters_address'] = $request->input('headquarters_address');
        $kyc['activity_sector'] = $request->input('activity_sector');
        $kyc['legal_representative_name'] = $request->input('legal_representative_name');
        $kyc['legal_representative_first_name'] = $request->input('legal_representative_first_name');
        $kyc['is_legal_representative'] = filter_var($request->input('is_legal_representative'), FILTER_VALIDATE_BOOLEAN);

        DB::beginTransaction();
        try {
            $enrollment->kyc_data = $kyc;
            $enrollment->documents = $documents;
            $enrollment->status = EnrollmentStatus::EnAttenteAgent;
            $enrollment->assigned_agent_id = null;
            $enrollment->assigned_responsable_id = null;
            $enrollment->agent_avis = null;
            $enrollment->agent_decided_at = null;
            $enrollment->reject_reasons = null;
            $enrollment->review_comments = null;
            $enrollment->correction_deadline_at = null;
            $enrollment->correction_reminder_sent_at = null;
            $enrollment->sla_alert_level = null;
            $enrollment->fill([
                'sla_deadline_at' => now()->addHours((int) config('enrollment.sla.max_hours', 72)),
            ]);
            $enrollment->save();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Morale enrollment correction failed: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->id,
            ]);
            $this->cleanupUploadedFiles($uploadedFiles);

            return ServiceResult::fail('Erreur lors de la correction de la demande morale.', null, 500);
        }

        if ($uploadedFiles !== []) {
            Bus::chain([
                new UploadEnrollmentFilesJob($enrollment->id, $uploadedFiles),
            ])->dispatch();
        }

        $legalName = (string) ($kyc['legal_name'] ?? $enrollment->email);
        $this->activityLog->record(
            ActivityLogAction::CorrectionMorale,
            sprintf('%s a corrigé la demande morale %s.', ActivityLogService::actorLabel($user), $legalName),
            $user->id,
            $enrollment->id,
        );

        return ServiceResult::ok('Dossier corrigé. La demande est de nouveau en file agent.', [
            'demande_id' => $enrollment->id,
            'numero_suivi' => $enrollment->tracking_code,
            'statut' => $enrollment->status->value,
        ]);
    }

    public function verifyEmail(string $id, string $token): ServiceResult
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
                'status' => $enrollment->status->value,
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

        $this->activityLog->record(
            ActivityLogAction::OtpVerifie,
            sprintf('Email officiel vérifié pour la demande morale %s.', $enrollment->id),
            is_string($enrollment->submitted_by_user_id) ? $enrollment->submitted_by_user_id : null,
            $enrollment->id,
            ['context' => 'morale_email'],
        );

        return ServiceResult::ok('Email officiel vérifié.', [
            'enrollment_request_id' => $enrollment->id,
            'email_verified' => true,
            'phone_verified' => $enrollment->phone_verified_at !== null,
            'status' => $enrollment->fresh()->status->value,
        ]);
    }

    public function sendPhoneOtp(User $user, string $id): ServiceResult
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

        $this->activityLog->record(
            ActivityLogAction::OtpEnvoye,
            sprintf('OTP téléphone morale envoyé pour la demande %s.', $enrollment->id),
            $user->id,
            $enrollment->id,
            ['context' => 'morale_phone', 'channel' => 'phone'],
        );

        return ServiceResult::ok('OTP envoyé au téléphone officiel de l\'entreprise.', [
            'phonenumber' => $enrollment->phonenumber,
            'channel' => 'phone',
        ]);
    }

    public function verifyPhoneOtp(User $user, string $id, string $otp): ServiceResult
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

        $this->activityLog->record(
            ActivityLogAction::OtpVerifie,
            sprintf('Téléphone officiel vérifié pour la demande morale %s.', $enrollment->id),
            $user->id,
            $enrollment->id,
            ['context' => 'morale_phone'],
        );

        return ServiceResult::ok('Téléphone officiel vérifié. Votre demande entre en file de traitement.', [
            'enrollment_request_id' => $enrollment->id,
            'status' => $enrollment->fresh()->status->value,
        ]);
    }

    private function hasFinalizedPhysiqueEnrollment(User $user): bool
    {
        if ($user->status !== 'ACTIVE') {
            return false;
        }

        return $user->identities()
            ->where('type', 'IN_PERSON')
            ->where('status', 'APPROVED')
            ->exists();
    }

    private function hasOpenMoraleRequest(User $user): bool
    {
        return EnrollmentRequest::query()
            ->where('type', 'PERSONNE_MORALE')
            ->where('submitted_by_user_id', $user->id)
            ->whereIn('status', self::openMoraleStatuses())
            ->exists();
    }

    private function isCompanyAlreadyEnrolled(string $registrationNumber, string $country): bool
    {
        $enrolled = EnrolledCompany::query()
            ->where('status', EnrolledCompany::STATUS_ACTIVE)
            ->whereRaw('UPPER(TRIM(registration_number)) = ?', [$registrationNumber])
            ->whereRaw('UPPER(TRIM(country_of_incorporation)) = ?', [$country])
            ->exists();

        if ($enrolled) {
            return true;
        }

        return EnrollmentRequest::query()
            ->where('type', 'PERSONNE_MORALE')
            ->whereIn('status', self::ENROLLED_MORALE_STATUSES)
            ->whereDoesntHave('enrolledCompany')
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
        $keys = ['trade_register_extract', 'statutes', 'procuration', 'selfie', 'recto', 'verso'];

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
        if ($uploadedFiles !== []) {
            Bus::chain([
                new UploadEnrollmentFilesJob($enrollment->id, $uploadedFiles),
            ])->dispatch();
        }

        $verificationLink = config('app.frontend_url')
            ."/morale/verify-email/{$enrollment->id}/{$plainVerificationToken}";

        MoraleEmailVerificationJob::dispatch(
            $enrollment->email,
            (string) ($enrollment->kyc_data['legal_name'] ?? 'Entreprise'),
            $verificationLink,
        );
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

        if ($enrollment->status !== EnrollmentStatus::AwaitingContactVerification) {
            return;
        }

        if (! $enrollment->isContactVerificationComplete()) {
            return;
        }

        $enrollment->status = EnrollmentStatus::EnAttenteAgent;
        $enrollment->fill([
            'sla_deadline_at' => now()->addHours((int) config('enrollment.sla.max_hours', 72)),
        ]);
        $enrollment->save();

        $this->dispatchSubmissionConfirmation($enrollment);
    }

    private function dispatchSubmissionConfirmation(EnrollmentRequest $enrollment): void
    {
        $enrollment->loadMissing('submittedBy');
        $submitter = $enrollment->submittedBy;
        $demandeurEmail = $submitter instanceof User ? $submitter->email : null;
        if (! is_string($demandeurEmail) || $demandeurEmail === '') {
            return;
        }

        $legalName = (string) ($enrollment->kyc_data['legal_name'] ?? $enrollment->email);

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Confirmation de soumission — enrôlement personne morale',
            template: NotificationTemplate::ForeignerFinalized,
            recipients: [NotificationRecipient::email($demandeurEmail, [
                'name' => $legalName,
                'numero_suivi' => $enrollment->tracking_code,
            ])],
            variables: [
                'name' => $legalName,
                'demande_id' => $enrollment->id,
                'numero_suivi' => $enrollment->tracking_code,
            ],
            type: 'ENROLEMENT_SUBMITTED',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
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

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}
