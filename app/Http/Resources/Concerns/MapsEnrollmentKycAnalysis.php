<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Models\EnrollmentRequest;

/**
 * Shapes `analyse_kyc` for personne physique agent/responsable detail views.
 *
 * @mixin FormatsEnrollmentDocuments
 */
trait MapsEnrollmentKycAnalysis
{
    /**
     * Face API liveness status: 0 = confirmed.
     */
    private const LIVENESS_CONFIRMED = '0';

    /**
     * @return array{
     *     liveness: mixed,
     *     similarity: mixed,
     *     similarity_percent: int|null,
     *     risk_score: mixed,
     *     details: mixed,
     *     document_identite: array{
     *         type_piece: mixed,
     *         pays: mixed,
     *         verifie: bool,
     *         numero_document: mixed,
     *         nom: mixed,
     *         prenoms: mixed,
     *         date_naissance: mixed,
     *         nationalite: mixed,
     *         date_expiration: mixed
     *     },
     *     selfie: array{url: string|null, capture_le: string|null},
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
        $kyc = $this->asStringKeyedArray($enrollment->kyc_data);
        $documents = $this->asStringKeyedArray($enrollment->documents);
        $details = $enrollment->analysis_details;
        $similarity = $enrollment->similarity;
        $liveness = $enrollment->liveness;
        $ocr = $this->ocrBag($details);
        $documentName = $this->documentNameFromDetails($details);

        return [
            'liveness' => $liveness,
            'similarity' => $similarity,
            'similarity_percent' => $this->similarityPercent($similarity),
            'risk_score' => $enrollment->risk_score,
            'details' => $details,
            'document_identite' => $this->documentIdentiteFromKyc($kyc, $ocr, $documentName, $details),
            'selfie' => $this->kycSelfieBlock($documents),
            'etapes' => [
                'document_ajoute' => $this->hasDocumentSlot($documents, 'recto'),
                'informations_extraites' => $this->hasExtractedIdentityInfo($kyc, $ocr),
                'liveness_effectue' => $this->isLivenessConfirmed($liveness),
                'visage_compare' => $this->hasSimilarityScore($similarity),
            ],
        ];
    }

    /**
     * OCR wins over declared KYC so the review panel shows what the document said.
     *
     * @param  array<string, mixed>  $kyc
     * @param  array<string, mixed>  $ocr
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
    private function documentIdentiteFromKyc(array $kyc, array $ocr, ?string $documentName, mixed $details): array
    {
        [$namePays, $nameType] = $this->splitDocumentName($documentName);

        $pick = function (array $ocrKeys, array $kycKeys) use ($kyc, $ocr): mixed {
            foreach ($ocrKeys as $key) {
                if (array_key_exists($key, $ocr) && $ocr[$key] !== null && $ocr[$key] !== '') {
                    return $ocr[$key];
                }
            }
            foreach ($kycKeys as $key) {
                if (array_key_exists($key, $kyc) && $kyc[$key] !== null && $kyc[$key] !== '') {
                    return $kyc[$key];
                }
            }

            return null;
        };

        return [
            'type_piece' => $pick(['type_piece', 'document_type'], ['document_type', 'type_piece']) ?? $nameType,
            'pays' => $pick(['pays', 'issuing_state'], ['issuing_state']) ?? $namePays ?? $pick([], ['country_of_residence', 'pays']),
            'verifie' => $this->isDocumentVerified($details),
            'numero_document' => $pick(['numero_piece', 'numero_document', 'document_number'], ['document_number', 'numero_piece']),
            'nom' => $pick(['nom', 'name'], ['name', 'nom']),
            'prenoms' => $pick(['prenoms', 'prenom', 'first_name'], ['first_name', 'prenoms', 'prenom']),
            'date_naissance' => $pick(['date_naissance', 'date_of_birth'], ['date_of_birth', 'date_naissance']),
            'nationalite' => $pick(['nationalite', 'nationality'], ['nationality', 'nationalite']),
            'date_expiration' => $pick(['date_expiration', 'expiry_date', 'date_of_expiry'], ['date_expiration', 'expiry_date', 'date_of_expiry']),
        ];
    }

    /**
     * Verified only when Regula explicitly says the document is valid, with no error.
     * Presence of a `document` summary is not enough (KO paths still attach one).
     */
    private function isDocumentVerified(mixed $details): bool
    {
        if (! is_array($details)) {
            return false;
        }

        if (array_key_exists('error', $details) && $details['error'] !== null && $details['error'] !== '') {
            return false;
        }

        return ($details['doc_validity'] ?? null) === true;
    }

    /**
     * @return array<string, mixed>
     */
    private function ocrBag(mixed $details): array
    {
        if (! is_array($details)) {
            return [];
        }

        if (isset($details['ocr_data']) && is_array($details['ocr_data'])) {
            return $details['ocr_data'];
        }

        $document = $details['document'] ?? null;
        if (! is_array($document)) {
            return [];
        }

        foreach (['ocr', 'fields'] as $key) {
            if (isset($document[$key]) && is_array($document[$key])) {
                return $document[$key];
            }
        }

        return [];
    }

    private function documentNameFromDetails(mixed $details): ?string
    {
        if (! is_array($details)) {
            return null;
        }

        $document = $details['document'] ?? null;
        if (is_array($document) && isset($document['document_name']) && is_string($document['document_name']) && $document['document_name'] !== '') {
            return $document['document_name'];
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string} pays, type
     */
    private function splitDocumentName(?string $documentName): array
    {
        if ($documentName === null || trim($documentName) === '') {
            return [null, null];
        }

        if (preg_match('/^(.*?)\s+-\s+(.*)$/', trim($documentName), $matches) === 1) {
            return [trim($matches[1]) !== '' ? trim($matches[1]) : null, trim($matches[2]) !== '' ? trim($matches[2]) : null];
        }

        return [null, trim($documentName)];
    }

    /**
     * @param  array<string, mixed>  $documents
     * @return array{url: string|null, capture_le: string|null}
     */
    private function kycSelfieBlock(array $documents): array
    {
        return [
            'url' => $this->kycSelfieUrl($documents),
            'capture_le' => $this->kycSelfieCapturedAt(),
        ];
    }

    /**
     * @param  array<string, mixed>  $documents
     */
    private function kycSelfieUrl(array $documents): ?string
    {
        return $this->documentUrl($documents, 'selfie');
    }

    private function kycSelfieCapturedAt(): ?string
    {
        return $this->nullableIsoString(null);
    }

    private function nullableIsoString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function asStringKeyedArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
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
     * @param  array<string, mixed>  $ocr
     */
    private function hasExtractedIdentityInfo(array $kyc, array $ocr): bool
    {
        foreach (['numero_piece', 'document_number', 'nom', 'name'] as $key) {
            if (($ocr[$key] ?? '') !== '' || ($kyc[$key] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Checklist "liveness effectué" means Face API confirmed live capture (status 0),
     * not merely that a liveness field was stored (`1` = not confirmed, `skipped` = not run).
     */
    private function isLivenessConfirmed(mixed $liveness): bool
    {
        if ($liveness === null || $liveness === '') {
            return false;
        }

        return (string) $liveness === self::LIVENESS_CONFIRMED;
    }

    private function hasSimilarityScore(mixed $similarity): bool
    {
        return $similarity !== null && $similarity !== '';
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
