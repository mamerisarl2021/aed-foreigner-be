<?php

declare(strict_types=1);

namespace App\Services\Regula;

use Illuminate\Support\Facades\Log;

/**
 * Orchestrates Document Reader + Face API for KYC identity analysis.
 * Does not call the obsolete custom `/api/v1/identity/analyze` façade.
 */
final class HttpRegulaService implements RegulaService
{
    public function __construct(
        private readonly DocumentReaderClient $documentReader,
        private readonly FaceApiClient $faceApi,
    ) {}

    /**
     * @param  array<string, mixed>  $files  Local paths keyed by slot (selfie, recto, verso)
     * @param  array<string, mixed>  $data  Optional keys: liveness / liveness_transaction_id
     * @return array<string, mixed>
     */
    public function analyzeIdentity(array $files, array $data): array
    {
        $selfie = $this->path($files, 'selfie');
        $recto = $this->path($files, 'recto');
        $verso = $this->path($files, 'verso');

        if ($selfie === null || $recto === null) {
            return $this->failure('selfie_and_recto_required');
        }

        if ((string) config('services.regula.document_url') === '' || (string) config('services.regula.face_url') === '') {
            Log::error('Regula DOCUMENT/FACE URLs are not configured. KYC analysis fails closed.');

            return $this->failure('regula_not_configured');
        }

        $read = $this->readDocumentPages($recto, $verso);
        if ($read['failure'] !== null) {
            return $read['failure'];
        }

        $documentSummary = $read['summary'];
        $portraitB64 = $this->extractPortraitBase64($read['payload']);
        $matchImages = [
            [
                'type' => FaceApiClient::IMAGE_LIVE,
                'path' => $selfie,
                'index' => 0,
            ],
            $portraitB64 !== null
                ? [
                    'type' => FaceApiClient::IMAGE_DOCUMENT_PRINTED,
                    'data' => $portraitB64,
                    'index' => 1,
                ]
                : [
                    'type' => FaceApiClient::IMAGE_DOCUMENT_PRINTED,
                    'path' => $recto,
                    'index' => 1,
                ],
        ];

        $match = $this->faceApi->match($matchImages);
        if (! $match['ok']) {
            return $this->identityKo($match['error'] ?? 'face_match_failed', [
                'document' => $documentSummary,
                'face' => $match['payload'],
            ]);
        }

        $similarity = $this->maxSimilarity($match['payload']);
        $faceCode = (int) ($match['payload']['code'] ?? -1);
        $threshold = (float) config('services.regula.match_threshold', 0.75);

        $liveness = $this->evaluateLiveness(
            $this->livenessTransactionId($data),
            $similarity,
            $documentSummary,
            $match['payload'],
        );
        if ($liveness['failure'] !== null) {
            return $liveness['failure'];
        }

        $faceOk = $faceCode === 0 && $similarity !== null && $similarity >= $threshold;
        if (! $faceOk) {
            return $this->identityKo('face_match_below_threshold', [
                'face_match' => false,
                'doc_validity' => true,
                'threshold' => $threshold,
                'document' => $documentSummary,
                'face' => $this->summarizeMatch($match['payload'], $similarity),
                'liveness' => $liveness['payload'],
                'used_document_portrait' => $portraitB64 !== null,
            ], $this->riskFromSimilarity($similarity), $similarity, $liveness['status']);
        }

        return [
            'status' => 'OK',
            'risk_score' => $this->riskFromSimilarity($similarity),
            'similarity' => $similarity,
            'liveness' => $liveness['status'] !== null ? (string) $liveness['status'] : 'skipped',
            'details' => [
                'face_match' => true,
                'doc_validity' => true,
                'threshold' => $threshold,
                'document' => $documentSummary,
                'face' => $this->summarizeMatch($match['payload'], $similarity),
                'liveness' => $liveness['payload'],
                'used_document_portrait' => $portraitB64 !== null,
            ],
        ];
    }

    /**
     * Document-only analysis for the personne morale step 2: no selfie, so no face match
     * and no liveness — the identity document alone is read and checked.
     *
     * @param  array<string, mixed>  $files  Local paths keyed by slot (recto, verso)
     * @param  array<string, mixed>  $data  Unused: no face transaction is involved
     * @return array<string, mixed>
     */
    public function analyzeDocument(array $files, array $data): array
    {
        unset($data);

        $recto = $this->path($files, 'recto');
        $verso = $this->path($files, 'verso');

        if ($recto === null) {
            return $this->failure('recto_required');
        }

        if ((string) config('services.regula.document_url') === '') {
            Log::error('Regula DOCUMENT URL is not configured. Document analysis fails closed.');

            return $this->failure('regula_not_configured');
        }

        $read = $this->readDocumentPages($recto, $verso);
        if ($read['failure'] !== null) {
            return $read['failure'];
        }

        return [
            'status' => 'OK',
            'risk_score' => null,
            'similarity' => null,
            'liveness' => null,
            'details' => [
                'doc_validity' => true,
                'document_only' => true,
                'document' => $read['summary'],
            ],
        ];
    }

    /**
     * Document Reader pass shared by the full and the document-only analysis.
     * `failure` is the ready-to-return KO payload; it is null when the document is usable.
     *
     * @return array{summary: array<string, mixed>, payload: array<string, mixed>|null, failure: array<string, mixed>|null}
     */
    private function readDocumentPages(string $recto, ?string $verso): array
    {
        $document = $this->documentReader->process(array_values(array_filter([$recto, $verso])));
        $summary = $this->summarizeDocument($document['payload']);

        if (! $document['ok']) {
            return [
                'summary' => $summary,
                'payload' => null,
                'failure' => $this->failure($document['error'] ?? 'document_failed', $summary),
            ];
        }

        if (($summary['overall_status'] ?? null) === 2) {
            return [
                'summary' => $summary,
                'payload' => $document['payload'],
                'failure' => $this->failure('document_overall_status_error', $summary),
            ];
        }

        return ['summary' => $summary, 'payload' => $document['payload'], 'failure' => null];
    }

    /**
     * @param  array<string, mixed>|null  $documentSummary
     * @return array<string, mixed>
     */
    private function failure(string $error, ?array $documentSummary = null): array
    {
        $details = ['error' => $error];
        if ($documentSummary !== null) {
            $details['document'] = $documentSummary;
        }

        return [
            'status' => 'KO',
            'risk_score' => null,
            'similarity' => null,
            'liveness' => null,
            'details' => $details,
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function identityKo(
        string $error,
        array $details,
        ?int $riskScore = null,
        ?float $similarity = null,
        mixed $liveness = null,
    ): array {
        $details['error'] = $error;

        return [
            'status' => 'KO',
            'risk_score' => $riskScore,
            'similarity' => $similarity,
            'liveness' => $liveness !== null ? (string) $liveness : null,
            'details' => $details,
        ];
    }

    /**
     * @param  array<string, mixed>  $documentSummary
     * @param  array<string, mixed>|null  $matchPayload
     * @return array{status: mixed, payload: mixed, failure: array<string, mixed>|null}
     */
    private function evaluateLiveness(
        ?string $transactionId,
        ?float $similarity,
        array $documentSummary,
        ?array $matchPayload,
    ): array {
        if ($transactionId === null) {
            return ['status' => null, 'payload' => null, 'failure' => null];
        }

        $liveness = $this->faceApi->getLiveness($transactionId);
        if (! $liveness['ok']) {
            return [
                'status' => null,
                'payload' => null,
                'failure' => $this->identityKo($liveness['error'] ?? 'liveness_failed', [
                    'document' => $documentSummary,
                    'face' => $this->summarizeMatch($matchPayload, $similarity),
                ], null, $similarity),
            ];
        }

        $payload = $liveness['payload'];
        $status = is_array($payload) ? ($payload['status'] ?? null) : null;
        if ((int) $status !== 0) {
            return [
                'status' => $status,
                'payload' => $payload,
                'failure' => $this->identityKo('liveness_not_confirmed', [
                    'face_match' => false,
                    'doc_validity' => ($documentSummary['overall_status'] ?? 1) !== 2,
                    'document' => $documentSummary,
                    'face' => $this->summarizeMatch($matchPayload, $similarity),
                    'liveness' => $payload,
                ], $this->riskFromSimilarity($similarity), $similarity, $status),
            ];
        }

        return ['status' => $status, 'payload' => $payload, 'failure' => null];
    }

    /**
     * @param  array<string, mixed>  $files
     */
    private function path(array $files, string $slot): ?string
    {
        $path = $files[$slot] ?? null;

        return is_string($path) && is_file($path) ? $path : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function livenessTransactionId(array $data): ?string
    {
        foreach (['liveness_transaction_id', 'liveness'] as $key) {
            $value = $data[$key] ?? null;
            if (! is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            // Ignore legacy numeric client scores (e.g. "0.92").
            if (is_numeric($value)) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function summarizeDocument(?array $payload): array
    {
        if ($payload === null) {
            return [];
        }

        $ocr = (new RegulaDocumentOcr)->summarize($payload);

        return [
            'overall_status' => $payload['overallStatus'] ?? $payload['Status'] ?? null,
            'document_name' => $ocr['document_name'],
            'ocr' => $ocr['ocr'],
            'transaction_info' => $payload['TransactionInfo'] ?? null,
            'chip_page' => $payload['ChipPage'] ?? null,
            'has_container_list' => isset($payload['ContainerList']),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function summarizeMatch(?array $payload, ?float $similarity): array
    {
        return [
            'code' => $payload['code'] ?? null,
            'similarity' => $similarity,
            'results' => $payload['results'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function maxSimilarity(?array $payload): ?float
    {
        if ($payload === null) {
            return null;
        }

        $results = $payload['results'] ?? null;
        if (! is_array($results) || $results === []) {
            return null;
        }

        $max = null;
        foreach ($results as $result) {
            if (! is_array($result) || ! isset($result['similarity'])) {
                continue;
            }
            $score = (float) $result['similarity'];
            $max = $max === null ? $score : max($max, $score);
        }

        return $max;
    }

    private function riskFromSimilarity(?float $similarity): int
    {
        if ($similarity === null) {
            return 10;
        }

        return (int) max(0, min(10, (int) round((1 - $similarity) * 10)));
    }

    /**
     * Best-effort portrait crop from Document Reader graphics containers.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function extractPortraitBase64(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $containers = $payload['ContainerList']['List'] ?? null;
        if (! is_array($containers)) {
            return null;
        }

        foreach ($containers as $container) {
            if (! is_array($container)) {
                continue;
            }
            $found = $this->portraitFromContainer($container);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $container
     */
    private function portraitFromContainer(array $container): ?string
    {
        $images = $container['Images']['fieldList']
            ?? $container['Images']['FieldList']
            ?? $container['Images']
            ?? null;

        if (! is_array($images)) {
            return null;
        }

        return $this->portraitFromImageList($images);
    }

    /**
     * @param  array<mixed>  $images
     */
    private function portraitFromImageList(array $images): ?string
    {
        if (array_is_list($images) || isset($images[0])) {
            foreach ($images as $field) {
                if (! is_array($field) || ! $this->isPortraitField($field)) {
                    continue;
                }
                $value = $this->firstImageValue($field);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        foreach ($images as $key => $field) {
            if (! is_array($field)) {
                continue;
            }
            if (is_string($key) && stripos($key, 'portrait') !== false) {
                $value = $this->firstImageValue($field);
                if ($value !== null) {
                    return $value;
                }
            }
            if ($this->isPortraitField($field)) {
                $value = $this->firstImageValue($field);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function isPortraitField(array $field): bool
    {
        $name = (string) ($field['fieldName'] ?? $field['FieldName'] ?? $field['name'] ?? '');
        $type = $field['fieldType'] ?? $field['FieldType'] ?? $field['graphicFieldType'] ?? null;

        // GraphicFieldType.PORTRAIT is commonly 201.
        if ($type === 201 || $type === '201' || $type === 'PORTRAIT') {
            return true;
        }

        return stripos($name, 'portrait') !== false || stripos($name, 'photo') !== false;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function firstImageValue(array $field): ?string
    {
        $candidates = [
            $field['value'] ?? null,
            $field['Value'] ?? null,
            $field['image'] ?? null,
        ];

        $valueList = $field['valueList'] ?? $field['ValueList'] ?? null;
        if (is_array($valueList)) {
            foreach ($valueList as $item) {
                if (is_array($item)) {
                    $candidates[] = $item['value'] ?? $item['Value'] ?? $item['image'] ?? null;
                } elseif (is_string($item)) {
                    $candidates[] = $item;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && strlen($candidate) > 64) {
                return $candidate;
            }
        }

        return null;
    }
}
