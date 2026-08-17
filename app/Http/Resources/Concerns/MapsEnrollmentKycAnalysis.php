<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Models\EnrollmentRequest;

/**
 * Shapes `analyse_kyc` for agent/responsable detail views.
 * `document_identite` is OCR-only (form `kyc_data` is never mixed in).
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
     * Keys that mean `ocr_data` is document OCR, not mock metadata (email, liveness, …).
     *
     * @var list<string>
     */
    private const IDENTITY_OCR_KEYS = [
        'nom',
        'name',
        'prenoms',
        'prenom',
        'first_name',
        'numero_piece',
        'numero_document',
        'document_number',
        'numero_passeport',
        'numero_cni',
        'date_naissance',
        'date_of_birth',
        'nationalite',
        'nationality',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function analyseKycPhysique(EnrollmentRequest $enrollment): array
    {
        return $this->analyseKycFromDocument($enrollment);
    }

    /**
     * Morale KYC is the demandeur's identity document, not company kyc_data.
     *
     * @return array<string, mixed>
     */
    protected function analyseKycMorale(EnrollmentRequest $enrollment): array
    {
        return $this->analyseKycFromDocument($enrollment);
    }

    /**
     * @return array<string, mixed>
     */
    private function analyseKycFromDocument(EnrollmentRequest $enrollment): array
    {
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
            'document_identite' => $this->documentIdentiteFromOcr($ocr, $documentName, $details),
            'selfie' => $this->kycSelfieBlock($enrollment, $documents),
            'etapes' => [
                'document_ajoute' => $this->hasDocumentSlot($documents, 'recto'),
                'informations_extraites' => $this->hasExtractedIdentityInfo($ocr),
                'liveness_effectue' => $this->isLivenessConfirmed($liveness),
                'visage_compare' => $this->hasSimilarityScore($similarity),
            ],
        ];
    }

    /**
     * Review panel: every OCR field, never the enrollment form.
     *
     * @param  array<string, mixed>  $ocr
     * @return array<string, mixed>
     */
    private function documentIdentiteFromOcr(array $ocr, ?string $documentName, mixed $details): array
    {
        [$namePays, $nameType] = $this->splitDocumentName($documentName);

        $pick = function (array $keys) use ($ocr): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $ocr) && $ocr[$key] !== null && $ocr[$key] !== '') {
                    return $ocr[$key];
                }
            }

            return null;
        };

        $identite = [
            'type_piece' => $pick(['type_piece', 'document_type', 'classe_document']) ?? $nameType,
            'pays' => $pick(['pays', 'pays_emission', 'issuing_state', 'etat_emetteur']) ?? $namePays,
            'verifie' => $this->isDocumentVerified($details),
            'numero_document' => $pick(['numero_document', 'numero_piece', 'document_number', 'numero_passeport', 'numero_cni']),
            'nom' => $pick(['nom', 'name']),
            'prenoms' => $pick(['prenoms', 'prenom', 'first_name']),
            'date_naissance' => $pick(['date_naissance', 'date_of_birth']),
            'nationalite' => $pick(['nationalite', 'nationality']),
            'date_expiration' => $pick(['date_expiration', 'expiry_date', 'date_of_expiry']),
            'sexe' => $pick(['sexe', 'sex']),
            'date_emission' => $pick(['date_emission', 'date_of_issue']),
            'lieu_naissance' => $pick(['lieu_naissance', 'ville_naissance', 'place_of_birth']),
            'autorite' => $pick(['autorite', 'authority']),
            'numero_personnel' => $pick(['numero_personnel', 'personal_number']),
            'nom_complet' => $pick(['nom_complet']),
        ];

        $aliases = [
            'type_piece', 'document_type', 'classe_document',
            'pays', 'pays_emission', 'issuing_state', 'etat_emetteur',
            'numero_document', 'numero_piece', 'document_number', 'numero_passeport', 'numero_cni',
            'nom', 'name',
            'prenoms', 'prenom', 'first_name',
            'date_naissance', 'date_of_birth',
            'nationalite', 'nationality',
            'date_expiration', 'expiry_date', 'date_of_expiry',
            'sexe', 'sex',
            'date_emission', 'date_of_issue',
            'lieu_naissance', 'ville_naissance', 'place_of_birth',
            'autorite', 'authority',
            'numero_personnel', 'personal_number',
            'nom_complet',
        ];

        foreach ($ocr as $key => $value) {
            if ($key === '' || array_key_exists($key, $identite) || in_array($key, $aliases, true)) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $identite[$key] = $value;
        }

        return $identite;
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

        if (isset($details['ocr_data']) && is_array($details['ocr_data']) && $this->looksLikeIdentityOcr($details['ocr_data'])) {
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

    /**
     * @param  array<string, mixed>  $bag
     */
    private function looksLikeIdentityOcr(array $bag): bool
    {
        foreach (self::IDENTITY_OCR_KEYS as $key) {
            if (! array_key_exists($key, $bag)) {
                continue;
            }
            $value = $bag[$key];
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
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
    private function kycSelfieBlock(EnrollmentRequest $enrollment, array $documents): array
    {
        return [
            'url' => $this->kycSelfieUrl($documents),
            'capture_le' => $this->kycSelfieCapturedAt($enrollment),
        ];
    }

    /**
     * @param  array<string, mixed>  $documents
     */
    private function kycSelfieUrl(array $documents): ?string
    {
        return $this->documentUrl($documents, 'selfie');
    }

    private function kycSelfieCapturedAt(EnrollmentRequest $enrollment): ?string
    {
        return $enrollment->selfie_captured_at?->toIso8601String();
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
     * @param  array<string, mixed>  $ocr
     */
    private function hasExtractedIdentityInfo(array $ocr): bool
    {
        foreach (['numero_piece', 'numero_document', 'document_number', 'nom', 'name', 'prenoms'] as $key) {
            if (($ocr[$key] ?? '') !== '') {
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
