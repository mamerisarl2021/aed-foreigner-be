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
     * @param  array<string, mixed>  $data   Optional keys: liveness / liveness_transaction_id
     * @return array<string, mixed>
     */
    public function analyzeIdentity(array $files, array $data): array
    {
        $selfie = $this->path($files, 'selfie');
        $recto = $this->path($files, 'recto');
        $verso = $this->path($files, 'verso');

        if ($selfie === null || $recto === null) {
            return [
                'status' => 'KO',
                'risk_score' => null,
                'details' => ['error' => 'selfie_and_recto_required'],
            ];
        }

        if ((string) config('services.regula.document_url') === '' || (string) config('services.regula.face_url') === '') {
            Log::error('Regula DOCUMENT/FACE URLs are not configured. KYC analysis fails closed.');

            return [
                'status' => 'KO',
                'risk_score' => null,
                'details' => ['error' => 'regula_not_configured'],
            ];
        }

        $docPages = array_values(array_filter([$recto, $verso]));
        $document = $this->documentReader->process($docPages);
        if (! $document['ok']) {
            return [
                'status' => 'KO',
                'risk_score' => null,
                'details' => [
                    'error' => $document['error'] ?? 'document_failed',
                    'document' => $this->summarizeDocument($document['payload']),
                ],
            ];
        }

        $documentSummary = $this->summarizeDocument($document['payload']);
        if (($documentSummary['overall_status'] ?? null) === 2) {
            return [
                'status' => 'KO',
                'risk_score' => null,
                'similarity' => null,
                'liveness' => null,
                'details' => [
                    'error' => 'document_overall_status_error',
                    'document' => $documentSummary,
                ],
            ];
        }

        $portraitB64 = $this->extractPortraitBase64($document['payload']);
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
            return [
                'status' => 'KO',
                'risk_score' => null,
                'similarity' => null,
                'liveness' => null,
                'details' => [
                    'error' => $match['error'] ?? 'face_match_failed',
                    'document' => $documentSummary,
                    'face' => $match['payload'],
                ],
            ];
        }

        $similarity = $this->maxSimilarity($match['payload']);
        $faceCode = (int) ($match['payload']['code'] ?? -1);
        $threshold = (float) config('services.regula.match_threshold', 0.75);

        $livenessStatus = null;
        $livenessPayload = null;
        $transactionId = $this->livenessTransactionId($data);
        if ($transactionId !== null) {
            $liveness = $this->faceApi->getLiveness($transactionId);
            if (! $liveness['ok']) {
                return [
                    'status' => 'KO',
                    'risk_score' => null,
                    'similarity' => $similarity,
                    'liveness' => null,
                    'details' => [
                        'error' => $liveness['error'] ?? 'liveness_failed',
                        'document' => $documentSummary,
                        'face' => $this->summarizeMatch($match['payload'], $similarity),
                    ],
                ];
            }
            $livenessPayload = $liveness['payload'];
            $livenessStatus = $livenessPayload['status'] ?? null;
            // Face API: status 0 = confirmed liveness, 1 = not confirmed.
            if ((int) $livenessStatus !== 0) {
                return [
                    'status' => 'KO',
                    'risk_score' => $this->riskFromSimilarity($similarity),
                    'similarity' => $similarity,
                    'liveness' => (string) $livenessStatus,
                    'details' => [
                        'error' => 'liveness_not_confirmed',
                        'face_match' => false,
                        'doc_validity' => ($documentSummary['overall_status'] ?? 1) !== 2,
                        'document' => $documentSummary,
                        'face' => $this->summarizeMatch($match['payload'], $similarity),
                        'liveness' => $livenessPayload,
                    ],
                ];
            }
        }

        $faceOk = $faceCode === 0 && $similarity !== null && $similarity >= $threshold;
        if (! $faceOk) {
            return [
                'status' => 'KO',
                'risk_score' => $this->riskFromSimilarity($similarity),
                'similarity' => $similarity,
                'liveness' => $livenessStatus !== null ? (string) $livenessStatus : null,
                'details' => [
                    'error' => 'face_match_below_threshold',
                    'face_match' => false,
                    'doc_validity' => true,
                    'threshold' => $threshold,
                    'document' => $documentSummary,
                    'face' => $this->summarizeMatch($match['payload'], $similarity),
                    'liveness' => $livenessPayload,
                    'used_document_portrait' => $portraitB64 !== null,
                ],
            ];
        }

        return [
            'status' => 'OK',
            'risk_score' => $this->riskFromSimilarity($similarity),
            'similarity' => $similarity,
            'liveness' => $livenessStatus !== null ? (string) $livenessStatus : 'skipped',
            'details' => [
                'face_match' => true,
                'doc_validity' => true,
                'threshold' => $threshold,
                'document' => $documentSummary,
                'face' => $this->summarizeMatch($match['payload'], $similarity),
                'liveness' => $livenessPayload,
                'used_document_portrait' => $portraitB64 !== null,
            ],
        ];
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

        return [
            'overall_status' => $payload['overallStatus'] ?? $payload['Status'] ?? null,
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

            $images = $container['Images']['fieldList']
                ?? $container['Images']['FieldList']
                ?? $container['Images']
                ?? null;

            if (! is_array($images)) {
                continue;
            }

            // fieldList style
            if (array_is_list($images) || isset($images[0])) {
                foreach ($images as $field) {
                    if (! is_array($field)) {
                        continue;
                    }
                    if ($this->isPortraitField($field)) {
                        $value = $this->firstImageValue($field);
                        if ($value !== null) {
                            return $value;
                        }
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
