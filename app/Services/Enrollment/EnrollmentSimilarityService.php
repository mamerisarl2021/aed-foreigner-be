<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\User;
use App\Support\JsonbText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class EnrollmentSimilarityService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function findSimilar(EnrollmentRequest $enrollment): array
    {
        $ttl = max(0, (int) config('enrollment.similarity.cache_ttl_seconds', 120));
        if ($ttl === 0) {
            return $this->computeSimilar($enrollment);
        }

        $fingerprint = hash('sha256', (string) json_encode([
            $enrollment->id,
            $enrollment->status->value,
            $enrollment->kyc_data,
            $enrollment->updated_at?->getTimestamp(),
        ]));

        /** @var list<array<string, mixed>> $matches */
        $matches = Cache::remember(
            'enrollment.similarity.'.$fingerprint,
            $ttl,
            fn (): array => $this->computeSimilar($enrollment),
        );

        return $matches;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function computeSimilar(EnrollmentRequest $enrollment): array
    {
        if ($enrollment->isPersonneMorale()) {
            return $this->findSimilarMorale($enrollment);
        }

        $needle = $this->physiqueNeedle($enrollment);
        $matches = collect()
            ->concat($this->similarApprovedUsers($needle))
            ->concat($this->similarPhysiqueDemandes($enrollment, $needle));

        return $this->topMatches($matches);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findSimilarMorale(EnrollmentRequest $enrollment): array
    {
        $needle = $this->moraleNeedle($enrollment);
        $matches = collect()
            ->concat($this->similarApprovedCompanies($needle))
            ->concat($this->similarMoraleDemandes($enrollment, $needle));

        return $this->topMatches($matches);
    }

    /**
     * @return array{name: string, first_name: string, dob: string, nationality: string, document_number: string}
     */
    private function physiqueNeedle(EnrollmentRequest $enrollment): array
    {
        $kyc = $enrollment->kyc_data ?? [];

        return [
            'name' => strtoupper(trim((string) ($kyc['name'] ?? ''))),
            'first_name' => strtoupper(trim((string) ($kyc['first_name'] ?? ''))),
            'dob' => (string) ($kyc['date_of_birth'] ?? ''),
            'nationality' => strtoupper(trim((string) ($kyc['nationality'] ?? ''))),
            'document_number' => strtoupper(trim((string) ($kyc['document_number'] ?? ''))),
        ];
    }

    /**
     * @return array{legal_name: string, registration_number: string, country: string}
     */
    private function moraleNeedle(EnrollmentRequest $enrollment): array
    {
        $kyc = $enrollment->kyc_data ?? [];

        return [
            'legal_name' => strtoupper(trim((string) ($kyc['legal_name'] ?? ''))),
            'registration_number' => strtoupper(trim((string) ($kyc['registration_number'] ?? ''))),
            'country' => strtoupper(trim((string) ($kyc['country_of_incorporation'] ?? ''))),
        ];
    }

    /**
     * @param  array{name: string, first_name: string, dob: string, nationality: string, document_number: string}  $needle
     * @return Collection<int, array<string, mixed>>
     */
    private function similarApprovedUsers(array $needle): Collection
    {
        $matches = collect();
        if ($needle['name'] === '' && $needle['first_name'] === '' && $needle['nationality'] === '' && $needle['document_number'] === '') {
            return $matches;
        }

        $approvedUsers = User::query()
            ->where('status', 'ACTIVE')
            ->whereHas('identities', fn ($q) => $q->where('status', 'APPROVED'))
            ->where(function (Builder $q) use ($needle): void {
                $this->orEqual($q, 'name', $needle['name']);
                $this->orEqual($q, 'first_name', $needle['first_name']);
                $this->orEqual($q, 'nationality', $needle['nationality']);
                if ($needle['document_number'] !== '') {
                    $documentNumber = $needle['document_number'];
                    $q->orWhereHas('identities', function (Builder $iq) use ($documentNumber): void {
                        $sql = JsonbText::upperTrimEqualsSql('proof', '$.document_number');
                        if ($sql === null) {
                            return;
                        }
                        $iq->where('status', 'APPROVED')
                            ->whereRaw($sql, [$documentNumber]);
                    });
                }
            })
            ->with(['identities' => fn ($q) => $q->where('status', 'APPROVED')])
            ->limit(200)
            ->get();

        foreach ($approvedUsers as $user) {
            $hit = $this->scoreApprovedUser($user, $needle);
            if ($hit !== null) {
                $matches->push($hit);
            }
        }

        return $matches;
    }

    /**
     * @param  array{name: string, first_name: string, dob: string, nationality: string, document_number: string}  $needle
     * @return array<string, mixed>|null
     */
    private function scoreApprovedUser(User $user, array $needle): ?array
    {
        $score = 0;
        $matchedFields = [];

        if ($needle['name'] !== '' && strtoupper((string) $user->name) === $needle['name']) {
            $score += 30;
            $matchedFields[] = 'name';
        }
        if ($needle['first_name'] !== '' && strtoupper((string) $user->first_name) === $needle['first_name']) {
            $score += 25;
            $matchedFields[] = 'first_name';
        }
        if ($needle['nationality'] !== '' && strtoupper((string) $user->nationality) === $needle['nationality']) {
            $score += 15;
            $matchedFields[] = 'nationality';
        }

        foreach ($user->identities as $identity) {
            $proof = $this->proofArray($identity);

            $idDoc = strtoupper(trim((string) ($proof['document_number'] ?? '')));
            if ($needle['document_number'] !== '' && $idDoc === $needle['document_number']) {
                $score += 40;
                $matchedFields[] = 'document_number';
            }

            $idNat = strtoupper(trim((string) ($proof['nationality'] ?? '')));
            if ($needle['nationality'] !== '' && $idNat === $needle['nationality'] && ! in_array('nationality', $matchedFields, true)) {
                $score += 10;
                $matchedFields[] = 'nationality';
            }
        }

        if ($score < 40) {
            return null;
        }

        return [
            'type' => 'approved_identity',
            'user_id' => $user->id,
            'npi' => $user->npi,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'score' => min(100, $score),
            'matched_fields' => array_values(array_unique($matchedFields)),
        ];
    }

    /**
     * @param  array{name: string, first_name: string, dob: string, nationality: string, document_number: string}  $needle
     * @return Collection<int, array<string, mixed>>
     */
    private function similarPhysiqueDemandes(EnrollmentRequest $enrollment, array $needle): Collection
    {
        $matches = collect();
        if (
            $needle['name'] === ''
            && $needle['first_name'] === ''
            && $needle['dob'] === ''
            && $needle['nationality'] === ''
            && $needle['document_number'] === ''
        ) {
            return $matches;
        }

        $otherDemandes = EnrollmentRequest::query()
            ->where('id', '!=', $enrollment->id)
            ->whereIn('status', [
                ...EnrollmentStatus::open(),
                EnrollmentStatus::Approuvee->value,
            ])
            ->where('type', 'PERSONNE_PHYSIQUE')
            ->where(function (Builder $q) use ($needle): void {
                $this->orJsonEqual($q, 'kyc_data', '$.name', $needle['name']);
                $this->orJsonEqual($q, 'kyc_data', '$.first_name', $needle['first_name']);
                $this->orJsonEqual($q, 'kyc_data', '$.date_of_birth', $needle['dob']);
                $this->orJsonEqual($q, 'kyc_data', '$.nationality', $needle['nationality']);
                $this->orJsonEqual($q, 'kyc_data', '$.document_number', $needle['document_number']);
            })
            ->limit(200)
            ->get();

        foreach ($otherDemandes as $other) {
            $hit = $this->scorePhysiqueDemande($other, $needle);
            if ($hit !== null) {
                $matches->push($hit);
            }
        }

        return $matches;
    }

    /**
     * @param  array{name: string, first_name: string, dob: string, nationality: string, document_number: string}  $needle
     * @return array<string, mixed>|null
     */
    private function scorePhysiqueDemande(EnrollmentRequest $other, array $needle): ?array
    {
        $otherKyc = $other->kyc_data ?? [];
        $score = 0;
        $matchedFields = [];

        if ($needle['name'] !== '' && strtoupper(trim((string) ($otherKyc['name'] ?? ''))) === $needle['name']) {
            $score += 30;
            $matchedFields[] = 'name';
        }
        if ($needle['first_name'] !== '' && strtoupper(trim((string) ($otherKyc['first_name'] ?? ''))) === $needle['first_name']) {
            $score += 25;
            $matchedFields[] = 'first_name';
        }
        if ($needle['dob'] !== '' && (string) ($otherKyc['date_of_birth'] ?? '') === $needle['dob']) {
            $score += 20;
            $matchedFields[] = 'date_of_birth';
        }
        if ($needle['nationality'] !== '' && strtoupper(trim((string) ($otherKyc['nationality'] ?? ''))) === $needle['nationality']) {
            $score += 15;
            $matchedFields[] = 'nationality';
        }
        if ($needle['document_number'] !== '' && strtoupper(trim((string) ($otherKyc['document_number'] ?? ''))) === $needle['document_number']) {
            $score += 40;
            $matchedFields[] = 'document_number';
        }

        if ($score < 40) {
            return null;
        }

        return [
            'type' => 'enrollment_request',
            'enrollment_request_id' => $other->id,
            'status' => $other->status->value,
            'email' => $other->email,
            'score' => min(100, $score),
            'matched_fields' => array_values(array_unique($matchedFields)),
        ];
    }

    /**
     * @param  array{legal_name: string, registration_number: string, country: string}  $needle
     * @return Collection<int, array<string, mixed>>
     */
    private function similarApprovedCompanies(array $needle): Collection
    {
        $matches = collect();
        if ($needle['legal_name'] === '' && $needle['registration_number'] === '' && $needle['country'] === '') {
            return $matches;
        }

        $approvedIdentities = Identity::query()
            ->where('type', 'PERSONNE_MORALE')
            ->where('status', 'APPROVED')
            ->where(function (Builder $q) use ($needle): void {
                $this->orJsonEqual($q, 'proof', '$.company.registration_number', $needle['registration_number']);
                $this->orJsonEqual($q, 'proof', '$.company.legal_name', $needle['legal_name']);
                $this->orJsonEqual($q, 'proof', '$.company.country_of_incorporation', $needle['country']);
            })
            ->with('user')
            ->limit(200)
            ->get();

        foreach ($approvedIdentities as $identity) {
            $hit = $this->scoreApprovedCompany($identity, $needle);
            if ($hit !== null) {
                $matches->push($hit);
            }
        }

        return $matches;
    }

    /**
     * @param  array{legal_name: string, registration_number: string, country: string}  $needle
     * @return array<string, mixed>|null
     */
    private function scoreApprovedCompany(Identity $identity, array $needle): ?array
    {
        $proof = $this->proofArray($identity);
        $company = is_array($proof['company'] ?? null) ? $proof['company'] : [];
        $hit = $this->scoreMoraleFields($needle, $company);
        if ($hit === null) {
            return null;
        }

        return [
            'type' => 'approved_company_identity',
            'user_id' => $identity->user_id,
            'identity_id' => $identity->id,
            'legal_name' => $company['legal_name'] ?? null,
            'score' => $hit['score'],
            'matched_fields' => $hit['matched_fields'],
        ];
    }

    /**
     * @param  array{legal_name: string, registration_number: string, country: string}  $needle
     * @return Collection<int, array<string, mixed>>
     */
    private function similarMoraleDemandes(EnrollmentRequest $enrollment, array $needle): Collection
    {
        $matches = collect();
        if ($needle['legal_name'] === '' && $needle['registration_number'] === '' && $needle['country'] === '') {
            return $matches;
        }

        $otherDemandes = EnrollmentRequest::query()
            ->where('id', '!=', $enrollment->id)
            ->where('type', 'PERSONNE_MORALE')
            ->whereIn('status', [
                ...EnrollmentStatus::open(),
                EnrollmentStatus::Approuvee->value,
                EnrollmentStatus::AwaitingContactVerification->value,
                EnrollmentStatus::ACorriger->value,
            ])
            ->where(function (Builder $q) use ($needle): void {
                $this->orJsonEqual($q, 'kyc_data', '$.registration_number', $needle['registration_number']);
                $this->orJsonEqual($q, 'kyc_data', '$.legal_name', $needle['legal_name']);
                $this->orJsonEqual($q, 'kyc_data', '$.country_of_incorporation', $needle['country']);
            })
            ->limit(200)
            ->get();

        foreach ($otherDemandes as $other) {
            $otherKyc = $other->kyc_data ?? [];
            $hit = $this->scoreMoraleFields($needle, $otherKyc);
            if ($hit === null) {
                continue;
            }

            $matches->push([
                'type' => 'enrollment_request',
                'enrollment_request_id' => $other->id,
                'status' => $other->status->value,
                'legal_name' => $otherKyc['legal_name'] ?? null,
                'score' => $hit['score'],
                'matched_fields' => $hit['matched_fields'],
            ]);
        }

        return $matches;
    }

    /**
     * @param  array{legal_name: string, registration_number: string, country: string}  $needle
     * @param  array<string, mixed>  $fields
     * @return array{score: int, matched_fields: list<string>}|null
     */
    private function scoreMoraleFields(array $needle, array $fields): ?array
    {
        $score = 0;
        $matchedFields = [];

        if ($needle['registration_number'] !== '' && strtoupper(trim((string) ($fields['registration_number'] ?? ''))) === $needle['registration_number']) {
            $score += 50;
            $matchedFields[] = 'registration_number';
        }
        if ($needle['legal_name'] !== '' && strtoupper(trim((string) ($fields['legal_name'] ?? ''))) === $needle['legal_name']) {
            $score += 30;
            $matchedFields[] = 'legal_name';
        }
        if ($needle['country'] !== '' && strtoupper(trim((string) ($fields['country_of_incorporation'] ?? ''))) === $needle['country']) {
            $score += 20;
            $matchedFields[] = 'country_of_incorporation';
        }

        if ($score < 40) {
            return null;
        }

        return [
            'score' => min(100, $score),
            'matched_fields' => array_values(array_unique($matchedFields)),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $matches
     * @return list<array<string, mixed>>
     */
    private function topMatches(Collection $matches): array
    {
        $max = (int) config('enrollment.similarity.max_results', 5);

        return array_values($matches
            ->sortByDesc('score')
            ->take($max)
            ->values()
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function proofArray(Identity $identity): array
    {
        return $identity->proof ?? [];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function orEqual(Builder $query, string $column, string $value): void
    {
        if ($value === '' || ! in_array($column, ['name', 'first_name', 'nationality'], true)) {
            return;
        }

        $query->orWhereRaw("UPPER(TRIM({$column})) = ?", [$value]);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function orJsonEqual(Builder $query, string $column, string $path, string $value): void
    {
        if ($value === '' || ! in_array($column, ['kyc_data', 'proof'], true)) {
            return;
        }

        $sql = JsonbText::upperTrimEqualsSql($column, $path);
        if ($sql === null) {
            return;
        }

        $query->orWhereRaw($sql, [$value]);
    }
}
