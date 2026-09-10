<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\DataTransferObjects\DocumentReadInput;
use App\Enums\ActivityLogAction;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\Regula\DocumentReaderClient;
use App\Services\ServiceResult;

/**
 * Pré-lecture assistée de la pièce d'identité, avant la porte KYC.
 *
 * Sert à pré-remplir le formulaire d'identité et à signaler une photo
 * inexploitable pendant que l'usager est encore sur l'écran de capture. Le
 * contrôle qui fait foi reste `POST /kyc/verify`, qui rejoue la lecture avec le
 * même scénario Regula (`services.regula.document_scenario`).
 *
 * Cet endpoint existe pour que le navigateur n'ait pas à joindre le serveur
 * Regula directement : sans lui, il faudrait ouvrir le CORS du serveur Regula
 * et laisser n'importe quel visiteur consommer des transactions sous licence.
 *
 * Aucune donnée n'est persistée : la lecture est éphémère et non contraignante.
 */
final class DocumentReadService
{
    /** Types de conteneurs Regula (`ContainerList.List[].result_type`). */
    private const RESULT_DOCUMENT_TYPE = 9;

    private const RESULT_IMAGE_QUALITY = 30;

    private const RESULT_STATUS = 33;

    private const RESULT_TEXT = 36;

    private const RESULT_IMAGES = 37;

    /** `CheckResult` — seul `ERROR` signale un contrôle réellement en échec. */
    private const CHECK_ERROR = 0;

    private const CHECK_OK = 1;

    /** `GraphicFieldType::PORTRAIT`. */
    private const GRAPHIC_PORTRAIT = 201;

    /** `TextFieldType` → clé du formulaire d'identité côté front. */
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
     * `ImageQualityCheckType` → code rendu au client.
     *
     * On rend des codes et non des phrases : la formulation et la langue
     * appartiennent au front, qui a déjà son catalogue de traductions. C'est
     * aussi la convention de `/kyc/verify`, qui rend `details.error`.
     */
    private const QUALITY_CODES = [
        0 => 'IMAGE_GLARES',
        1 => 'IMAGE_FOCUS',
        2 => 'IMAGE_RESOLUTION',
        3 => 'IMAGE_COLORNESS',
        4 => 'PERSPECTIVE',
        5 => 'BOUNDS',
        7 => 'PORTRAIT',
        9 => 'BRIGHTNESS',
        10 => 'OCCLUSION',
    ];

    public function __construct(
        private readonly DocumentReaderClient $documentReader,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function read(DocumentReadInput $input): ServiceResult
    {
        $pages = array_values(array_filter([
            $input->recto->getPathname(),
            $input->verso?->getPathname(),
        ]));

        if ($pages === []) {
            return ServiceResult::fail('Le recto de la pièce est obligatoire.', null, 422);
        }

        $result = $this->documentReader->process($pages);

        if (! $result['ok']) {
            $this->activityLog->record(
                ActivityLogAction::DocumentLu,
                'Lecture assistée de pièce impossible.',
                null,
                null,
                ['ok' => false, 'error' => $result['error'] ?? null],
            );

            return ServiceResult::ok('Lecture de la pièce impossible.', [
                'ok' => false,
                'document_name' => null,
                'fields' => new \stdClass,
                'quality_issues' => [$this->unreadableCode((string) ($result['error'] ?? ''))],
                'portrait' => null,
            ]);
        }

        $summary = $this->summarize($result['payload']);
        $this->activityLog->record(
            ActivityLogAction::DocumentLu,
            sprintf('Lecture assistée de pièce (%s).', $summary['document_name'] ?? 'document'),
            null,
            null,
            [
                'ok' => true,
                'document_name' => $summary['document_name'] ?? null,
                'quality_issues' => $summary['quality_issues'] ?? [],
            ],
        );

        return ServiceResult::ok('Pièce lue.', $summary);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function summarize(?array $payload): array
    {
        $containers = $this->containers($payload);
        $fields = $this->extractFields($containers);

        return [
            'ok' => $this->overallStatus($containers) === self::CHECK_OK,
            'document_name' => $this->extractDocumentName($containers),
            'fields' => $fields === [] ? new \stdClass : $fields,
            'quality_issues' => $this->extractQualityIssues($containers),
            'portrait' => $this->extractPortrait($containers),
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

    /**
     * @param  list<array<string, mixed>>  $containers
     */
    private function overallStatus(array $containers): ?int
    {
        $status = $this->firstOfType($containers, self::RESULT_STATUS)['Status']['overallStatus'] ?? null;

        return is_numeric($status) ? (int) $status : null;
    }

    /**
     * @param  list<array<string, mixed>>  $containers
     */
    private function extractDocumentName(array $containers): ?string
    {
        $name = $this->firstOfType($containers, self::RESULT_DOCUMENT_TYPE)['DocumentName'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Ne retient que les champs attendus par le formulaire, normalisés.
     *
     * @param  list<array<string, mixed>>  $containers
     * @return array<string, string>
     */
    private function extractFields(array $containers): array
    {
        $list = $this->firstOfType($containers, self::RESULT_TEXT)['Text']['fieldList'] ?? null;
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
                'sexe' => $this->normalizeSexe($value),
                'date_naissance', 'date_expiration' => $this->toIsoDate($value),
                default => trim($value),
            };

            if ($normalized !== null) {
                $fields[$key] = $normalized;
            }
        }

        return $fields;
    }

    /**
     * @param  list<array<string, mixed>>  $containers
     * @return list<string>
     */
    private function extractQualityIssues(array $containers): array
    {
        $checks = $this->firstOfType($containers, self::RESULT_IMAGE_QUALITY)['ImageQualityCheckList']['List'] ?? null;
        if (! is_array($checks)) {
            return [];
        }

        $issues = [];
        foreach ($checks as $check) {
            if (! is_array($check) || (int) ($check['result'] ?? -1) !== self::CHECK_ERROR) {
                continue;
            }
            $issues[] = self::QUALITY_CODES[(int) ($check['type'] ?? -1)] ?? 'QUALITY_UNKNOWN';
        }

        return array_values(array_unique($issues));
    }

    /**
     * Portrait découpé dans la pièce, en base64 — le front s'en sert comme
     * aperçu ; la comparaison faciale, elle, est refaite par `/kyc/verify`.
     *
     * @param  list<array<string, mixed>>  $containers
     */
    private function extractPortrait(array $containers): ?string
    {
        $list = $this->firstOfType($containers, self::RESULT_IMAGES)['Images']['fieldList'] ?? null;
        if (! is_array($list)) {
            return null;
        }

        foreach ($list as $field) {
            $image = $this->portraitFromField($field);
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    private function portraitFromField(mixed $field): ?string
    {
        if (! is_array($field) || (int) ($field['fieldType'] ?? -1) !== self::GRAPHIC_PORTRAIT) {
            return null;
        }

        $values = $field['valueList'] ?? null;
        if (! is_array($values)) {
            return null;
        }

        foreach ($values as $value) {
            $image = is_array($value) ? ($value['value'] ?? null) : null;
            if (is_string($image) && $image !== '') {
                return $image;
            }
        }

        return null;
    }

    /** Regula rend `M`, `F` ou une variante localisée ; on ne garde que M/F. */
    private function normalizeSexe(string $value): ?string
    {
        $first = strtoupper(substr(trim($value), 0, 1));

        return in_array($first, ['M', 'F'], true) ? $first : null;
    }

    /** Les dates MRZ arrivent en `jj/mm/aaaa` ; le front attend de l'ISO. */
    private function toIsoDate(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches) === 1) {
            return $matches[3].'-'.$matches[2].'-'.$matches[1];
        }

        return $value;
    }

    /**
     * Regula répond 4xx quand l'image ne contient aucun document exploitable —
     * l'usager peut y remédier. Tout le reste (réseau, service arrêté) ne
     * dépend pas de lui et se dit autrement.
     */
    private function unreadableCode(string $error): string
    {
        return str_starts_with($error, 'document_http_') ? 'UNREADABLE_DOCUMENT' : 'READER_UNAVAILABLE';
    }
}
