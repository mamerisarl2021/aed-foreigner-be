<?php

namespace App\Services\Structure;

use App\Jobs\NotifyAdminJob;
use App\Jobs\SendStructureInvitationEmail;
use App\Models\Attachment;
use App\Models\OTP;
use App\Models\Structure;
use App\Models\StructureInvitation;
use App\Models\User;
use App\Services\AttachmentUploadService;
use App\Services\ServiceResult;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class StructureManagementService
{
    public function __construct(
        private readonly AttachmentUploadService $attachmentService,
    ) {}

    public function list(int $perPage): ServiceResult
    {
        try {
            $structures = Structure::with('manager')->paginate($perPage);

            return ServiceResult::ok('Liste des structures.', $this->flattenPagination($structures));
        } catch (Exception $e) {
            Log::error('Impossible de récupérer les structures: '.$e->getMessage());

            return ServiceResult::fail('Impossible de récupérer les structures.', null, 500);
        }
    }

    public function mine(int $managerId): ServiceResult
    {
        try {
            $structures = Structure::where('manager_id', $managerId)->get();

            return ServiceResult::ok('Mes entreprises.', $structures);
        } catch (Exception $e) {
            Log::error('Fetching structures failed: '.$e->getMessage());

            return ServiceResult::fail('Fetching structures failed.', null, 500);
        }
    }

    public function search(string $query): ServiceResult
    {
        try {
            $structures = Structure::where('name', 'LIKE', "%$query%")
                ->orWhere('ifu', 'LIKE', "%$query%")
                ->orWhere('searchbase', 'LIKE', "%$query%")
                ->where('status', '==', 'APPROVED')
                ->take(2)
                ->get();

            Log::debug($structures->toArray());

            return ServiceResult::ok('Résultats de recherche.', $structures);
        } catch (Exception $e) {
            Log::error('Searching structures failed: '.$e);

            return ServiceResult::fail('Searching structures failed.', null, 500);
        }
    }

    public function show(int $id, User $user): ServiceResult
    {
        try {
            $structure = Structure::with(['manager', 'attachments.documents', 'userSubscriptions', 'structureSubscriptions'])
                ->findOrFail($id);

            if ($user->hasRole('client')) {
                if ($structure->manager_id !== $user->id) {
                    return ServiceResult::fail('Vous n\'êtes pas autorisé à accéder à cette entreprise.', null, 403);
                }

                return ServiceResult::ok('Entreprise récupérée avec succès.', $structure);
            }

            if ($user->hasRole(config('roles.agent'))) {
                return ServiceResult::ok('Entreprise récupérée avec succès.', $structure);
            }

            return ServiceResult::fail('Vous n\'êtes pas autorisé à accéder à cette entreprise.', null, 403);
        } catch (Exception $e) {
            Log::error('Impossible de récupérer cette entreprise: '.$e->getMessage());

            return ServiceResult::fail('Impossible de récupérer cette entreprise.', null, 500);
        }
    }

    public function oneShotStore(Request $request, int $managerId): ServiceResult
    {
        DB::beginTransaction();

        try {
            $validatedData = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('structures')->where(function ($query) use ($managerId) {
                        return $query->where('manager_id', $managerId);
                    }),
                ],
                'ifu' => 'required|string|unique:structures,ifu',
                'attachements.*.name' => 'required|string|max:255',
                'attachements.*.status' => 'required|in:SENT,VALIDATED,WAITING_MANAGER,REJECTED',
                'attachements.*.message' => 'required_if:attachments.*.status,REJECTED|string',
                'attachements.*.files.*' => 'required|mimes:pdf,docx,doc,xls,mp4,png,jpeg,jpg|max:10000',
            ]);

            $structure = Structure::create([
                'name' => $validatedData['name'],
                'ifu' => $validatedData['ifu'],
                'manager_id' => $managerId,
                'status' => 'PENDING',
                'searchbase' => "ou=Employees-Virtual ID,ou={$validatedData['name']},o=GOUV,c=BJ",
            ]);

            $finalFiles = [];
            foreach ($request->input('attachements') as $key => $attachmentData) {
                $attachment = Attachment::create(array_merge($attachmentData, ['structure_id' => $structure->id]));
                $files = $request->file("attachements.{$key}.files");

                if ($files) {
                    $response = $this->attachmentService->attachFiles($files, $attachment->id);

                    if (! $response['status']) {
                        DB::rollBack();

                        return ServiceResult::fail(
                            'Les fichiers n\'ont pas pu être attachés à une ou plusieurs pièces jointes. Veuillez réessayer.',
                            null,
                            400
                        );
                    }

                    $finalFiles[] = $response['data'] ?? [];
                }
            }

            DB::commit();

            return ServiceResult::ok(
                'Votre entité a été créée avec succès et les fichiers ont été attachés. Vous recevrez une notification lorsque l\'activation sera complète et lorsque le statut changera.',
                [
                    'structure' => $structure,
                    'files' => $finalFiles,
                ]
            );
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la création de la structure et de l\'attachement des fichiers : '.$e->getMessage());

            return ServiceResult::fail($e->getMessage(), $e, 500);
        }
    }

    public function oneShotAttach(Request $request): ServiceResult
    {
        DB::beginTransaction();

        try {
            $attachment = Attachment::create($request->all());
            $files = $request->file('files');
            $response = $this->attachmentService->attachFiles($files, $attachment->id);

            if (! $response['status']) {
                DB::rollBack();

                return ServiceResult::fail($response['message'], null, 400);
            }

            DB::commit();

            return ServiceResult::ok($response['message'], $response['data']);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la création de la pièce jointe : '.$e->getMessage());

            return ServiceResult::fail(
                'Une erreur est survenue pendant la création de la pièce jointe.',
                null,
                500
            );
        }
    }

    /**
     * @param  array{name: string, ifu: string}  $validatedData
     */
    public function store(array $validatedData, int $managerId): ServiceResult
    {
        try {
            Structure::create([
                'name' => $validatedData['name'],
                'ifu' => $validatedData['ifu'],
                'manager_id' => $managerId,
                'status' => 'PENDING',
                'searchbase' => 'ou=Employees-Virtual ID,ou='.$validatedData['name'].',o=GOUV,c=BJ',
            ]);

            $structures = Structure::where('manager_id', $managerId)->get();

            return ServiceResult::ok(
                'Votre entité à bien été créée. Il vous faudra renseigner les pièces nécessaires à son activation. Elle sera dès lors en attente de validation d\'un agent de la plateforme. Vous serez notifié dès que le statut de votre document changera.',
                $structures
            );
        } catch (Exception $e) {
            Log::error('Creating structure failed: '.$e->getMessage());

            return ServiceResult::fail(
                'Nous sommes dans le regret de vous annoncer que votre structure n\'a pas pu être créée et nous vous demandons de réessayer ultérieurement.',
                null,
                500
            );
        }
    }

    /**
     * @param  array<string, mixed>  $validatedData
     */
    public function update(int $id, array $validatedData): ServiceResult
    {
        try {
            if (($validatedData['status'] ?? null) === 'APPROVED') {
                $validatedData['status'] = 'WAITING_MANAGER';
            }

            $structure = Structure::findOrFail($id);
            $structure->update($validatedData);

            return ServiceResult::ok('Structure updated successfully.', null);
        } catch (Exception $e) {
            Log::error('Updating structure failed: '.$e->getMessage());

            return ServiceResult::fail('Updating structure failed.', null, 500);
        }
    }

    public function destroy(int $id): ServiceResult
    {
        try {
            $structure = Structure::findOrFail($id);
            $structure->delete();

            return ServiceResult::ok('Structure deleted successfully.', null);
        } catch (Exception $e) {
            Log::error('Deleting structure failed: '.$e->getMessage());

            return ServiceResult::fail('Deleting structure failed.', null, 500);
        }
    }

    /**
     * @param  list<array{id: int, status: string}>  $structures
     */
    public function updateStructureStatus(array $structures): ServiceResult
    {
        try {
            foreach ($structures as $structureData) {
                $structure = Structure::findOrFail($structureData['id']);
                $status = $structureData['status'];

                if ($status === 'APPROVED') {
                    $status = 'WAITING_MANAGER';
                    $allAttachmentsValidated = Attachment::where('structure_id', $structure->id)
                        ->where('status', '!=', 'VALIDATED')
                        ->doesntExist();

                    if (! $allAttachmentsValidated) {
                        return ServiceResult::fail(
                            "Impossible de valider la structure n° {$structure->id} car tous ses documents n'ont pas été validés.",
                            null,
                            500
                        );
                    }
                }

                $structure->update(['status' => $status]);
            }

            return ServiceResult::ok('Structures modifiées avec succès.', null);
        } catch (Exception $e) {
            Log::error('Failed to update structures status: '.$e->getMessage());

            return ServiceResult::fail("Nous n'avons pas pu mettre à jour le statut de l'une ou plusieurs des structures.", null, 500);
        }
    }

    public function sendOtp(string $email): ServiceResult
    {
        $otp = implode('', array_map(fn () => (string) mt_rand(0, 9), range(1, 6)));
        $validUntil = Carbon::now()->addMinutes(5);

        $existingOTP = OTP::where('email', $email)->first();
        if ($existingOTP) {
            $existingOTP->update(['otp' => $otp, 'valid_until' => $validUntil]);
        } else {
            OTP::create([
                'email' => $email,
                'otp' => $otp,
                'valid_until' => $validUntil,
            ]);
        }

        Log::info("Dispatching NotifyAdminJob for email: $email with OTP: $otp");
        NotifyAdminJob::dispatch($email, $otp);

        return ServiceResult::ok('OTP envoyé avec succès.', []);
    }

    /**
     * @param  array{email: string, otp: string, entity_id: int}  $validatedData
     */
    public function verifyOtp(array $validatedData): ServiceResult
    {
        $existingOTP = OTP::where('email', $validatedData['email'])
            ->where('otp', $validatedData['otp'])
            ->where('valid_until', '>=', Carbon::now())
            ->first();

        if (! $existingOTP) {
            return ServiceResult::fail('OTP invalide ou expiré.', null, 403);
        }

        $existingOTP->delete();
        $structure = Structure::findOrFail($validatedData['entity_id']);
        $structure->update(['status' => 'APPROVED']);

        $manager = User::find($structure->manager_id);
        if ($manager) {
            $manager->update(['status' => 'ACTIVE']);
        }

        return ServiceResult::ok("Bienvenue sur la plateforme d'enregistrement déléguée! Vous nous avez manqué!", $structure);
    }

    public function listEmployees(int $structureId, User $user): ServiceResult
    {
        try {
            $structure = Structure::findOrFail($structureId);

            if ($user->hasRole('client') && $structure->manager_id !== $user->id) {
                return ServiceResult::fail('Vous n\'êtes pas autorisé à voir les employés de cette structure.', null, 403);
            }

            $employees = $structure->employees()
                ->withPivot('role', 'status', 'joined_at')
                ->get()
                ->map(fn ($employee) => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'npi' => $employee->npi,
                    'role' => $employee->pivot->role,
                    'status' => $employee->pivot->status,
                    'joined_at' => $employee->pivot->joined_at,
                    'invitation_message' => $employee->pivot->invitation_message,
                ]);

            return ServiceResult::ok('Liste des employés récupérée avec succès.', $employees);
        } catch (Exception $e) {
            Log::error('Erreur lors de la récupération des employés : '.$e->getMessage());

            return ServiceResult::fail('Impossible de récupérer la liste des employés.', null, 500);
        }
    }

    /**
     * @param  array<string, mixed>  $validatedData
     */
    public function inviteEmployee(array $validatedData, int $structureId, User $manager): ServiceResult
    {
        DB::beginTransaction();
        try {
            $structure = Structure::findOrFail($structureId);

            if ($structure->manager_id !== $manager->id) {
                return ServiceResult::fail('Vous n\'êtes pas autorisé à inviter des employés dans cette structure.', null, 403);
            }

            if ($structure->status !== 'APPROVED') {
                return ServiceResult::fail('La structure doit être validée avant d\'inviter des employés.', null, 400);
            }

            $user = $this->findUserByIdentifier($validatedData['user_identifier']);
            if (! $user) {
                return ServiceResult::fail('Utilisateur non trouvé.', null, 404);
            }

            if ($structure->employees()->where('user_id', $user->id)->exists()) {
                return ServiceResult::fail('Cet utilisateur est déjà membre de la structure.', null, 400);
            }

            $structure->employees()->attach($user->id, [
                'role' => $validatedData['role'] ?? 'EMPLOYEE',
                'status' => 'ACTIVE',
                'joined_at' => Carbon::now(),
                'invitation_message' => $validatedData['message'] ?? 'Ajouté par le manager',
            ]);

            if ($user->status !== 'ACTIVE') {
                $user->update(['status' => 'ACTIVE']);
            }

            $invitation = StructureInvitation::create([
                'structure_id' => $structure->id,
                'user_id' => $user->id,
                'invited_by' => $manager->id,
                'email' => $user->email,
                'token' => bin2hex(random_bytes(32)),
                'role' => $validatedData['role'] ?? 'EMPLOYEE',
                'status' => 'ACCEPTED',
                'expires_at' => Carbon::now()->addDays(7),
                'accepted_at' => Carbon::now(),
                'message' => $validatedData['message'] ?? null,
            ]);

            DB::commit();

            Log::info("Dispatching SendStructureInvitationEmail job for auto-accepted invitation. {$invitation->user}");
            SendStructureInvitationEmail::dispatch($invitation);

            return ServiceResult::ok('Employé ajouté directement à la structure.', [
                'user' => $user->only(['id', 'email', 'name', 'status']),
                'structure' => $structure->only(['id', 'name']),
                'role' => $validatedData['role'] ?? 'EMPLOYEE',
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de l\'ajout de l\'employé : '.$e->getMessage());

            return ServiceResult::fail('Impossible d\'ajouter l\'employé.', null, 500);
        }
    }

    /**
     * @param  array<string, mixed>  $validatedData
     */
    public function addEmployee(array $validatedData, int $structureId, User $manager): ServiceResult
    {
        DB::beginTransaction();
        try {
            $structure = Structure::findOrFail($structureId);

            if ($structure->manager_id !== $manager->id && ! $manager->hasAnyRole([config('roles.agent'), config('roles.responsable_de_validation')])) {
                return ServiceResult::fail('Vous n\'êtes pas autorisé à ajouter des employés.', null, 403);
            }

            $user = User::findOrFail($validatedData['user_id']);

            if ($structure->employees()->where('user_id', $user->id)->exists()) {
                return ServiceResult::fail('Cet utilisateur est déjà membre de la structure.', null, 400);
            }

            $structure->employees()->attach($user->id, [
                'role' => $validatedData['role'] ?? 'EMPLOYEE',
                'status' => 'ACTIVE',
                'joined_at' => Carbon::now(),
                'invitation_message' => 'Ajouté directement',
            ]);

            if ($user->status !== 'ACTIVE' && $structure->status === 'APPROVED') {
                $user->update(['status' => 'ACTIVE']);
            }

            $invitation = StructureInvitation::create([
                'structure_id' => $structure->id,
                'user_id' => $user->id,
                'invited_by' => $manager->id,
                'email' => $user->email,
                'token' => bin2hex(random_bytes(32)),
                'role' => $validatedData['role'] ?? 'EMPLOYEE',
                'status' => 'ACCEPTED',
                'expires_at' => Carbon::now()->addDays(7),
                'accepted_at' => Carbon::now(),
                'message' => $validatedData['message'] ?? null,
            ]);

            DB::commit();

            SendStructureInvitationEmail::dispatch($invitation);

            return ServiceResult::ok('Employé ajouté avec succès à la structure.', [
                'user' => $user->only(['id', 'email', 'name', 'npi']),
                'structure' => $structure->only(['id', 'name']),
                'role' => $validatedData['role'] ?? 'EMPLOYEE',
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de l\'ajout de l\'employé : '.$e->getMessage());

            return ServiceResult::fail('Impossible d\'ajouter l\'employé.', null, 500);
        }
    }

    /**
     * @param  array{role: string}  $validatedData
     */
    public function updateEmployeeRole(array $validatedData, int $structureId, int $userId, User $manager): ServiceResult
    {
        try {
            $structure = Structure::findOrFail($structureId);

            if ($structure->manager_id !== $manager->id) {
                return ServiceResult::fail('Vous n\'êtes pas autorisé à modifier les rôles.', null, 403);
            }

            $employee = $structure->employees()->where('user_id', $userId)->first();
            if (! $employee) {
                return ServiceResult::fail('Cet utilisateur n\'est pas employé dans cette structure.', null, 404);
            }

            $structure->employees()->updateExistingPivot($userId, [
                'role' => $validatedData['role'],
                'updated_at' => Carbon::now(),
            ]);

            return ServiceResult::ok('Rôle de l\'employé mis à jour avec succès.', [
                'user_id' => $userId,
                'new_role' => $validatedData['role'],
            ]);
        } catch (Exception $e) {
            Log::error('Erreur lors de la mise à jour du rôle : '.$e->getMessage());

            return ServiceResult::fail('Impossible de mettre à jour le rôle.', null, 500);
        }
    }

    public function removeEmployee(int $structureId, int $userId, User $manager): ServiceResult
    {
        DB::beginTransaction();
        try {
            $structure = Structure::findOrFail($structureId);

            if ($structure->manager_id !== $manager->id && ! $manager->hasAnyRole([config('roles.agent'), config('roles.responsable_de_validation')])) {
                return ServiceResult::fail('Vous n\'êtes pas autorisé à retirer des employés.', null, 403);
            }

            if ($structure->manager_id == $userId) {
                return ServiceResult::fail('Vous ne pouvez pas retirer le manager de sa propre structure.', null, 400);
            }

            if (! $structure->employees()->where('user_id', $userId)->exists()) {
                return ServiceResult::fail('Cet utilisateur n\'est pas employé dans cette structure.', null, 404);
            }

            $structure->employees()->detach($userId);
            DB::commit();

            return ServiceResult::ok('Employé retiré de la structure avec succès.', [
                'user_id' => $userId,
                'structure_id' => $structureId,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors du retrait de l\'employé : '.$e->getMessage());

            return ServiceResult::fail('Impossible de retirer l\'employé.', null, 500);
        }
    }

    public function listPendingInvitations(int $structureId): ServiceResult
    {
        try {
            Structure::findOrFail($structureId);

            $invitations = StructureInvitation::with(['user', 'inviter'])
                ->where('structure_id', $structureId)
                ->where('status', 'PENDING')
                ->where('expires_at', '>', Carbon::now())
                ->get();

            return ServiceResult::ok('Invitations en attente récupérées avec succès.', $invitations);
        } catch (Exception $e) {
            Log::error('Erreur lors de la récupération des invitations : '.$e->getMessage());

            return ServiceResult::fail('Impossible de récupérer les invitations.', null, 500);
        }
    }

    private function findUserByIdentifier(string $identifier): ?User
    {
        $user = User::where('email', $identifier)
            ->orWhere('npi', $identifier)
            ->first();

        if (! $user && is_numeric($identifier)) {
            $user = User::find($identifier);
        }

        return $user;
    }

    /**
     * @return array{data: mixed, pagination: array<string, mixed>}
     */
    private function flattenPagination(LengthAwarePaginator $paginator): array
    {
        $flattenedData = $paginator->toArray();
        $data = $flattenedData['data'];
        unset($flattenedData['data']);

        return array_merge(['data' => $data], ['pagination' => $flattenedData]);
    }
}
