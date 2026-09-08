<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Psceq\SearchPsceqCompaniesRequest;
use App\Http\Requests\Psceq\ShowPsceqCompanyRequest;
use App\Http\Resources\PsceqCompanyAdministrateurResource;
use App\Http\Resources\PsceqCompanyDetailResource;
use App\Http\Resources\PsceqCompanyListResource;
use App\Http\Resources\PsceqCompanyStatutResource;
use App\Models\EnrolledCompany;
use App\Services\Psceq\PsceqCompanyService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;

#[Group('PSCEQ')]
final class PsceqCompanyController extends BaseController
{
    public function __construct(
        private readonly PsceqCompanyService $companies,
    ) {}

    /**
     * Search enrolled companies by legal name
     *
     * Query `q` is required (min 2 characters). Matches `UPPER(TRIM(legal_name))`.
     * Short payload only: identifiant, raison_sociale, pays_origine, statut.
     * No email, documents, or demande_id.
     */
    public function search(SearchPsceqCompaniesRequest $request): JsonResponse
    {
        $this->authorize('queryAsPsceq');

        $companies = $this->companies->search((string) $request->validated('q'));

        return $this->sendResponse(
            'Entreprises trouvées.',
            PsceqCompanyListResource::collection($companies),
        );
    }

    /**
     * Company identity by identifiant
     *
     * Lookup by public `PM…` identifiant, never the internal UUID.
     * Unknown or malformed identifiant → 404 Entreprise introuvable.
     */
    #[PathParameter('identifiant', description: 'Identifiant entreprise (PM + 9 caractères alphanumériques).', type: 'string')]
    public function show(ShowPsceqCompanyRequest $request): JsonResponse
    {
        $this->authorize('queryAsPsceq');

        return $this->respondCompany(
            (string) $request->validated('identifiant'),
            'show',
            fn (EnrolledCompany $company) => $this->sendResponse('Entreprise trouvée.', new PsceqCompanyDetailResource($company)),
        );
    }

    /**
     * Legal representative of an enrolled company
     *
     * Returns `nom` / `prenoms` of the représentant légal only.
     */
    #[PathParameter('identifiant', description: 'Identifiant entreprise (PM + 9 caractères alphanumériques).', type: 'string')]
    public function administrateur(ShowPsceqCompanyRequest $request): JsonResponse
    {
        $this->authorize('queryAsPsceq');

        return $this->respondCompany(
            (string) $request->validated('identifiant'),
            'administrateur',
            fn (EnrolledCompany $company) => $this->sendResponse(
                'Administrateur de l\'entreprise.',
                new PsceqCompanyAdministrateurResource($company),
            ),
        );
    }

    /**
     * Enrollment status of a company
     *
     * `{ identifiant, statut, existe: true }` when found. Same 404 otherwise.
     */
    #[PathParameter('identifiant', description: 'Identifiant entreprise (PM + 9 caractères alphanumériques).', type: 'string')]
    public function statut(ShowPsceqCompanyRequest $request): JsonResponse
    {
        $this->authorize('queryAsPsceq');

        return $this->respondCompany(
            (string) $request->validated('identifiant'),
            'statut',
            fn (EnrolledCompany $company) => $this->sendResponse('Statut de l\'entreprise.', new PsceqCompanyStatutResource($company)),
        );
    }

    /**
     * @param  callable(EnrolledCompany): JsonResponse  $found
     */
    private function respondCompany(string $identifiant, string $route, callable $found): JsonResponse
    {
        $company = $this->companies->findByIdentifiant($identifiant);
        match ($route) {
            'administrateur' => $this->companies->logAdministrateur($identifiant, $company !== null),
            'statut' => $this->companies->logStatut($identifiant, $company !== null),
            default => $this->companies->logShow($identifiant, $company !== null),
        };

        if ($company === null) {
            return $this->sendError('Entreprise introuvable.', [], 404);
        }

        return $found($company);
    }
}
