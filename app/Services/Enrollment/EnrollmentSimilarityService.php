<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRequest;
use App\Models\Identity;
use App\Models\User;

class EnrollmentSimilarityService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function findSimilar(EnrollmentRequest $enrollment): array
    {
        if ($enrollment->isPersonneMorale()) {
            return $this->findSimilarMorale($enrollment);
        }

        $kyc = $enrollment->kyc_data ?? [];
        $name = strtoupper(trim((string) ($kyc['name'] ?? '')));
        $firstName = strtoupper(trim((string) ($kyc['first_name'] ?? '')));
        $dob = (string) ($kyc['date_of_birth'] ?? '');
        $nationality = strtoupper(trim((string) ($kyc['nationality'] ?? '')));
        $documentNumber = strtoupper(trim((string) ($kyc['document_number'] ?? '')));

        $max = (int) config('enrollment.similarity.max_results', 5);
        $matches = collect();

        $approvedUsers = User::query()
            ->where('status', 'ACTIVE')
            ->whereHas('identities', fn ($q) => $q->where('status', 'APPROVED'))
            ->with(['identities' => fn ($q) => $q->where('status', 'APPROVED')])
            ->limit(200)
            ->get();

        foreach ($approvedUsers as $user) {
            $score = 0;
            $matchedFields = [];

            if ($name !== '' && strtoupper((string) $user->name) === $name) {
                $score += 30;
                $matchedFields[] = 'name';
            }
            if ($firstName !== '' && strtoupper((string) $user->first_name) === $firstName) {
                $score += 25;
                $matchedFields[] = 'first_name';
            }
            if ($nationality !== '' && strtoupper((string) $user->nationality) === $nationality) {
                $score += 15;
                $matchedFields[] = 'nationality';
            }

            foreach ($user->identities as $identity) {
                $proof = is_string($identity->proof)
                    ? (json_decode($identity->proof, true) ?: [])
                    : (array) $identity->proof;

                $idDoc = strtoupper(trim((string) ($proof['document_number'] ?? '')));
                if ($documentNumber !== '' && $idDoc === $documentNumber) {
                    $score += 40;
                    $matchedFields[] = 'document_number';
                }

                $idNat = strtoupper(trim((string) ($proof['nationality'] ?? '')));
                if ($nationality !== '' && $idNat === $nationality && ! in_array('nationality', $matchedFields, true)) {
                    $score += 10;
                    $matchedFields[] = 'nationality';
                }
            }

            if ($score >= 40) {
                $matches->push([
                    'type' => 'approved_identity',
                    'user_id' => $user->id,
                    'npi' => $user->npi,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'score' => min(100, $score),
                    'matched_fields' => array_values(array_unique($matchedFields)),
                ]);
            }
        }

        $otherDemandes = EnrollmentRequest::query()
            ->where('id', '!=', $enrollment->id)
            ->whereIn('status', [
                ...EnrollmentStatus::open(),
                EnrollmentStatus::Approuvee->value,
            ])
            ->where('type', 'PERSONNE_PHYSIQUE')
            ->limit(200)
            ->get();

        foreach ($otherDemandes as $other) {
            $otherKyc = $other->kyc_data ?? [];
            $score = 0;
            $matchedFields = [];

            if ($name !== '' && strtoupper(trim((string) ($otherKyc['name'] ?? ''))) === $name) {
                $score += 30;
                $matchedFields[] = 'name';
            }
            if ($firstName !== '' && strtoupper(trim((string) ($otherKyc['first_name'] ?? ''))) === $firstName) {
                $score += 25;
                $matchedFields[] = 'first_name';
            }
            if ($dob !== '' && (string) ($otherKyc['date_of_birth'] ?? '') === $dob) {
                $score += 20;
                $matchedFields[] = 'date_of_birth';
            }
            if ($nationality !== '' && strtoupper(trim((string) ($otherKyc['nationality'] ?? ''))) === $nationality) {
                $score += 15;
                $matchedFields[] = 'nationality';
            }
            if ($documentNumber !== '' && strtoupper(trim((string) ($otherKyc['document_number'] ?? ''))) === $documentNumber) {
                $score += 40;
                $matchedFields[] = 'document_number';
            }

            if ($score >= 40) {
                $matches->push([
                    'type' => 'enrollment_request',
                    'enrollment_request_id' => $other->id,
                    'status' => $other->status->value,
                    'email' => $other->email,
                    'score' => min(100, $score),
                    'matched_fields' => array_values(array_unique($matchedFields)),
                ]);
            }
        }

        return $matches
            ->sortByDesc('score')
            ->take($max)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findSimilarMorale(EnrollmentRequest $enrollment): array
    {
        $kyc = $enrollment->kyc_data ?? [];
        $legalName = strtoupper(trim((string) ($kyc['legal_name'] ?? '')));
        $registrationNumber = strtoupper(trim((string) ($kyc['registration_number'] ?? '')));
        $country = strtoupper(trim((string) ($kyc['country_of_incorporation'] ?? '')));
        $max = (int) config('enrollment.similarity.max_results', 5);
        $matches = collect();

        $approvedIdentities = Identity::query()
            ->where('type', 'PERSONNE_MORALE')
            ->where('status', 'APPROVED')
            ->with('user')
            ->limit(200)
            ->get();

        foreach ($approvedIdentities as $identity) {
            $proof = is_string($identity->proof)
                ? (json_decode($identity->proof, true) ?: [])
                : (array) $identity->proof;
            $company = $proof['company'] ?? [];
            $score = 0;
            $matchedFields = [];

            if ($registrationNumber !== '' && strtoupper(trim((string) ($company['registration_number'] ?? ''))) === $registrationNumber) {
                $score += 50;
                $matchedFields[] = 'registration_number';
            }
            if ($legalName !== '' && strtoupper(trim((string) ($company['legal_name'] ?? ''))) === $legalName) {
                $score += 30;
                $matchedFields[] = 'legal_name';
            }
            if ($country !== '' && strtoupper(trim((string) ($company['country_of_incorporation'] ?? ''))) === $country) {
                $score += 20;
                $matchedFields[] = 'country_of_incorporation';
            }

            if ($score >= 40) {
                $matches->push([
                    'type' => 'approved_company_identity',
                    'user_id' => $identity->user_id,
                    'identity_id' => $identity->id,
                    'legal_name' => $company['legal_name'] ?? null,
                    'score' => min(100, $score),
                    'matched_fields' => array_values(array_unique($matchedFields)),
                ]);
            }
        }

        $otherDemandes = EnrollmentRequest::query()
            ->where('id', '!=', $enrollment->id)
            ->where('type', 'PERSONNE_MORALE')
            ->whereIn('status', [
                ...EnrollmentStatus::open(),
                EnrollmentStatus::Approuvee->value,
                EnrollmentStatus::AwaitingContactVerification->value,
            ])
            ->limit(200)
            ->get();

        foreach ($otherDemandes as $other) {
            $otherKyc = $other->kyc_data ?? [];
            $score = 0;
            $matchedFields = [];

            if ($registrationNumber !== '' && strtoupper(trim((string) ($otherKyc['registration_number'] ?? ''))) === $registrationNumber) {
                $score += 50;
                $matchedFields[] = 'registration_number';
            }
            if ($legalName !== '' && strtoupper(trim((string) ($otherKyc['legal_name'] ?? ''))) === $legalName) {
                $score += 30;
                $matchedFields[] = 'legal_name';
            }
            if ($country !== '' && strtoupper(trim((string) ($otherKyc['country_of_incorporation'] ?? ''))) === $country) {
                $score += 20;
                $matchedFields[] = 'country_of_incorporation';
            }

            if ($score >= 40) {
                $matches->push([
                    'type' => 'enrollment_request',
                    'enrollment_request_id' => $other->id,
                    'status' => $other->status->value,
                    'legal_name' => $otherKyc['legal_name'] ?? null,
                    'score' => min(100, $score),
                    'matched_fields' => array_values(array_unique($matchedFields)),
                ]);
            }
        }

        return $matches
            ->sortByDesc('score')
            ->take($max)
            ->values()
            ->all();
    }
}
