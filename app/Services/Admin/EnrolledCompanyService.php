<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\DataTransferObjects\EnrolledCompanyListFilters;
use App\Enums\ActivityLogAction;
use App\Enums\EnrolledCompanyStatus;
use App\Models\EnrolledCompany;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\ServiceResult;
use App\Support\SqlLike;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * Entreprises enrôlées, pendant de {@see EnrolledPersonService}.
 *
 * La lecture est plus directe côté morale : l'enrôlement d'une entreprise crée
 * sa propre ligne dans `enrolled_companies`, là où celui d'une personne
 * physique ne fait que rapprocher un compte `client` de sa demande — d'où la
 * jointure du service des personnes, inutile ici.
 */
final class EnrolledCompanyService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Colonnes de tri exposées, et la colonne réelle derrière chacune.
     *
     * Les clés reprennent celles de `/admin/enrolled-persons` pour que les deux
     * listes s'appellent de la même façon depuis le backoffice.
     *
     * @var array<string, string>
     */
    private const TRIS = [
        'enrolled_at' => 'approved_at',
        'legal_name' => 'legal_name',
        'email' => 'company_email',
    ];

    /**
     * @return LengthAwarePaginator<int, EnrolledCompany>
     */
    public function list(EnrolledCompanyListFilters $filters): LengthAwarePaginator
    {
        $query = EnrolledCompany::query()
            ->where('status', EnrolledCompany::STATUS_ACTIVE);

        if (is_string($filters->q) && $filters->q !== '') {
            $pattern = SqlLike::contains($filters->q);
            $query->where(function ($sub) use ($pattern) {
                $sub->where('legal_name', 'like', $pattern)
                    ->orWhere('company_email', 'like', $pattern)
                    ->orWhere('registration_number', 'like', $pattern)
                    ->orWhere('identifiant', 'like', $pattern);
            });
        }

        $orderBy = self::TRIS[$filters->orderBy] ?? 'approved_at';

        $query->orderBy($orderBy, $filters->orderDir);

        return $query->paginate($filters->perPage);
    }

    public function show(string $id): ServiceResult
    {
        try {
            $company = EnrolledCompany::query()
                ->where('status', EnrolledCompany::STATUS_ACTIVE)
                ->where('id', $id)
                ->firstOrFail();

            return ServiceResult::ok('Entreprise enrôlée récupérée.', $company);
        } catch (ModelNotFoundException) {
            return ServiceResult::fail('Entreprise enrôlée introuvable.', null, 404);
        } catch (Exception $e) {
            Log::error('Failed to retrieve enrolled company: '.$e->getMessage());

            return ServiceResult::fail('Impossible de récupérer l\'entreprise enrôlée.', null, 500);
        }
    }

    public function updateStatus(string $id, EnrolledCompanyStatus $status, ?string $actorUserId): ServiceResult
    {
        try {
            $company = EnrolledCompany::query()->where('id', $id)->firstOrFail();
            $previous = $company->statusValue();

            $company->status = $status;
            $company->save();

            $this->activityLog->record(
                ActivityLogAction::EntrepriseStatutModifie,
                sprintf('Statut de l\'entreprise %s modifié.', $company->identifiant),
                $actorUserId,
                $company->enrollment_request_id,
                [
                    'enrolled_company_id' => $company->id,
                    'identifiant' => $company->identifiant,
                    'ancien_statut' => $previous,
                    'nouveau_statut' => $status->value,
                ],
            );

            return ServiceResult::ok('Statut de l\'entreprise mis à jour.', $company);
        } catch (ModelNotFoundException) {
            return ServiceResult::fail('Entreprise enrôlée introuvable.', null, 404);
        } catch (Exception $e) {
            Log::error('Failed to update enrolled company status: '.$e->getMessage());

            return ServiceResult::fail('Impossible de mettre à jour le statut de l\'entreprise.', null, 500);
        }
    }
}
