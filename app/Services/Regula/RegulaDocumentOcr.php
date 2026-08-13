<?php

declare(strict_types=1);

namespace App\Services\Regula;

/**
 * Pulls document name + text fields from a Document Reader `/api/process` payload.
 * Field keys match the assisted-read form (`nom`, `prenoms`, `numero_piece`, …).
 */
final class RegulaDocumentOcr
{
    private const RESULT_DOCUMENT_TYPE = 9;

    private const RESULT_TEXT = 36;

    /** @var array<int, string> */
    private const TEXT_FIELDS = [
        8 => 'nom',
        9 => 'prenoms',
        12 => 'sexe',
        5 => 'date_naissance',
        3 => 'date_expiration',
        2 => 'numero_piece',
        11 => 'nationalite',
        6 => 'ville_naissance',
        38 => 'pays_naissance',
    ];

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{document_name: ?string, ocr: array<string, string>}
     */
    public function summarize(?array $payload): array
    {
        $containers = $this->containers($payload);

        return [
            'document_name' => $this->extractDocumentName($containers),
            'ocr' => $this->extractFields($containers),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return list<array<string, mixed>>
     */
    private function containers(?array $payload): array
    {
        $list = $payload['ContainerList']['List'] ?? null;
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  list<array<string, mixed>>  $containers
     */
    private function extractDocumentName(array $containers): ?string
    {
        $container = $this->firstOfType($containers, self::RESULT_DOCUMENT_TYPE);
        $name = is_array($container) ? ($container['DocumentName'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param  list<array<string, mixed>>  $containers
     * @return array<string, string>
     */
    private function extractFields(array $containers): array
    {
        $text = $this->firstOfType($containers, self::RESULT_TEXT);
        $textBlock = is_array($text) ? ($text['Text'] ?? null) : null;
        $list = is_array($textBlock) ? ($textBlock['fieldList'] ?? null) : null;
        if (! is_array($list)) {
            return [];
        }

        $fields = [];
        foreach ($list as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = self::TEXT_FIELDS[(int) ($field['fieldType'] ?? -1)] ?? null;
            $value = $field['value'] ?? null;
            if ($key === null || ! is_string($value) || trim($value) === '') {
                continue;
            }

            $normalized = match ($key) {
                'date_naissance', 'date_expiration' => $this->toIsoDate($value),
                default => trim($value),
            };

            if ($normalized !== '') {
                $fields[$key] = $normalized;
            }
        }

        return $fields;
    }

    /**
     * @param  list<array<string, mixed>>  $containers
     * @return array<string, mixed>|null
     */
    private function firstOfType(array $containers, int $resultType): ?array
    {
        foreach ($containers as $container) {
            if ((int) ($container['result_type'] ?? -1) === $resultType) {
                return $container;
            }
        }

        return null;
    }

    private function toIsoDate(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches) === 1) {
            return $matches[3].'-'.$matches[2].'-'.$matches[1];
        }

        return $value;
    }
}
