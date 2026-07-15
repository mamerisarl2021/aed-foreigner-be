<?php

namespace App\Services\IdentityReview;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\WelcomeUserJob;
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSimilarityService;
use App\Services\ServiceResult;
use App\Support\NotificationRecipient;
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
    ) {}

    public function list(Request $request): LengthAwarePaginator
    {
        $allowedStatuses = [
            'PENDING',
            'VISIO_REQUESTED',
            'APPROVED_BY_AGENT',
            'REJECTED_BY_AGENT',
            'RETURNED_TO_AGENT',
            'REJECTED',
            'APPROVED',
            'FINALIZED',
        ];
        $allowedTypes = ['PERSONNE_PHYSIQUE', 'PERSONNE_MORALE'];

        $statusesParam = $request->input('status');
        if (is_null($statusesParam)) {
            $statuses = ['PENDING'];
        } else {
            $statuses = is_array($statusesParam)
                ? $statusesParam
                : array_map('trim', explode(',', (string) $statusesParam));
            $statuses = array_values(array_intersect($allowedStatuses, $statuses));
            if (empty($statuses)) {
                $statuses = ['PENDING'];
            }
        }

        $query = EnrollmentRequest::whereIn('status', $statuses);

        if ($request->filled('assigned')) {
            $assigned = filter_var($request->input('assigned'), FILTER_VALIDATE_BOOLEAN);
            $query->when($assigned, fn ($q) => $q->whereNotNull('assigned_agent_id'))
                ->when(! $assigned, fn ($q) => $q->whereNull('assigned_agent_id'));
        }

        if ($request->filled('agent_id')) {
            $query->where('assigned_agent_id', (int) $request->input('agent_id'));
        }

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
                    ->orWhereJsonContains('kyc_data->first_name', $q);
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->input('from'))->toDateString());
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->input('to'))->toDateString());
        }

        $orderBy = $request->input('order_by', 'id');
        $orderDir = strtolower($request->input('order_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedOrderBy = ['id', 'created_at'];
        if (! in_array($orderBy, $allowedOrderBy, true)) {
            $orderBy = 'id';
        }
        $query->orderBy($orderBy, $orderDir);

        $perPage = (int) $request->input('per_page', 15);

        return $query->paginate($perPage);
    }

    public function show(int $id): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);
        $enrollment->setAttribute('similar_enrollments', $this->similarityService->findSimilar($enrollment));

        return ServiceResult::ok('Détail de la demande.', $enrollment);
    }

    public function claim(int $id, int $agentId): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->status !== 'PENDING') {
            return ServiceResult::fail('Impossible de réserver cette demande (statut non PENDING).', null, 422);
        }

        if ($enrollment->assigned_agent_id && $enrollment->assigned_agent_id !== $agentId) {
            return ServiceResult::fail('Demande déjà assignée à un autre agent.', null, 409);
        }

        $enrollment->assigned_agent_id = $agentId;
        $enrollment->save();

        return ServiceResult::ok('Demande assignée.', $enrollment);
    }

    public function approve(int $id, int $agentId): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if (! in_array($enrollment->status, ['PENDING', 'RETURNED_TO_AGENT'], true)) {
            return ServiceResult::fail('Statut non éligible à l\'approbation agent (PENDING ou RETURNED_TO_AGENT).', null, 422);
        }

        DB::beginTransaction();
        try {
            $enrollment->assigned_agent_id = $agentId;
            $recipientName = $enrollment->kyc_data['name'] ?? $enrollment->email;

            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: "Votre demande a passé l'étape agent",
                template: NotificationTemplate::IdentityStepApproved,
                recipients: [
                    NotificationRecipient::email($enrollment->email, [
                        'name' => $recipientName,
                    ]),
                ],
                variables: [
                    'name' => $recipientName,
                ],
                type: 'IDENTITY_STEP_APPROVED',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));

            $enrollment->status = 'APPROVED_BY_AGENT';
            $enrollment->save();
            DB::commit();

            return ServiceResult::ok('Demande validée par l’agent et transmise au responsable.', [
                'enrollment_id' => $enrollment->id,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Approve enrollment failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ServiceResult::fail("Erreur lors de l'approbation agent.", null, 500);
        }
    }

    public function requestVisio(int $id, ?string $notes): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->status !== 'PENDING') {
            return ServiceResult::fail('Statut non PENDING.', null, 422);
        }

        $enrollment->status = 'VISIO_REQUESTED';
        $enrollment->visio_notes = $notes;
        $enrollment->visio_requested_at = now();
        $enrollment->save();

        $recipientName = $enrollment->kyc_data['name'] ?? $enrollment->email;

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Demande de visioconférence — enrôlement AED',
            template: NotificationTemplate::EnrollmentVisioRequested,
            recipients: [
                NotificationRecipient::email($enrollment->email, [
                    'name' => $recipientName,
                    'notes' => $notes,
                ]),
            ],
            variables: [
                'name' => $recipientName,
                'notes' => $notes,
            ],
            type: 'ENROLLMENT_VISIO_REQUESTED',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));

        return ServiceResult::ok('Visioconférence demandée.', $enrollment);
    }

    public function completeVisio(int $id): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->status !== 'VISIO_REQUESTED') {
            return ServiceResult::fail('Statut non VISIO_REQUESTED.', null, 422);
        }

        $enrollment->status = 'PENDING';
        $enrollment->visio_completed_at = now();
        $enrollment->save();

        return ServiceResult::ok('Visioconférence clôturée, demande remise en PENDING.', $enrollment);
    }

    public function supervisorReturn(int $id, array $reasons, ?string $comments): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if (! in_array($enrollment->status, ['APPROVED_BY_AGENT', 'REJECTED_BY_AGENT'], true)) {
            return ServiceResult::fail('Statut non éligible au renvoi (APPROVED_BY_AGENT ou REJECTED_BY_AGENT).', null, 422);
        }

        $enrollment->status = 'RETURNED_TO_AGENT';
        $enrollment->return_reasons = $reasons;
        $enrollment->review_comments = $comments;
        $enrollment->returned_at = now();
        $enrollment->save();

        $agent = $enrollment->assignedAgent;
        if ($agent?->email) {
            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: 'Demande renvoyée à l\'agent — enrôlement AED',
                template: NotificationTemplate::EnrollmentReturnedToAgent,
                recipients: [
                    NotificationRecipient::email($agent->email, [
                        'enrollment_id' => $enrollment->id,
                        'applicant_email' => $enrollment->email,
                        'reasons' => $reasons,
                        'comments' => $comments,
                    ]),
                ],
                variables: [
                    'enrollment_id' => $enrollment->id,
                    'applicant_email' => $enrollment->email,
                    'reasons' => $reasons,
                    'comments' => $comments,
                ],
                type: 'ENROLLMENT_RETURNED_TO_AGENT',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));
        }

        return ServiceResult::ok('Demande renvoyée à l\'agent.', $enrollment);
    }

    public function supervisorApprove(int $id, int $supervisorId): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->status !== 'APPROVED_BY_AGENT') {
            return ServiceResult::fail('Statut non APPROVED_BY_AGENT.', null, 422);
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
                $user->npi = 'F-'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT);
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

            $allToken = Str::random(60);
            $pinToken = Str::random(60);
            $passwordToken = Str::random(60);

            PasswordResetToken::updateOrCreate(
                ['npi' => $user->npi, 'type' => 'pin'],
                ['token' => $pinToken, 'created_at' => Carbon::now()]
            );
            PasswordResetToken::updateOrCreate(
                ['npi' => $user->npi, 'type' => 'password'],
                ['token' => $passwordToken, 'created_at' => Carbon::now()]
            );
            PasswordResetToken::updateOrCreate(
                ['npi' => $user->npi, 'type' => 'all'],
                ['token' => $allToken, 'created_at' => Carbon::now()]
            );

            $link = config('app.frontend_url')."/init-account/none/$pinToken/$passwordToken/$allToken/{$user->npi}";
            WelcomeUserJob::dispatch($user->email, $user, $link, true);

            $enrollment->status = 'APPROVED';
            $enrollment->save();
            DB::commit();

            return ServiceResult::ok('Demande approuvée par le responsable. Invitation de finalisation envoyée.', [
                'enrollment_id' => $enrollment->id,
                'user_id' => $user->id,
                'npi' => $user->npi,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Supervisor approve failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ServiceResult::fail("Erreur lors de l'approbation responsable.", null, 500);
        }
    }

    /**
     * Agent proposes a rejection — waits for responsable confirmation (PDF §3.1 / §3.2).
     */
    public function proposeReject(int $id, string $stage, array $reasons, ?string $comments): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if (! in_array($enrollment->status, ['PENDING', 'RETURNED_TO_AGENT'], true)) {
            return ServiceResult::fail(
                'Statut non éligible au rejet agent (PENDING ou RETURNED_TO_AGENT).',
                null,
                422
            );
        }

        $enrollment->reject_stage = $stage;
        $enrollment->reject_reasons = $reasons;
        $enrollment->review_comments = $comments;
        $enrollment->status = 'REJECTED_BY_AGENT';
        $enrollment->save();

        return ServiceResult::ok('Rejet proposé et transmis au responsable.', [
            'enrollment_id' => $enrollment->id,
        ]);
    }

    /**
     * Responsable confirms the agent's rejection proposal (PDF §3.2 — Approbation du rejet).
     */
    public function supervisorApproveReject(int $id, ?string $stage = null, ?array $reasons = null, ?string $comments = null): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->status !== 'REJECTED_BY_AGENT') {
            return ServiceResult::fail('Statut non REJECTED_BY_AGENT.', null, 422);
        }

        DB::beginTransaction();
        try {
            if ($stage !== null) {
                $enrollment->reject_stage = $stage;
            }
            if ($reasons !== null) {
                $enrollment->reject_reasons = $reasons;
            }
            if ($comments !== null) {
                $enrollment->review_comments = $comments;
            }

            $enrollment->status = 'REJECTED';
            $enrollment->save();

            $recipientName = $enrollment->kyc_data['name'] ?? $enrollment->email;
            $rejectReasons = $enrollment->reject_reasons ?? [];

            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: 'Votre demande d\'enrôlement a été rejetée',
                template: NotificationTemplate::IdentityRejected,
                recipients: [
                    NotificationRecipient::email($enrollment->email, [
                        'name' => $recipientName,
                        'stage' => $enrollment->reject_stage,
                        'reasons' => $rejectReasons,
                        'comments' => $enrollment->review_comments,
                    ]),
                ],
                variables: [
                    'name' => $recipientName,
                    'stage' => $enrollment->reject_stage,
                    'reasons' => $rejectReasons,
                    'comments' => $enrollment->review_comments,
                ],
                type: 'IDENTITY_REJECTED',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));

            DB::commit();

            return ServiceResult::ok('Rejet confirmé par le responsable et notifié au demandeur.', [
                'enrollment_id' => $enrollment->id,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Supervisor approve reject failed: '.$e->getMessage());

            return ServiceResult::fail('Erreur lors de la confirmation du rejet.', null, 500);
        }
    }
}
