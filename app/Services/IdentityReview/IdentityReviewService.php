<?php

declare(strict_types=1);

namespace App\Services\IdentityReview;

use App\Contracts\EnrollmentEventPublisherInterface;
use App\DataTransferObjects\EmailNotificationData;
use App\Enums\EnrollmentStatus;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\WelcomeUserJob;
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSimilarityService;
use App\Services\PKI\TrustedXClientService;
use App\Services\ServiceResult;
use App\Support\NotificationRecipient;
use App\Support\NpiAllocator;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IdentityReviewService
{
    public function __construct(
        private readonly EnrollmentSimilarityService $similarityService,
        private readonly TrustedXClientService $trustedXClient,
        private readonly EnrollmentEventPublisherInterface $events,
    ) {}

    public function list(Request $request): LengthAwarePaginator
    {
        $allowedStatuses = EnrollmentStatus::reviewable();
        $allowedTypes = ['PERSONNE_PHYSIQUE', 'PERSONNE_MORALE'];

        $statutParam = $request->input('statut', $request->input('status'));
        if (is_null($statutParam)) {
            $user = $request->user();
            if ($user && $user->hasRole(config('roles.responsable_de_validation'))) {
                $statuses = [
                    EnrollmentStatus::ValidationAgent->value,
                    EnrollmentStatus::RejetAgent->value,
                ];
            } else {
                $statuses = [EnrollmentStatus::EnAttente->value];
            }
        } else {
            $statuses = is_array($statutParam)
                ? $statutParam
                : array_map('trim', explode('|', (string) $statutParam));
            $statuses = array_values(array_intersect($allowedStatuses, $statuses));
            if ($statuses === []) {
                $statuses = [EnrollmentStatus::EnAttente->value];
            }
        }

        $query = EnrollmentRequest::whereIn('status', $statuses);

        if ($request->filled('type')) {
            $type = strtoupper($request->input('type'));
            if (in_array($type, $allowedTypes, true)) {
                $query->where('type', $type);
            }
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($uq) use ($q) {
                $uq->where('email', 'like', "%$q%")
                    ->orWhere('phonenumber', 'like', "%$q%")
                    ->orWhereJsonContains('kyc_data->name', $q)
                    ->orWhereJsonContains('kyc_data->first_name', $q)
                    ->orWhereJsonContains('kyc_data->legal_name', $q)
                    ->orWhereJsonContains('kyc_data->registration_number', $q)
                    ->orWhereHas('submittedBy', function ($sub) use ($q) {
                        $sub->where('name', 'like', "%$q%")
                            ->orWhere('first_name', 'like', "%$q%");
                    });
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->input('from'))->toDateString());
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->input('to'))->toDateString());
        }

        $orderBy = $request->input('order_by', 'id');
        if (! in_array($orderBy, ['id', 'created_at'], true)) {
            $orderBy = 'id';
        }
        $orderDir = strtolower($request->input('order_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($orderBy, $orderDir);

        return $query->with(['assignedAgent', 'assignedResponsable', 'submittedBy'])->paginate((int) $request->input('per_page', 15));
    }

    public function show(int $id): ServiceResult
    {
        $enrollment = EnrollmentRequest::with(['assignedAgent', 'assignedResponsable', 'submittedBy'])->findOrFail($id);
        $enrollment->setAttribute('similar_enrollments', $this->similarityService->findSimilar($enrollment));

        return ServiceResult::ok('Détail de la demande.', $enrollment);
    }

    public function claim(int $id, string $agentId): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->status !== EnrollmentStatus::EnAttente->value) {
            return ServiceResult::fail('Seules les demandes EN_ATTENTE peuvent être prises en charge.', null, 422);
        }

        if ($enrollment->assigned_agent_id !== null) {
            return ServiceResult::fail('Cette demande est déjà assignée à un agent.', null, 422);
        }

        $enrollment->assigned_agent_id = $agentId;
        $enrollment->save();
        $enrollment->load('assignedAgent', 'submittedBy');

        $this->events->publish('agent_assigned', [
            'demande_id' => $enrollment->id,
            'assigned_agent_id' => $agentId,
            'statut' => $enrollment->status,
        ]);

        return ServiceResult::ok('Demande prise en charge.', $enrollment);
    }

    public function claimValidation(int $id, string $responsableId): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if (! in_array($enrollment->status, [
            EnrollmentStatus::ValidationAgent->value,
            EnrollmentStatus::RejetAgent->value,
        ], true)) {
            return ServiceResult::fail('Seules les demandes en attente de validation responsable peuvent être prises en charge.', null, 422);
        }

        if ($enrollment->assigned_responsable_id !== null) {
            return ServiceResult::fail('Cette décision est déjà assignée à un responsable.', null, 422);
        }

        $enrollment->assigned_responsable_id = $responsableId;
        $enrollment->save();
        $enrollment->load(['assignedAgent', 'assignedResponsable', 'submittedBy']);

        $this->events->publish('responsable_assigned', [
            'demande_id' => $enrollment->id,
            'assigned_responsable_id' => $responsableId,
            'statut' => $enrollment->status,
        ]);

        return ServiceResult::ok('Décision prise en charge.', $enrollment);
    }

    public function instruction(int $id, string $statut, ?array $motif = null, ?string $comments = null): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->status !== EnrollmentStatus::EnAttente->value) {
            return ServiceResult::fail('Statut non éligible à l\'instruction agent (EN_ATTENTE requis).', null, 422);
        }

        if ($statut === EnrollmentStatus::ValidationAgent->value) {
            return $this->agentValidate($enrollment);
        }

        if ($statut === EnrollmentStatus::RejetAgent->value) {
            return $this->agentReject($enrollment, $motif ?? [], $comments);
        }

        return ServiceResult::fail('Statut d\'instruction invalide.', null, 422);
    }

    public function validation(int $id, string $decision, ?string $commentaire = null, ?string $supervisorId = null, ?array $motif = null): ServiceResult
    {
        return match ($decision) {
            'APPROUVEE' => $this->supervisorApprove($id, (string) $supervisorId),
            'REJET_CONFIRME' => $this->supervisorConfirmReject($id),
            'RETOUR_AGENT' => $this->supervisorReturnToAgent($id, $commentaire, $motif),
            default => ServiceResult::fail('Décision de validation invalide.', null, 422),
        };
    }

    private function agentValidate(EnrollmentRequest $enrollment): ServiceResult
    {
        DB::beginTransaction();
        try {
            $enrollment->status = EnrollmentStatus::ValidationAgent->value;
            $enrollment->agent_decided_at = now();
            $enrollment->save();

            $this->events->publish('status_changed', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status,
            ]);

            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: 'Dossier transmis au responsable',
                template: NotificationTemplate::IdentityStepApproved,
                recipients: [
                    NotificationRecipient::email($enrollment->email, [
                        'name' => $this->applicantDisplayName($enrollment),
                    ]),
                ],
                variables: ['name' => $this->applicantDisplayName($enrollment)],
                type: 'ENROLEMENT_STATUS_CHANGED',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));

            DB::commit();

            return ServiceResult::ok('Dossier validé et transmis au responsable.', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status,
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
        $enrollment->status = EnrollmentStatus::RejetAgent->value;
        $enrollment->agent_decided_at = now();
        $enrollment->save();

        $this->events->publish('status_changed', [
            'demande_id' => $enrollment->id,
            'statut' => $enrollment->status,
            'motif' => $motif,
        ]);

        return ServiceResult::ok('Rejet transmis au responsable.', [
            'demande_id' => $enrollment->id,
            'statut' => $enrollment->status,
        ]);
    }

    public function supervisorApprove(int $id, string $supervisorId): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->status !== EnrollmentStatus::ValidationAgent->value) {
            return ServiceResult::fail('Statut non VALIDATION_AGENT.', null, 422);
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
                ['token' => $finalisationToken, 'created_at' => Carbon::now()]
            );

            $link = config('app.frontend_url').'/enrolements/finalisation?token='.$finalisationToken;
            WelcomeUserJob::dispatch($user->email, $user, $link, true);

            $enrollment->status = EnrollmentStatus::Approuvee->value;
            $enrollment->save();

            $this->events->publish('approved', [
                'demande_id' => $enrollment->id,
                'npi' => $user->npi,
                'statut' => $enrollment->status,
            ]);

            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: 'Invitation à finaliser votre enrôlement',
                template: NotificationTemplate::SendInitLink,
                recipients: [
                    NotificationRecipient::email($enrollment->email, [
                        'name' => $this->applicantDisplayName($enrollment),
                        'link' => $link,
                        'npi' => $user->npi,
                    ]),
                ],
                variables: [
                    'name' => $this->applicantDisplayName($enrollment),
                    'link' => $link,
                    'npi' => $user->npi,
                ],
                type: 'ENROLEMENT_APPROVED',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));

            DB::commit();

            return ServiceResult::ok('Demande approuvée. Invitation de finalisation envoyée.', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status,
                'npi' => $user->npi,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Supervisor approve failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ServiceResult::fail("Erreur lors de l'approbation responsable.", null, 500);
        }
    }

    public function supervisorConfirmReject(int $id): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->status !== EnrollmentStatus::RejetAgent->value) {
            return ServiceResult::fail('Statut non REJET_AGENT.', null, 422);
        }

        DB::beginTransaction();
        try {
            $enrollment->status = EnrollmentStatus::Rejetee->value;
            $enrollment->save();

            $this->events->publish('rejected', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status,
            ]);

            $recipientName = $this->applicantDisplayName($enrollment);
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
                'statut' => $enrollment->status,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Supervisor confirm reject failed: '.$e->getMessage());

            return ServiceResult::fail('Erreur lors de la confirmation du rejet.', null, 500);
        }
    }

    public function supervisorReturnToAgent(int $id, ?string $commentaire, ?array $motif = null): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if (! in_array($enrollment->status, [
            EnrollmentStatus::ValidationAgent->value,
            EnrollmentStatus::RejetAgent->value,
        ], true)) {
            return ServiceResult::fail('Statut non éligible au retour agent.', null, 422);
        }

        $enrollment->status = EnrollmentStatus::EnAttente->value;
        $enrollment->assigned_agent_id = null;
        $enrollment->assigned_responsable_id = null;
        $enrollment->review_comments = $commentaire;
        $enrollment->returned_at = now();

        if ($motif !== null) {
            $enrollment->return_reasons = $motif;
            $enrollment->reject_stage = 'RESPONSABLE';
        }

        $enrollment->save();

        $this->events->publish('status_changed', [
            'demande_id' => $enrollment->id,
            'statut' => $enrollment->status,
            'commentaire' => $commentaire,
        ]);

        return ServiceResult::ok('Demande renvoyée à l\'agent.', [
            'demande_id' => $enrollment->id,
            'statut' => $enrollment->status,
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

            $enrollment->status = EnrollmentStatus::Approuvee->value;
            $enrollment->save();

            $this->events->publish('approved', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status,
            ]);

            DB::commit();

            return ServiceResult::ok('Demande morale approuvée.', [
                'demande_id' => $enrollment->id,
                'statut' => $enrollment->status,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Supervisor approve morale failed: '.$e->getMessage());

            return ServiceResult::fail("Erreur lors de l'approbation morale.", null, 500);
        }
    }

    private function applicantDisplayName(EnrollmentRequest $enrollment): string
    {
        if ($enrollment->isPersonneMorale()) {
            return (string) ($enrollment->kyc_data['legal_name'] ?? $enrollment->email);
        }

        return (string) ($enrollment->kyc_data['name'] ?? $enrollment->email);
    }
}
