<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\EnrollmentRequest;
use App\Models\User;

class EnrollmentSimilarityService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function findSimilar(EnrollmentRequest $enrollment): array
    {
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
            ->whereIn('status', ['APPROVED', 'APPROVED_BY_AGENT', 'PENDING'])
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
                    'status' => $other->status,
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
}
