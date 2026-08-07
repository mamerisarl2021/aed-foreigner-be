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
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\PKI\TrustedXClientService;
use App\Services\ServiceResult;
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
                'proof' => json_encode([
                    'selfiePath' => $enrollment->documents['selfie'] ?? '',
                    'rectoPath' => $enrollment->documents['recto'] ?? '',
                    'versoPath' => $enrollment->documents['verso'] ?? '',
                    'liveness' => $enrollment->liveness,
                    'similarity' => $enrollment->similarity,
                    'document_type' => $enrollment->kyc_data['document_type'] ?? '',
                    'document_number' => $enrollment->kyc_data['document_number'] ?? '',
                    'nationality' => $enrollment->kyc_data['nationality'] ?? '',
                ]),
                'status' => 'APPROVED',
                'risk_score' => $enrollment->risk_score,
                'analysis_details' => is_array($enrollment->analysis_details)
                    ? json_encode($enrollment->analysis_details)
                    : $enrollment->analysis_details,
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

            $link = config('app.frontend_url').'/etranger/finalisation?token='.$finalisationToken;
            WelcomeUserJob::dispatch($user->email, $user, $link, true);

            $enrollment->status = EnrollmentStatus::Approuvee;
            $enrollment->save();

            $this->activityLog->record(
                ActivityLogAction::ValidationResponsable,
                sprintf('La demande n°%d a été approuvée par le responsable.', $enrollment->id),
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
            $enrollment->status = EnrollmentStatus::Rejetee;
            $enrollment->save();

            $this->activityLog->record(
                ActivityLogAction::RejetConfirme,
                sprintf('Le rejet de la demande n°%d a été confirmé par le responsable.', $enrollment->id),
                $supervisorId,
                $enrollment->id,
            );

            $this->events->publish('rejected', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status->value,
            ]);

            $recipientName = $enrollment->applicantDisplayName();
            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: 'Votre demande d\'enrôlement a été rejetée',
                template: NotificationTemplate::IdentityRejected,
                recipients: [
                    NotificationRecipient::email($enrollment->email, [
                        'name' => $recipientName,
                        'reasons' => $enrollment->reject_reasons ?? [],
                    ]),
                ],
                variables: [
                    'name' => $recipientName,
                    'reasons' => $enrollment->reject_reasons ?? [],
                    'comments' => $enrollment->review_comments,
                ],
                type: 'ENROLEMENT_REJECTED',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));

            DB::commit();

            return ServiceResult::ok('Rejet confirmé et notifié au demandeur.', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status->value,
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
            sprintf('La demande n°%d a été renvoyée à l\'agent par le responsable.', $enrollment->id),
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
            Identity::create([
                'user_id' => $representative->id,
                'type' => 'PERSONNE_MORALE',
                'level' => 'ADVANCED',
                'proof' => json_encode([
                    'company' => $enrollment->kyc_data,
                    'documents' => $enrollment->documents,
                    'company_email' => $enrollment->email,
                    'company_phone' => $enrollment->phonenumber,
                    'enrollment_request_id' => $enrollment->id,
                    'company_manager' => [
                        'user_id' => $representative->id,
                        'nom' => $representative->name,
                        'prenom' => $representative->first_name,
                        'email' => $representative->email,
                    ],
                ]),
                'status' => 'APPROVED',
                'assigned_agent_id' => $supervisorId,
            ]);

            $legalName = (string) ($enrollment->kyc_data['legal_name'] ?? $enrollment->email);

            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: 'Votre demande personne morale a été approuvée',
                template: NotificationTemplate::IdentityStepApproved,
                recipients: [NotificationRecipient::email($enrollment->email, ['name' => $legalName])],
                variables: ['name' => $legalName],
                type: 'MORALE_APPROVED',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));

            $enrollment->status = EnrollmentStatus::Approuvee;
            $enrollment->save();

            $this->activityLog->record(
                ActivityLogAction::ValidationResponsable,
                sprintf('La demande morale n°%d a été approuvée par le responsable.', $enrollment->id),
                $supervisorId,
                $enrollment->id,
            );

            $this->events->publish('approved', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status->value,
            ]);

            DB::commit();

            return ServiceResult::ok('Demande morale approuvée.', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status->value,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Supervisor approve morale failed: '.$e->getMessage());

            return ServiceResult::fail("Erreur lors de l'approbation morale.", null, 500);
        }
    }
}
