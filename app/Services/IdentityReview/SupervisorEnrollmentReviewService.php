<?php

declare(strict_types=1);

namespace App\Services\IdentityReview;

use App\Contracts\EnrollmentEventPublisherInterface;
use App\DataTransferObjects\EmailNotificationData;
use App\Enums\ActivityLogAction;
use App\Enums\AgentAvis;
use App\Enums\EnrollmentStatus;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Enums\SupervisorDecision;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\WelcomeUserJob;
use App\Models\EnrolledCompany;
use App\Models\EnrollmentRejectMotif;
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\PKI\TrustedXClientService;
use App\Services\ServiceResult;
use App\Support\CompanyIdentifiantAllocator;
use App\Support\NotificationRecipient;
use App\Support\NpiAllocator;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupervisorEnrollmentReviewService
{
    private const DECISION_NON_PRISE_EN_CHARGE = 'La décision doit d\'abord être prise en charge.';

    public function __construct(
        private readonly TrustedXClientService $trustedXClient,
        private readonly EnrollmentEventPublisherInterface $events,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function claimValidation(EnrollmentRequest $enrollment, string $responsableId): ServiceResult
    {
        if ($enrollment->status !== EnrollmentStatus::EnAttenteResponsable) {
            return ServiceResult::fail('Seules les demandes en attente de validation responsable peuvent être prises en charge.', null, 422);
        }

        if ($enrollment->assigned_responsable_id !== null) {
            return ServiceResult::fail('Cette décision est déjà assignée à un responsable.', null, 422);
        }

        $enrollment->assigned_responsable_id = $responsableId;
        $enrollment->status = EnrollmentStatus::EnCoursResponsable;
        $enrollment->save();
        $enrollment->load(['assignedAgent', 'assignedResponsable', 'submittedBy']);

        $this->activityLog->record(
            ActivityLogAction::PriseEnChargeResponsable,
            sprintf(
                '%s a pris en charge la validation de la demande %s.',
                ActivityLogService::actorLabel($enrollment->assignedResponsable),
                $enrollment->tracking_code ?? $enrollment->id
            ),
            $responsableId,
            $enrollment->id,
        );

        $this->events->publish('responsable_assigned', [
            'demande_id' => $enrollment->id,
            'assigned_responsable_id' => $responsableId,
            'statut' => $enrollment->status->value,
        ]);

        return ServiceResult::ok('Décision prise en charge.', $enrollment);
    }

    /**
     * @param  list<string>|null  $motif
     */
    public function validation(
        EnrollmentRequest $enrollment,
        string|SupervisorDecision $decision,
        ?string $commentaire = null,
        ?string $supervisorId = null,
        ?array $motif = null,
    ): ServiceResult {
        $decision = $decision instanceof SupervisorDecision
            ? $decision
            : SupervisorDecision::tryFrom($decision);

        if ($decision === null) {
            return ServiceResult::fail('Décision de validation invalide.', null, 422);
        }

        return match ($decision) {
            SupervisorDecision::Approuvee => $this->supervisorApprove($enrollment, (string) $supervisorId),
            SupervisorDecision::RejetConfirme => $this->supervisorConfirmReject($enrollment, $supervisorId),
            SupervisorDecision::RetourAgent => $this->supervisorReturnToAgent($enrollment, $commentaire, $motif, $supervisorId),
        };
    }

    public function supervisorApprove(EnrollmentRequest $enrollment, string $supervisorId): ServiceResult
    {
        // La décision exige la prise en charge (EN_COURS_RESPONSABLE) *et* un avis
        // favorable : approuver un dossier proposé au rejet passe par RETOUR_AGENT.
        if ($enrollment->status !== EnrollmentStatus::EnCoursResponsable) {
            return ServiceResult::fail(self::DECISION_NON_PRISE_EN_CHARGE, null, 422);
        }

        if ($enrollment->agent_avis !== AgentAvis::Favorable) {
            return ServiceResult::fail('L\'avis de l\'agent n\'est pas favorable.', null, 422);
        }

        if ($enrollment->isPersonneMorale()) {
            return $this->supervisorApproveMorale($enrollment, $supervisorId);
        }

        DB::beginTransaction();
        try {
            $user = User::where('email', $enrollment->email)->first();

            if (! $user) {
                $user = User::create([
                    'email' => $enrollment->email,
                    'name' => $enrollment->kyc_data['name'] ?? '',
                    'first_name' => $enrollment->kyc_data['first_name'] ?? '',
                    'sexe' => $enrollment->kyc_data['sexe'] ?? $enrollment->kyc_data['sex'] ?? null,
                    'phonenumber' => $enrollment->phonenumber,
                    'nationality' => $enrollment->kyc_data['nationality'] ?? '',
                    'profile' => $enrollment->documents['profile'] ?? null,
                    'status' => 'CREATED',
                ]);
            }

            if (! $user->npi) {
                $user->npi = NpiAllocator::nextForeignerNpi();
                $user->save();
            }

            $user->assignRole('client');

            Identity::create([
                'user_id' => $user->id,
                'type' => 'IN_PERSON',
                'level' => 'ADVANCED',
                'proof' => [
                    'selfiePath' => $enrollment->documents['selfie'] ?? '',
                    'rectoPath' => $enrollment->documents['recto'] ?? '',
                    'versoPath' => $enrollment->documents['verso'] ?? '',
                    'liveness' => $enrollment->liveness,
                    'similarity' => $enrollment->similarity,
                    'document_type' => $enrollment->kyc_data['document_type'] ?? '',
                    'document_number' => $enrollment->kyc_data['document_number'] ?? '',
                    'nationality' => $enrollment->kyc_data['nationality'] ?? '',
                ],
                'status' => 'APPROVED',
                'risk_score' => $enrollment->risk_score,
                'analysis_details' => is_array($enrollment->analysis_details)
                    ? $enrollment->analysis_details
                    : [],
                'assigned_agent_id' => $supervisorId,
            ]);

            if ($user->trustedx_registered_at === null) {
                $registerResult = $this->trustedXClient->register(['data' => ['npi' => $user->npi]]);
                if (! ($registerResult['status'] ?? false)) {
                    DB::rollBack();

                    return ServiceResult::fail(
                        $registerResult['message'] ?? 'Échec de l\'enrôlement TrustedX.',
                        null,
                        400
                    );
                }
                $user->trustedx_registered_at = now();
                $user->save();
            }

            $finalisationToken = Str::random(60);
            PasswordResetToken::updateOrCreate(
                ['npi' => $user->npi, 'type' => 'finalisation'],
                ['token' => hash('sha256', $finalisationToken), 'created_at' => Carbon::now()]
            );

            $link = $this->finalisationInviteLink($finalisationToken);
            WelcomeUserJob::dispatch($user->email, $user, $link, true);

            $enrollment->status = EnrollmentStatus::Approuvee;
            $enrollment->save();

            $this->activityLog->record(
                ActivityLogAction::ValidationResponsable,
                sprintf('La demande %s a été approuvée par le responsable.', $enrollment->tracking_code ?? $enrollment->id),
                $supervisorId,
                $enrollment->id,
            );

            $this->events->publish('approved', [
                'demande_id' => $enrollment->id,
                'npi' => $user->npi,
                'statut' => $enrollment->status->value,
            ]);

            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: 'Invitation à finaliser votre enrôlement',
                template: NotificationTemplate::SendInitLink,
                recipients: [
                    NotificationRecipient::email($enrollment->email, [
                        'name' => $enrollment->applicantDisplayName(),
                        'link' => $link,
                        'npi' => $user->npi,
                        'numero_suivi' => $enrollment->tracking_code,
                    ]),
                ],
                variables: [
                    'name' => $enrollment->applicantDisplayName(),
                    'link' => $link,
                    'npi' => $user->npi,
                    'numero_suivi' => $enrollment->tracking_code,
                ],
                type: 'ENROLEMENT_APPROVED',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));

            DB::commit();

            return ServiceResult::ok('Demande approuvée. Invitation de finalisation envoyée.', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status->value,
                'npi' => $user->npi,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Supervisor approve failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ServiceResult::fail("Erreur lors de l'approbation responsable.", null, 500);
        }
    }

    public function supervisorConfirmReject(EnrollmentRequest $enrollment, ?string $supervisorId = null): ServiceResult
    {
        if ($enrollment->status !== EnrollmentStatus::EnCoursResponsable) {
            return ServiceResult::fail(self::DECISION_NON_PRISE_EN_CHARGE, null, 422);
        }

        if ($enrollment->agent_avis !== AgentAvis::Defavorable) {
            return ServiceResult::fail('L\'avis de l\'agent n\'est pas défavorable.', null, 422);
        }

        DB::beginTransaction();
        try {
            if ($enrollment->isPersonneMorale()) {
                $days = max(1, (int) config('enrollment.morale.correction_days', 7));
                $enrollment->status = EnrollmentStatus::ACorriger;
                $enrollment->fill([
                    'correction_deadline_at' => now()->addDays($days),
                    'correction_reminder_sent_at' => null,
                ]);
                $enrollment->save();
            } else {
                $enrollment->status = EnrollmentStatus::Rejetee;
                $enrollment->save();
            }

            $this->activityLog->record(
                ActivityLogAction::RejetConfirme,
                sprintf('Le rejet de la demande %s a été confirmé par le responsable.', $enrollment->tracking_code ?? $enrollment->id),
                $supervisorId,
                $enrollment->id,
            );

            $this->events->publish('rejected', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status->value,
            ]);

            $this->dispatchRejectNotification($enrollment);

            DB::commit();

            $message = $enrollment->isPersonneMorale()
                ? 'Rejet notifié. Le demandeur peut corriger le dossier avant l\'échéance.'
                : 'Rejet confirmé et notifié au demandeur.';

            return ServiceResult::ok($message, [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status->value,
                'correction_deadline_at' => $enrollment->correction_deadline_at?->toIso8601String(),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Supervisor confirm reject failed: '.$e->getMessage());

            return ServiceResult::fail('Erreur lors de la confirmation du rejet.', null, 500);
        }
    }

    /**
     * @param  list<string>|null  $motif
     */
    public function supervisorReturnToAgent(EnrollmentRequest $enrollment, ?string $commentaire, ?array $motif = null, ?string $supervisorId = null): ServiceResult
    {
        if ($enrollment->status !== EnrollmentStatus::EnCoursResponsable) {
            return ServiceResult::fail(self::DECISION_NON_PRISE_EN_CHARGE, null, 422);
        }

        $enrollment->loadMissing('assignedAgent');
        $this->dispatchReturnedToAgentNotification($enrollment, $commentaire, $motif);

        $enrollment->status = EnrollmentStatus::EnAttenteAgent;
        $enrollment->assigned_agent_id = null;
        $enrollment->assigned_responsable_id = null;
        $enrollment->review_comments = $commentaire;
        $enrollment->returned_at = now();
        // Le retour annule l'avis : la demande repart sans décision d'aucun
        // niveau, et `returned_at` seul la distingue d'une demande jamais instruite.
        $enrollment->agent_avis = null;
        $enrollment->agent_decided_at = null;
        $enrollment->reject_reasons = null;

        if ($motif !== null) {
            $enrollment->return_reasons = $motif;
            $enrollment->reject_stage = 'RESPONSABLE';
        }

        $enrollment->save();

        $this->activityLog->record(
            ActivityLogAction::RetourAgent,
            sprintf('La demande %s a été renvoyée à l\'agent par le responsable.', $enrollment->tracking_code ?? $enrollment->id),
            $supervisorId,
            $enrollment->id,
        );

        $this->events->publish('status_changed', [
            'demande_id' => $enrollment->id,
            'statut' => $enrollment->status->value,
            'commentaire' => $commentaire,
        ]);

        return ServiceResult::ok('Demande renvoyée à l\'agent.', [
            'demande_id' => $enrollment->id,
            'statut' => $enrollment->status->value,
        ]);
    }

    private function supervisorApproveMorale(EnrollmentRequest $enrollment, string $supervisorId): ServiceResult
    {
        $representative = User::find($enrollment->submitted_by_user_id);
        if (! $representative) {
            return ServiceResult::fail('Représentant introuvable.', null, 422);
        }

        DB::beginTransaction();
        try {
            $kyc = is_array($enrollment->kyc_data) ? $enrollment->kyc_data : [];
            $identifiant = CompanyIdentifiantAllocator::next();

            $company = EnrolledCompany::query()->create([
                'identifiant' => $identifiant,
                'enrollment_request_id' => $enrollment->id,
                'manager_user_id' => $representative->id,
                'legal_name' => (string) ($kyc['legal_name'] ?? $enrollment->email),
                'legal_form' => $kyc['legal_form'] ?? null,
                'country_of_incorporation' => (string) ($kyc['country_of_incorporation'] ?? ''),
                'registration_number' => (string) ($kyc['registration_number'] ?? ''),
                'incorporation_date' => filled($kyc['incorporation_date'] ?? null) ? $kyc['incorporation_date'] : null,
                'headquarters_address' => (string) ($kyc['headquarters_address'] ?? ''),
                'activity_sector' => (string) ($kyc['activity_sector'] ?? ''),
                'legal_representative_name' => (string) ($kyc['legal_representative_name'] ?? ''),
                'legal_representative_first_name' => (string) ($kyc['legal_representative_first_name'] ?? ''),
                'company_email' => $enrollment->email,
                'company_phone' => $enrollment->phonenumber,
                'documents' => $enrollment->documents,
                'status' => EnrolledCompany::STATUS_ACTIVE,
                'approved_at' => now(),
                'approved_by_user_id' => $supervisorId,
            ]);

            Identity::create([
                'user_id' => $representative->id,
                'type' => 'PERSONNE_MORALE',
                'level' => 'ADVANCED',
                'proof' => [
                    'company' => $kyc,
                    'documents' => $enrollment->documents,
                    'company_email' => $enrollment->email,
                    'company_phone' => $enrollment->phonenumber,
                    'enrollment_request_id' => $enrollment->id,
                    'enrolled_company_id' => $company->id,
                    'identifiant' => $identifiant,
                    'company_manager' => [
                        'user_id' => $representative->id,
                        'nom' => $representative->name,
                        'prenom' => $representative->first_name,
                        'email' => $representative->email,
                    ],
                ],
                'status' => 'APPROVED',
                'assigned_agent_id' => $supervisorId,
            ]);

            $legalName = (string) ($kyc['legal_name'] ?? $enrollment->email);
            $this->dispatchMoraleApprovedNotifications($enrollment, $representative, $identifiant, $legalName);

            $enrollment->status = EnrollmentStatus::Approuvee;
            $enrollment->save();

            $this->activityLog->record(
                ActivityLogAction::ValidationResponsable,
                sprintf('La demande morale %s a été approuvée par le responsable.', $enrollment->tracking_code ?? $enrollment->id),
                $supervisorId,
                $enrollment->id,
            );

            $this->events->publish('approved', [
                'demande_id' => $enrollment->id,
                'identifiant' => $identifiant,
                'statut' => $enrollment->status->value,
            ]);

            DB::commit();

            return ServiceResult::ok('Demande morale approuvée.', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status->value,
                'identifiant' => $identifiant,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Supervisor approve morale failed: '.$e->getMessage());

            return ServiceResult::fail("Erreur lors de l'approbation morale.", null, 500);
        }
    }

    private function dispatchMoraleApprovedNotifications(
        EnrollmentRequest $enrollment,
        User $representative,
        string $identifiant,
        string $legalName,
    ): void {
        $variables = [
            'name' => $legalName,
            'raison_sociale' => $legalName,
            'identifiant' => $identifiant,
            'numero_suivi' => $enrollment->tracking_code,
        ];

        $recipients = [];
        $seen = [];
        foreach ([$representative->email, $enrollment->email] as $email) {
            if ($email === '') {
                continue;
            }
            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $recipients[] = NotificationRecipient::email($email, $variables);
        }

        if ($recipients === []) {
            return;
        }

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Votre demande personne morale a été approuvée',
            template: NotificationTemplate::MoraleApproved,
            recipients: $recipients,
            variables: $variables,
            type: 'MORALE_APPROVED',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }

    private function dispatchRejectNotification(EnrollmentRequest $enrollment): void
    {
        $enrollment->loadMissing('submittedBy');
        $reasons = $this->motifTitles($enrollment->reject_reasons);
        $recipientName = $enrollment->applicantDisplayName();
        $demandeurEmail = $enrollment->submittedBy instanceof User
            ? $enrollment->submittedBy->email
            : $enrollment->email;

        if ($demandeurEmail === '') {
            $demandeurEmail = $enrollment->email;
        }

        $isMorale = $enrollment->isPersonneMorale();
        $deadline = $enrollment->correction_deadline_at?->toIso8601String();

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: $isMorale
                ? 'Votre demande personne morale est à corriger'
                : 'Votre demande d\'enrôlement a été rejetée',
            template: $isMorale
                ? NotificationTemplate::MoraleCorrectionRequired
                : NotificationTemplate::IdentityRejected,
            recipients: [
                NotificationRecipient::email($demandeurEmail, [
                    'name' => $recipientName,
                    'reasons' => $reasons,
                ]),
            ],
            variables: [
                'name' => $recipientName,
                'reasons' => $reasons,
                'comments' => $enrollment->review_comments,
                'correction_deadline_at' => $deadline,
                'numero_suivi' => $enrollment->tracking_code,
                'stage' => $enrollment->reject_stage,
            ],
            type: $isMorale ? 'MORALE_CORRECTION_REQUIRED' : 'ENROLEMENT_REJECTED',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }

    /**
     * @param  list<string>|null  $motif
     */
    private function dispatchReturnedToAgentNotification(
        EnrollmentRequest $enrollment,
        ?string $commentaire,
        ?array $motif,
    ): void {
        $agent = $enrollment->assignedAgent;
        if (! $agent instanceof User || $agent->email === '') {
            return;
        }

        $reasons = $this->motifTitles($motif ?? $enrollment->return_reasons);

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Une demande vous a été renvoyée par le responsable',
            template: NotificationTemplate::EnrollmentReturnedToAgent,
            recipients: [
                NotificationRecipient::email($agent->email, [
                    'enrollment_id' => $enrollment->id,
                    'applicant_email' => $enrollment->email,
                ]),
            ],
            variables: [
                'enrollment_id' => $enrollment->id,
                'applicant_email' => $enrollment->email,
                'reasons' => $reasons,
                'comments' => $commentaire,
                'numero_suivi' => $enrollment->tracking_code,
            ],
            type: 'ENROLEMENT_RETURNED_TO_AGENT',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }

    /**
     * @param  array<int|string, mixed>|null  $ids
     * @return list<string>
     */
    private function motifTitles(?array $ids): array
    {
        if ($ids === null || $ids === []) {
            return [];
        }

        $normalized = [];
        foreach ($ids as $id) {
            $normalized[] = (string) $id;
        }

        $motifs = EnrollmentRejectMotif::query()->whereIn('id', $normalized)->get()->keyBy('id');

        $titles = [];
        foreach ($normalized as $id) {
            $motif = $motifs->get($id);
            $titles[] = $motif !== null ? $motif->title : $id;
        }

        return $titles;
    }

    private function finalisationInviteLink(string $token): string
    {
        return config('app.frontend_url').'/etranger/finalisation?'.http_build_query([
            'token' => $token,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
