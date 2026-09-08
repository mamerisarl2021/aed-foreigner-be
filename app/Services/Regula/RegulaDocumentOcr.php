<?php

declare(strict_types=1);

namespace App\Services\Regula;

/**
 * Pulls document name + every text field from Document Reader `/api/process`.
 * Known TextFieldType ids get French keys for the review panel; other fields
 * keep a slug of `fieldName`. Assisted form prefill stays a separate whitelist
 * in DocumentReadService.
 */
final class RegulaDocumentOcr
{
    private const RESULT_DOCUMENT_TYPE = 9;

    private const RESULT_TEXT = 36;

    /** @var array<int, string> */
    private const TEXT_FIELDS = [
        1 => 'code_etat_emetteur',
        2 => 'numero_piece',
        3 => 'date_expiration',
        4 => 'date_emission',
        5 => 'date_naissance',
        6 => 'lieu_naissance',
        7 => 'numero_personnel',
        8 => 'nom',
        9 => 'prenoms',
        10 => 'nom_mere',
        11 => 'nationalite',
        12 => 'sexe',
        13 => 'taille',
        17 => 'adresse',
        24 => 'autorite',
        25 => 'nom_complet',
        26 => 'code_nationalite',
        27 => 'numero_passeport',
        37 => 'classe_document',
        38 => 'pays_emission',
        39 => 'lieu_emission',
        51 => 'mrz',
        56 => 'serie_document',
        40 => 'checksum_numero_piece',
        41 => 'checksum_date_naissance',
        42 => 'checksum_date_expiration',
        43 => 'checksum_numero_personnel',
        44 => 'checksum_final',
        45 => 'checksum_numero_passeport',
        48 => 'checksum_nom_complet',
        54 => 'checksum_date_emission',
        55 => 'check_digit_date_emission',
        80 => 'check_digit_numero_piece',
        81 => 'check_digit_date_naissance',
        82 => 'check_digit_date_expiration',
        83 => 'check_digit_numero_personnel',
        84 => 'check_digit_final',
        88 => 'check_digit_nom_complet',
        126 => 'nom_famille',
        129 => 'nom_pere',
        142 => 'numero_cni',
        185 => 'age',
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
        $fields = [];
        foreach ($containers as $container) {
            if ((int) ($container['result_type'] ?? -1) !== self::RESULT_TEXT) {
                continue;
            }

            $textBlock = $container['Text'] ?? null;
            $list = is_array($textBlock) ? ($textBlock['fieldList'] ?? null) : null;
            if (! is_array($list)) {
                continue;
            }

            $this->mergeFieldList(array_values($list), $fields);
        }

        return $fields;
    }

    /**
     * First-wins per key across recto + verso text containers.
     *
     * @param  list<mixed>  $list
     * @param  array<string, string>  $fields
     */
    private function mergeFieldList(array $list, array &$fields): void
    {
        foreach ($list as $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = (int) ($field['fieldType'] ?? $field['FieldType'] ?? -1);
            $fieldName = $this->stringValue($field['fieldName'] ?? $field['FieldName'] ?? null);

            $value = $this->fieldValue($field);
            if ($value === null) {
                continue;
            }

            $key = self::TEXT_FIELDS[$type] ?? $this->slugFieldName($fieldName);
            if ($key === '') {
                $key = 'field_'.$type;
            }

            if (array_key_exists($key, $fields)) {
                continue;
            }

            $normalized = $this->normalizeValue($key, $value);
            if ($normalized !== '') {
                $fields[$key] = $normalized;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function fieldValue(array $field): ?string
    {
        $direct = $this->stringValue($field['value'] ?? $field['Value'] ?? null);
        if ($direct !== null) {
            return $direct;
        }

        $valueList = $field['valueList'] ?? $field['ValueList'] ?? null;
        if (! is_array($valueList)) {
            return null;
        }

        foreach ($valueList as $item) {
            $candidate = is_array($item)
                ? ($item['value'] ?? $item['Value'] ?? null)
                : $item;
            $value = $this->stringValue($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function slugFieldName(?string $fieldName): string
    {
        if ($fieldName === null || $fieldName === '') {
            return '';
        }

        $slug = strtolower($fieldName);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';

        return trim($slug, '_');
    }

    private function normalizeValue(string $key, string $value): string
    {
        if ($key === 'sexe') {
            $first = strtoupper(substr($value, 0, 1));

            return in_array($first, ['M', 'F'], true) ? $first : strtoupper(trim($value));
        }

        if ($key === 'date_naissance' || $key === 'date_expiration' || $key === 'date_emission' || str_starts_with($key, 'date_')) {
            return $this->toIsoDate($value);
        }

        return $value;
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
