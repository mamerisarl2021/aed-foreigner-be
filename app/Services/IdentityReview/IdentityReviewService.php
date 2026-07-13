<?php

namespace App\Services\IdentityReview;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\WelcomeUserJob;
use App\Models\Identity;
use App\Models\PasswordResetToken;
use App\Models\Structure;
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
        $allowedTypes = ['IN_PERSON', 'ONLINE'];
        $allowedLevels = ['SIMPLE', 'ADVANCED'];

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

        $query = Identity::with(['user:id,name,email,phonenumber,npi'])
            ->whereIn('status', $statuses);

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

        if ($request->filled('level')) {
            $level = strtoupper($request->input('level'));
            if (in_array($level, $allowedLevels, true)) {
                $query->where('level', $level);
            }
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->whereHas('user', function ($uq) use ($q) {
                $uq->where('email', 'like', "%$q%")
                    ->orWhere('name', 'like', "%$q%")
                    ->orWhere('phonenumber', 'like', "%$q%");
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
        $items = $query->paginate($perPage);

        $items->getCollection()->transform(function ($identity) {
            $structure = Structure::with(['attachments.documents'])
                ->where('manager_id', $identity->user_id)
                ->latest('id')
                ->first();
            $identity->setAttribute('structure', $structure);

            return $identity;
        });

        return $items;
    }

    public function show(int $id): ServiceResult
    {
        $identity = Identity::with(['user:id,name,email,phonenumber,npi'])->findOrFail($id);
        $structure = Structure::with(['attachments.documents'])
            ->where('manager_id', $identity->user_id)
            ->latest('id')
            ->first();
        $identity->setAttribute('structure', $structure);

        return ServiceResult::ok('Détail de la demande.', $identity);
    }

    public function claim(int $id, int $agentId): ServiceResult
    {
        $identity = Identity::findOrFail($id);

        if ($identity->status !== 'PENDING') {
            return ServiceResult::fail('Impossible de réserver cette demande (statut non PENDING).', null, 422);
        }

        if ($identity->assigned_agent_id && $identity->assigned_agent_id !== $agentId) {
            return ServiceResult::fail('Demande déjà assignée à un autre agent.', null, 409);
        }

        $identity->assigned_agent_id = $agentId;
        $identity->save();

        return ServiceResult::ok('Demande assignée.', $identity);
    }

    public function approve(int $id, int $agentId): ServiceResult
    {
        $identity = Identity::with('user')->findOrFail($id);

        if ($identity->status !== 'PENDING') {
            return ServiceResult::fail('Statut non PENDING.', null, 422);
        }

        DB::beginTransaction();
        try {
            $identity->assigned_agent_id = $agentId;

            $user = $identity->user;
            if (! $user->npi) {
                $user->npi = 'F-'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT);
                $user->save();
            }

            $recipientName = $user->name ?? $user->email;

            SendEmailNotificationJob::dispatch(new EmailNotificationData(
                subject: "Votre demande a passé l'étape agent",
                template: NotificationTemplate::IdentityStepApproved,
                recipients: [
                    NotificationRecipient::email($user->email, [
                        'name' => $recipientName,
                    ]),
                ],
                variables: [
                    'name' => $recipientName,
                ],
                type: 'IDENTITY_STEP_APPROVED',
                platform: NotificationPlatform::from(config('notifications.platform')),
            ));

            $identity->status = 'APPROVED_BY_AGENT';
            $identity->save();
            DB::commit();

            return ServiceResult::ok('Demande validée par l’agent et transmise au superviseur.', [
                'identity_id' => $identity->id,
                'user_id' => $user->id,
                'npi' => $user->npi,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Approve identity failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            if (app()->environment('testing')) {
                return ServiceResult::fail("Erreur lors de l'approbation agent.", [
                    'exception' => $e->getMessage(),
                ], 500);
            }

            return ServiceResult::fail("Erreur lors de l'approbation agent.", null, 500);
        }
    }

    public function supervisorApprove(int $id, int $supervisorId): ServiceResult
    {
        $identity = Identity::with('user')->findOrFail($id);

        if ($identity->status !== 'APPROVED_BY_AGENT') {
            return ServiceResult::fail('Statut non APPROVED_BY_AGENT.', null, 422);
        }

        DB::beginTransaction();
        try {
            $user = $identity->user;
            if (! $user->npi) {
                $user->npi = 'F-'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT);
                $user->save();
            }

            $payload = ['data' => ['npi' => $user->npi]];
            $output = $this->trustedXClient->register($payload);

            $email = $user->email;
            $npi = $user->npi;

            if (isset($output['has_user']) && $output['has_user'] === true) {
                $allToken = Str::random(60);
                PasswordResetToken::updateOrCreate(
                    ['npi' => $npi, 'type' => 'all'],
                    ['token' => $allToken, 'created_at' => Carbon::now(), 'type' => 'all']
                );
                $link = config('app.frontend_url')."/init-account/all/$allToken/$npi";
                WelcomeUserJob::dispatch($email, $user, $link, true);
            } else {
                $allToken = Str::random(60);
                $pinToken = Str::random(60);
                $passwordToken = Str::random(60);

                PasswordResetToken::updateOrCreate(
                    ['npi' => $npi, 'type' => 'pin'],
                    ['token' => $pinToken, 'created_at' => Carbon::now(), 'type' => 'pin']
                );
                PasswordResetToken::updateOrCreate(
                    ['npi' => $npi, 'type' => 'password'],
                    ['token' => $passwordToken, 'created_at' => Carbon::now(), 'type' => 'password']
                );
                PasswordResetToken::updateOrCreate(
                    ['npi' => $npi, 'type' => 'all'],
                    ['token' => $allToken, 'created_at' => Carbon::now(), 'type' => 'all']
                );

                $link = config('app.frontend_url')."/init-account/none/$pinToken/$passwordToken/$allToken/$npi";
                WelcomeUserJob::dispatch($email, $user, $link, true);
            }

            $identity->status = 'APPROVED';
            $identity->save();
            DB::commit();

            return ServiceResult::ok('Demande approuvée par le superviseur et compte initialisé.', [
                'identity_id' => $identity->id,
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
        $identity = Identity::with('user')->findOrFail($id);

        if ($identity->status !== $requiredStatus) {
            $message = $requiredStatus === 'PENDING'
                ? 'Statut non PENDING.'
                : 'Statut non APPROVED_BY_AGENT.';

            return ServiceResult::fail($message, null, 422);
        }

        DB::beginTransaction();
        try {
            $this->applyRejection($identity, $stage, $reasons, $comments);

            $successMessage = $requiredStatus === 'PENDING'
                ? 'Demande rejetée et notifiée.'
                : 'Demande rejetée par le superviseur et notifiée.';

            return ServiceResult::ok($successMessage, ['identity_id' => $identity->id]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Reject identity failed: '.$e->getMessage());

            $errorMessage = $requiredStatus === 'PENDING'
                ? 'Erreur lors du rejet.'
                : 'Erreur lors du rejet superviseur.';

            return ServiceResult::fail($errorMessage, null, 500);
        }
    }

    private function applyRejection(Identity $identity, string $stage, array $reasons, ?string $comments): void
    {
        $identity->reject_stage = $stage;
        $identity->reject_reasons = json_encode($reasons);
        $identity->review_comments = $comments;
        $identity->status = 'REJECTED';
        $identity->save();

        $recipientName = $identity->user->name ?? $identity->user->email;

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Votre demande d\'identité a été rejetée',
            template: NotificationTemplate::IdentityRejected,
            recipients: [
                NotificationRecipient::email($identity->user->email, [
                    'name' => $recipientName,
                    'stage' => $identity->reject_stage,
                    'reasons' => $reasons,
                    'comments' => $identity->review_comments,
                ]),
            ],
            variables: [
                'name' => $recipientName,
                'stage' => $identity->reject_stage,
                'reasons' => $reasons,
                'comments' => $identity->review_comments,
            ],
            type: 'IDENTITY_REJECTED',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));

        DB::commit();
    }
}
