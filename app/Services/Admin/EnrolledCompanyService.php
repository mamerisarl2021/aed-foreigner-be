<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\EnrolledCompany;
use App\Services\ServiceResult;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
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
    public function list(Request $request): LengthAwarePaginator
    {
        $query = EnrolledCompany::query()
            ->where('status', EnrolledCompany::STATUS_ACTIVE);

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('legal_name', 'like', "%{$q}%")
                    ->orWhere('company_email', 'like', "%{$q}%")
                    ->orWhere('registration_number', 'like', "%{$q}%")
                    ->orWhere('identifiant', 'like', "%{$q}%");
            });
        }

        $orderBy = self::TRIS[$request->input('order_by', 'enrolled_at')] ?? 'approved_at';
        $orderDir = strtolower((string) $request->input('order_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($orderBy, $orderDir);

        $perPage = min((int) $request->input('per_page', $request->input('perPage', 15)), 100);

        return $query->paginate($perPage);
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
}
