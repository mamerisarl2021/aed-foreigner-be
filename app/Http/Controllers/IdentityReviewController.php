<?php

namespace App\Http\Controllers;

use App\Jobs\WelcomeUserJob;
use App\Jobs\NotifyAdminJob;
use App\Jobs\PlanifiedEmailJob;
use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Support\NotificationRecipient;
use App\Models\Identity;
use App\Models\Structure;
use App\Models\User;
use App\Traits\AuthTrait;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;


class IdentityReviewController extends BaseController
{
    use AuthTrait;

    // Liste paginée avec filtres (status multiples, assignation, type, level, recherche, période)
    public function index(Request $request)
    {
        Log::debug('Filtering identity requests', $request->all());

        // Inclure tous les statuts possibles ici
        $allowedStatuses = [
            'PENDING',
            'REJECTED',
            'APPROVED',
            'APPROVED_BY_AGENT',
        ];
        $allowedTypes = ['IN_PERSON', 'ONLINE'];
        $allowedLevels = ['SIMPLE', 'ADVANCED'];

        // Status: accepte "PENDING" ou liste séparée par virgules, ou tableau status[]=...
        $statusesParam = $request->input('status');

        Log::debug('Filtering identity requests by status', ['statusesParam' => $statusesParam]);
        if (is_null($statusesParam)) {
            $statuses = ['PENDING'];
        } else {
            $statuses = is_array($statusesParam)
                ? $statusesParam
                : array_map('trim', explode(',', (string)$statusesParam));
            // Ne garder que les valeurs autorisées
            $statuses = array_values(array_intersect($allowedStatuses, $statuses));
            if (empty($statuses)) {
                $statuses = ['PENDING'];
            }
        }


        $query = Identity::with(['user:id,name,email,phonenumber,npi'])
            ->whereIn('status', $statuses);

        // Filtre assignation (assigned=true/false)
        if ($request->filled('assigned')) {
            $assigned = filter_var($request->input('assigned'), FILTER_VALIDATE_BOOLEAN);
            $query->when($assigned, fn($q) => $q->whereNotNull('assigned_agent_id'))
                ->when(!$assigned, fn($q) => $q->whereNull('assigned_agent_id'));
        }

        // Filtre par agent
        if ($request->filled('agent_id')) {
            $query->where('assigned_agent_id', (int)$request->input('agent_id'));
        }

        // Filtre type/level
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

        // Recherche texte sur l'utilisateur
        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->whereHas('user', function ($uq) use ($q) {
                $uq->where('email', 'like', "%$q%")
                    ->orWhere('name', 'like', "%$q%")
                    ->orWhere('phonenumber', 'like', "%$q%");
            });
        }

        // Intervalle de dates (création)
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->input('from'))->toDateString());
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->input('to'))->toDateString());
        }

        // Tri
        $orderBy = $request->input('order_by', 'id');
        $orderDir = strtolower($request->input('order_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedOrderBy = ['id', 'created_at'];
        if (!in_array($orderBy, $allowedOrderBy, true)) {
            $orderBy = 'id';
        }
        $query->orderBy($orderBy, $orderDir);

        $perPage = (int)$request->input('per_page', 15);
        $items = $query->paginate($perPage);

        // Enrichir avec la structure récente + docs
        $items->getCollection()->transform(function ($identity) {
            $structure = Structure::with(['attachments.documents'])
                ->where('manager_id', $identity->user_id)
                ->latest('id')
                ->first();
            $identity->setAttribute('structure', $structure);
            return $identity;
        });

        return $this->sendResponse('Liste filtrée des demandes.', $items);
    }

    // Détail complet d'une identité (preuves + structure + documents)
    public function show(int $id)
    {
        $identity = Identity::with(['user:id,name,email,phonenumber,npi'])->findOrFail($id);
        $structure = Structure::with(['attachments.documents'])
            ->where('manager_id', $identity->user_id)
            ->latest('id')
            ->first();

        $proof = json_decode($identity->proof ?? '{}', true);

        return $this->sendResponse('Détail de la demande.', [
            'identity' => $identity,
            'proof' => $proof,
            'structure' => $structure,
        ]);
    }

    // Réserver/Assigner la demande à l’agent courant
    public function claim(int $id)
    {
        $identity = Identity::findOrFail($id);
        if ($identity->status !== 'PENDING') {
            return $this->sendError('Impossible de réserver cette demande (statut non PENDING).', null, 422);
        }
        $agentId = Auth::id();
        if (!$agentId) {
            return $this->sendError('Non authentifié.', null, 401);
        }
        if ($identity->assigned_agent_id && $identity->assigned_agent_id !== $agentId) {
            return $this->sendError('Demande déjà assignée à un autre agent.', null, 409);
        }
        $identity->assigned_agent_id = $agentId;
        $identity->save();
        return $this->sendResponse('Demande assignée.', $identity);
    }

    // Approuver la demande (niveau agent): statut APPROVED_BY_AGENT et mail d'étape
    public function approve(Request $request, int $id)
    {
        $identity = Identity::with('user')->findOrFail($id);
        if ($identity->status !== 'PENDING') {
            return $this->sendError('Statut non PENDING.', null, 422);
        }
        $agentId = Auth::id();
        if (!$agentId) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        DB::beginTransaction();
        try {
            $identity->assigned_agent_id = $agentId;

            // Générer un identifiant technique (NPI) si manquant
            $user = $identity->user;
            if (!$user->npi) {
                $user->npi = 'F-' . str_pad((string)$user->id, 8, '0', STR_PAD_LEFT);
                $user->save();
            }
            // Notification étape agent
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
            return $this->sendResponse('Demande validée par l’agent et transmise au superviseur.', [
                'identity_id' => $identity->id,
                'user_id' => $user->id,
                'npi' => $user->npi,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Approve identity failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            if (app()->environment('testing')) {
                return response()->json([
                    'success' => false,
                    'message' => "Erreur lors de l'approbation agent.",
                    'status' => 500,
                    'exception' => $e->getMessage(),
                ], 500);
            }
            return $this->sendError("Erreur lors de l'approbation agent.", null, 500);
        }
    }

    // Validation finale par superviseur: provision TrustedX + emails d’activation
    public function supervisorApprove(Request $request, int $id)
    {
        $identity = Identity::with('user')->findOrFail($id);
        if ($identity->status !== 'APPROVED_BY_AGENT') {
            return $this->sendError('Statut non APPROVED_BY_AGENT.', null, 422);
        }
        $supervisorId = Auth::id();
        if (!$supervisorId) {
            return $this->sendError('Non authentifié.', null, 401);
        }

        DB::beginTransaction();
        try {
            $user = $identity->user;
            if (!$user->npi) {
                $user->npi = 'F-' . str_pad((string)$user->id, 8, '0', STR_PAD_LEFT);
                $user->save();
            }

            $payload = ['data' => ['npi' => $user->npi]];
            $output = $this->register($payload);

            $email = $user->email;
            $npi = $user->npi;
            if (isset($output['has_user']) && $output['has_user'] === true) {
                $allToken = Str::random(60);
                DB::table('password_resets')->updateOrInsert(
                    ['npi' => $npi, 'type' => 'all'],
                    ['token' => $allToken, 'created_at' => Carbon::now(), 'type' => 'all']
                );
                $link = env('FRONT_URL') . "/init-account/all/$allToken/$npi";
                WelcomeUserJob::dispatch($email, $user, $link, true);
            } else {
                $allToken = Str::random(60);
                $pinToken = Str::random(60);
                $passwordToken = Str::random(60);

                DB::table('password_resets')->updateOrInsert(
                    ['npi' => $npi, 'type' => 'pin'],
                    ['token' => $pinToken, 'created_at' => Carbon::now(), 'type' => 'pin']
                );
                DB::table('password_resets')->updateOrInsert(
                    ['npi' => $npi, 'type' => 'password'],
                    ['token' => $passwordToken, 'created_at' => Carbon::now(), 'type' => 'password']
                );
                DB::table('password_resets')->updateOrInsert(
                    ['npi' => $npi, 'type' => 'all'],
                    ['token' => $allToken, 'created_at' => Carbon::now(), 'type' => 'all']
                );

                $link = env('FRONT_URL') . "/init-account/none/$pinToken/$passwordToken/$allToken/$npi";
                WelcomeUserJob::dispatch($email, $user, $link, true);
            }

            $identity->status = 'APPROVED';
            $identity->save();
            DB::commit();
            return $this->sendResponse('Demande approuvée par le superviseur et compte initialisé.', [
                'identity_id' => $identity->id,
                'user_id' => $user->id,
                'npi' => $user->npi,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Supervisor approve failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $this->sendError("Erreur lors de l'approbation superviseur.", null, 500);
        }
    }

    // Rejet par superviseur (mêmes possibilités qu’agent)
    public function supervisorReject(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'stage' => 'required|in:KYC,STRUCTURE',
            'reasons' => 'required|array|min:1',
            'comments' => 'sometimes|string|nullable',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Données invalides.', $validator->errors(), 422);
        }

        $identity = Identity::with('user')->findOrFail($id);
        if ($identity->status !== 'APPROVED_BY_AGENT') {
            return $this->sendError('Statut non APPROVED_BY_AGENT.', null, 422);
        }

        DB::beginTransaction();
        try {
            $this->extracted($request, $identity);
            return $this->sendResponse('Demande rejetée par le superviseur et notifiée.', ['identity_id' => $identity->id]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Supervisor reject failed: ' . $e->getMessage());
            return $this->sendError('Erreur lors du rejet superviseur.', null, 500);
        }
    }

    // Rejeter la demande avec indication de l’étape et des raisons
    public function reject(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'stage' => 'required|in:KYC,STRUCTURE',
            'reasons' => 'required|array|min:1',
            'comments' => 'sometimes|string|nullable',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Données invalides.', $validator->errors(), 422);
        }

        $identity = Identity::with('user')->findOrFail($id);
        if ($identity->status !== 'PENDING') {
            return $this->sendError('Statut non PENDING.', null, 422);
        }

        DB::beginTransaction();
        try {
            $this->extracted($request, $identity);
            return $this->sendResponse('Demande rejetée et notifiée.', ['identity_id' => $identity->id]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Reject identity failed: ' . $e->getMessage());
            return $this->sendError('Erreur lors du rejet.', null, 500);
        }
    }

    /**
     * @param Request $request
     * @param \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|Identity|null $identity
     * @return void
     */
    private function extracted(Request $request, \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|Identity|null $identity): void
    {
        $identity->reject_stage = $request->input('stage');
        $identity->reject_reasons = json_encode($request->input('reasons'));
        $identity->review_comments = $request->input('comments');
        $identity->status = 'REJECTED';
        $identity->save();

        $recipientName = $identity->user->name ?? $identity->user->email;
        $reasons = $request->input('reasons');

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
