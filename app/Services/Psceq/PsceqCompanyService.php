<?php

declare(strict_types=1);

namespace App\Services\Psceq;

use App\Enums\ActivityLogAction;
use App\Http\Middleware\EnsurePsceqApiKey;
use App\Models\EnrolledCompany;
use App\Services\ActivityLog\ActivityLogService;
use App\Support\SqlLike;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Request;

final class PsceqCompanyService
{
    public const IDENTIFIANT_PATTERN = '/^PM[A-Z0-9]{9}$/';

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * @return Collection<int, EnrolledCompany>
     */
    public function search(string $q): Collection
    {
        $normalized = mb_strtoupper(trim($q), 'UTF-8');

        $companies = EnrolledCompany::query()
            ->whereRaw('UPPER(TRIM(legal_name)) LIKE ? ESCAPE E\'\\\\\'', [SqlLike::contains($normalized)])
            ->orderBy('legal_name')
            ->limit(50)
            ->get();

        $this->logConsultation('search', found: $companies->isNotEmpty(), query: $q);

        return $companies;
    }

    public function findByIdentifiant(string $identifiant): ?EnrolledCompany
    {
        $normalized = strtoupper(trim($identifiant));
        if (preg_match(self::IDENTIFIANT_PATTERN, $normalized) !== 1) {
            return null;
        }

        return EnrolledCompany::query()
            ->where('identifiant', $normalized)
            ->first();
    }

    public function logShow(string $identifiant, bool $found): void
    {
        $this->logConsultation('show', $found, identifiant: $identifiant);
    }

    public function logAdministrateur(string $identifiant, bool $found): void
    {
        $this->logConsultation('administrateur', $found, identifiant: $identifiant);
    }

    public function logStatut(string $identifiant, bool $found): void
    {
        $this->logConsultation('statut', $found, identifiant: $identifiant);
    }

    private function logConsultation(
        string $route,
        bool $found,
        ?string $identifiant = null,
        ?string $query = null,
    ): void {
        $request = Request::instance();
        $clientId = $request->attributes->get(EnsurePsceqApiKey::CLIENT_ID_ATTRIBUTE);
        $prefix = $request->attributes->get(EnsurePsceqApiKey::KEY_PREFIX_ATTRIBUTE);

        $metadata = [
            'psceq_client_id' => is_string($clientId) ? $clientId : null,
            'key_prefix' => is_string($prefix) ? $prefix : null,
            'route' => $route,
            'found' => $found,
        ];
        if ($identifiant !== null) {
            $metadata['identifiant'] = $identifiant;
        }
        if ($query !== null) {
            $metadata['q'] = $query;
        }

        $this->activityLog->record(
            ActivityLogAction::PsceqConsultation,
            'Consultation PSCEQ ('.$route.').',
            null,
            null,
            $metadata,
        );
    }
}
