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
use App\Services\PKI\TrustedXClientService;
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
    public function __construct(private readonly TrustedXClientService $trustedXClient) {}

    public function list(Request $request): LengthAwarePaginator
    {
        $allowedStatuses = ['PENDING', 'REJECTED', 'APPROVED', 'APPROVED_BY_AGENT'];
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

        if ($enrollment->status !== 'PENDING') {
            return ServiceResult::fail('Statut non PENDING.', null, 422);
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

            return ServiceResult::ok('Demande validée par l’agent et transmise au superviseur.', [
                'enrollment_id' => $enrollment->id,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Approve enrollment failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ServiceResult::fail("Erreur lors de l'approbation agent.", null, 500);
        }
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
                    'phonenumber' => $enrollment->phonenumber,
                    'nationality' => $enrollment->kyc_data['nationality'] ?? '',
                    'profile' => $enrollment->documents['profile'] ?? null,
                    'status' => 'ACTIVE',
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
                'assigned_agent_id' => $supervisorId,
            ]);

            $payload = ['data' => ['npi' => $user->npi]];
            $output = $this->trustedXClient->register($payload);

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

            // Since it's AED Etranger, they need to finalize their account via a link (password/pin setup)
            $link = config('app.frontend_url')."/init-account/none/$pinToken/$passwordToken/$allToken/{$user->npi}";
            WelcomeUserJob::dispatch($user->email, $user, $link, true);

            $enrollment->status = 'APPROVED';
            $enrollment->save();
            DB::commit();

            return ServiceResult::ok('Demande approuvée par le superviseur, compte créé et initialisé.', [
                'enrollment_id' => $enrollment->id,
                'user_id' => $user->id,
                'npi' => $user->npi,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Supervisor approve failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ServiceResult::fail("Erreur lors de l'approbation superviseur.", null, 500);
        }
    }

    public function reject(int $id, string $stage, array $reasons, ?string $comments, string $requiredStatus): ServiceResult
    {
        $enrollment = EnrollmentRequest::findOrFail($id);

        if ($enrollment->status !== $requiredStatus) {
            $message = $requiredStatus === 'PENDING'
                ? 'Statut non PENDING.'
                : 'Statut non APPROVED_BY_AGENT.';

            return ServiceResult::fail($message, null, 422);
        }

        DB::beginTransaction();
        try {
            $this->applyRejection($enrollment, $stage, $reasons, $comments);

            $successMessage = $requiredStatus === 'PENDING'
                ? 'Demande rejetée et notifiée.'
                : 'Demande rejetée par le superviseur et notifiée.';

            return ServiceResult::ok($successMessage, ['enrollment_id' => $enrollment->id]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Reject enrollment failed: '.$e->getMessage());

            $errorMessage = $requiredStatus === 'PENDING'
                ? 'Erreur lors du rejet.'
                : 'Erreur lors du rejet superviseur.';

            return ServiceResult::fail($errorMessage, null, 500);
        }
    }

    private function applyRejection(EnrollmentRequest $enrollment, string $stage, array $reasons, ?string $comments): void
    {
        $enrollment->reject_stage = $stage;
        $enrollment->reject_reasons = $reasons;
        $enrollment->review_comments = $comments;
        $enrollment->status = 'REJECTED';
        $enrollment->save();

        $recipientName = $enrollment->kyc_data['name'] ?? $enrollment->email;

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Votre demande d\'enrôlement a été rejetée',
            template: NotificationTemplate::IdentityRejected,
            recipients: [
                NotificationRecipient::email($enrollment->email, [
                    'name' => $recipientName,
                    'stage' => $enrollment->reject_stage,
                    'reasons' => $reasons,
                    'comments' => $enrollment->review_comments,
                ]),
            ],
            variables: [
                'name' => $recipientName,
                'stage' => $enrollment->reject_stage,
                'reasons' => $reasons,
                'comments' => $enrollment->review_comments,
            ],
            type: 'IDENTITY_REJECTED',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));

        DB::commit();
    }
}
