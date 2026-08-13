<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Models\EnrollmentRequest;

/**
 * Shapes `analyse_kyc` for personne physique agent/responsable detail views.
 * Requires {@see FormatsEnrollmentDocuments} on the using class (for selfie URL).
 */
trait MapsEnrollmentKycAnalysis
{
    /**
     * @return array{
     *     liveness: mixed,
     *     similarity: mixed,
     *     similarity_percent: int|null,
     *     risk_score: mixed,
     *     details: mixed,
     *     document_identite: array<string, mixed>,
     *     selfie: array{url: ?string, capture_le: null},
     *     etapes: array{
     *         document_ajoute: bool,
     *         informations_extraites: bool,
     *         liveness_effectue: bool,
     *         visage_compare: bool
     *     }
     * }
     */
    protected function analyseKycPhysique(EnrollmentRequest $enrollment): array
    {
        $kyc = is_array($enrollment->kyc_data) ? $enrollment->kyc_data : [];
        $documents = is_array($enrollment->documents) ? $enrollment->documents : [];
        $details = $enrollment->analysis_details;
        $similarity = $enrollment->similarity;
        $liveness = $enrollment->liveness;

        return [
            'liveness' => $liveness,
            'similarity' => $similarity,
            'similarity_percent' => $this->similarityPercent($similarity),
            'risk_score' => $enrollment->risk_score,
            'details' => $details,
            'document_identite' => $this->documentIdentiteFromKyc($kyc, $details),
            'selfie' => [
                'url' => $this->documentUrl($documents, 'selfie'),
                'capture_le' => null,
            ],
            'etapes' => [
                'document_ajoute' => $this->hasDocumentSlot($documents, 'recto'),
                'informations_extraites' => $this->hasExtractedIdentityInfo($kyc, $details),
                'liveness_effectue' => $this->isLivenessEffectue($liveness),
                'visage_compare' => $similarity !== null && $similarity !== '',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $kyc
     * @return array{
     *     type_piece: mixed,
     *     pays: mixed,
     *     verifie: bool,
     *     numero_document: mixed,
     *     nom: mixed,
     *     prenoms: mixed,
     *     date_naissance: mixed,
     *     nationalite: mixed,
     *     date_expiration: mixed
     * }
     */
    private function documentIdentiteFromKyc(array $kyc, mixed $details): array
    {
        $ocr = [];
        if (is_array($details) && isset($details['ocr_data']) && is_array($details['ocr_data'])) {
            $ocr = $details['ocr_data'];
        }

        $pick = function (string ...$keys) use ($kyc, $ocr): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $kyc) && $kyc[$key] !== null && $kyc[$key] !== '') {
                    return $kyc[$key];
                }
                if (array_key_exists($key, $ocr) && $ocr[$key] !== null && $ocr[$key] !== '') {
                    return $ocr[$key];
                }
            }

            return null;
        };

        return [
            'type_piece' => $pick('document_type', 'type_piece'),
            'pays' => $pick('country_of_residence', 'issuing_state', 'pays'),
            'verifie' => $this->isDocumentVerified($details),
            'numero_document' => $pick('document_number', 'numero_document'),
            'nom' => $pick('name', 'nom'),
            'prenoms' => $pick('first_name', 'prenoms', 'prenom'),
            'date_naissance' => $pick('date_of_birth', 'date_naissance'),
            'nationalite' => $pick('nationality', 'nationalite'),
            'date_expiration' => $pick('date_expiration', 'expiry_date', 'date_of_expiry'),
        ];
    }

    private function isDocumentVerified(mixed $details): bool
    {
        if (! is_array($details)) {
            return false;
        }

        if (($details['doc_validity'] ?? null) === true) {
            return true;
        }

        if (($details['status'] ?? null) === 'OK') {
            return true;
        }

        if (($details['error'] ?? null) !== null) {
            return false;
        }

        return isset($details['ocr_data']) || isset($details['document']);
    }

    /**
     * @param  array<string, mixed>  $documents
     */
    private function hasDocumentSlot(array $documents, string $key): bool
    {
        return isset($documents[$key]) && is_string($documents[$key]) && $documents[$key] !== '';
    }

    /**
     * @param  array<string, mixed>  $kyc
     */
    private function hasExtractedIdentityInfo(array $kyc, mixed $details): bool
    {
        if (($kyc['document_number'] ?? '') !== '' || ($kyc['name'] ?? '') !== '') {
            return true;
        }

        if (! is_array($details)) {
            return false;
        }

        if (isset($details['ocr_data']) && is_array($details['ocr_data']) && $details['ocr_data'] !== []) {
            return true;
        }

        return isset($details['document']);
    }

    private function isLivenessEffectue(mixed $liveness): bool
    {
        if ($liveness === null || $liveness === '') {
            return false;
        }

        $value = strtolower((string) $liveness);

        return ! in_array($value, ['failed', 'error', 'liveness_not_confirmed', 'liveness_failed'], true);
    }

    private function similarityPercent(mixed $similarity): ?int
    {
        if ($similarity === null || $similarity === '') {
            return null;
        }

        if (! is_numeric($similarity)) {
            return null;
        }

        $value = (float) $similarity;

        if ($value < 0) {
            return 0;
        }

        if ($value <= 1.0) {
            return (int) round($value * 100);
        }

        return (int) max(0, min(100, (int) round($value)));
    }
}
