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
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Models\EnrollmentRequest;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use App\Support\NotificationRecipient;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AgentEnrollmentReviewService
{
    public function __construct(
        private readonly EnrollmentEventPublisherInterface $events,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function claim(EnrollmentRequest $enrollment, string $agentId): ServiceResult
    {
        if ($enrollment->status !== EnrollmentStatus::EnAttenteAgent) {
            return ServiceResult::fail('Seules les demandes EN_ATTENTE_AGENT peuvent être prises en charge.', null, 422);
        }

        if ($enrollment->assigned_agent_id !== null) {
            return ServiceResult::fail('Cette demande est déjà assignée à un agent.', null, 422);
        }

        $enrollment->assigned_agent_id = $agentId;
        $enrollment->status = EnrollmentStatus::EnCoursAgent;
        $enrollment->save();
        $enrollment->load('assignedAgent', 'submittedBy');

        $this->activityLog->record(
            ActivityLogAction::PriseEnChargeAgent,
            sprintf(
                '%s a pris en charge la demande %s.',
                ActivityLogService::actorLabel($enrollment->assignedAgent),
                $enrollment->tracking_code ?? $enrollment->id
            ),
            $agentId,
            $enrollment->id,
        );

        $this->events->publish('agent_assigned', [
            'demande_id' => $enrollment->id,
            'assigned_agent_id' => $agentId,
            'statut' => $enrollment->status->value,
        ]);

        return ServiceResult::ok('Demande prise en charge.', $enrollment);
    }

    /**
     * Instruction de l'agent : il rend un **avis**, il ne tranche pas.
     *
     * @param  list<string>|null  $motif
     */
    public function instruction(EnrollmentRequest $enrollment, string $avis, ?array $motif = null, ?string $comments = null): ServiceResult
    {
        if ($enrollment->status !== EnrollmentStatus::EnCoursAgent) {
            return ServiceResult::fail('Statut non éligible à l\'instruction agent (EN_COURS_AGENT requis).', null, 422);
        }

        if ($avis === AgentAvis::Favorable->value) {
            return $this->agentValidate($enrollment, $comments);
        }

        if ($avis === AgentAvis::Defavorable->value) {
            return $this->agentReject($enrollment, $motif ?? [], $comments);
        }

        return ServiceResult::fail('Avis d\'instruction invalide.', null, 422);
    }

    private function agentValidate(EnrollmentRequest $enrollment, ?string $comments = null): ServiceResult
    {
        DB::beginTransaction();
        try {
            $enrollment->status = EnrollmentStatus::EnAttenteResponsable;
            $enrollment->agent_avis = AgentAvis::Favorable;
            $enrollment->review_comments = $comments;
            $enrollment->reject_reasons = null;
            $enrollment->reject_stage = null;
            $enrollment->agent_decided_at = now();
            $enrollment->save();
            $enrollment->load('assignedAgent');

            $this->activityLog->record(
                ActivityLogAction::ValidationAgent,
                sprintf(
                    '%s a validé la demande %s.',
                    ActivityLogService::actorLabel($enrollment->assignedAgent),
                    $enrollment->tracking_code ?? $enrollment->id
                ),
                $enrollment->assigned_agent_id,
                $enrollment->id,
            );

            $this->events->publish('status_changed', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status->value,
            ]);

            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: 'Dossier transmis au responsable',
                template: NotificationTemplate::IdentityStepApproved,
                recipients: [
                    NotificationRecipient::email($enrollment->email, [
                        'name' => $enrollment->applicantDisplayName(),
                    ]),
                ],
                variables: ['name' => $enrollment->applicantDisplayName()],
                type: 'ENROLEMENT_STATUS_CHANGED',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));

            DB::commit();

            return ServiceResult::ok('Avis favorable transmis au responsable.', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status->value,
                'avis_agent' => AgentAvis::Favorable->value,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Agent validate failed: '.$e->getMessage());

            return ServiceResult::fail('Erreur lors de la validation agent.', null, 500);
        }
    }

    /**
     * @param  list<string>  $motif
     */
    private function agentReject(EnrollmentRequest $enrollment, array $motif, ?string $comments): ServiceResult
    {
        $enrollment->reject_reasons = $motif;
        $enrollment->review_comments = $comments;
        $enrollment->reject_stage = 'AGENT';
        $enrollment->status = EnrollmentStatus::EnAttenteResponsable;
        $enrollment->agent_avis = AgentAvis::Defavorable;
        $enrollment->agent_decided_at = now();
        $enrollment->save();
        $enrollment->load('assignedAgent');

        $this->activityLog->record(
            ActivityLogAction::RejetAgent,
            sprintf(
                '%s a rejeté la demande %s.',
                ActivityLogService::actorLabel($enrollment->assignedAgent),
                $enrollment->tracking_code ?? $enrollment->id
            ),
            $enrollment->assigned_agent_id,
            $enrollment->id,
        );

        $this->events->publish('status_changed', [
            'demande_id' => $enrollment->id,
            'statut' => $enrollment->status->value,
            'motif' => $motif,
        ]);

        return ServiceResult::ok('Avis défavorable transmis au responsable.', [
            'demande_id' => $enrollment->id,
            'statut' => $enrollment->status->value,
            'avis_agent' => AgentAvis::Defavorable->value,
        ]);
    }
}
