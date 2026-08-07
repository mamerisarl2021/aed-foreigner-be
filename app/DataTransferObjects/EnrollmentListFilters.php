<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\User;

/**
 * Filters for GET /enrolements — built from a validated Form Request payload.
 */
final readonly class EnrollmentListFilters
{
    /**
     * @param  list<string>|null  $statuses  Already-split statut values, or null for role default queue
     */
    public function __construct(
        public ?User $actor,
        public ?array $statuses,
        public ?string $avis,
        public ?string $type,
        public ?string $q,
        public ?string $from,
        public ?string $to,
        public int $perPage,
        public string $orderBy,
        public string $orderDir,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated, ?User $actor): self
    {
        $statutParam = $validated['statut'] ?? $validated['status'] ?? null;
        $statuses = null;
        if ($statutParam !== null) {
            $statuses = is_array($statutParam)
                ? array_values($statutParam)
                : array_values(array_filter(array_map('trim', explode('|', (string) $statutParam))));
        }

        $type = isset($validated['type']) ? strtoupper((string) $validated['type']) : null;

        return new self(
            actor: $actor,
            statuses: $statuses,
            avis: isset($validated['avis']) ? strtoupper((string) $validated['avis']) : null,
            type: $type,
            q: isset($validated['q']) ? (string) $validated['q'] : null,
            from: isset($validated['from']) ? (string) $validated['from'] : null,
            to: isset($validated['to']) ? (string) $validated['to'] : null,
            perPage: min(max((int) ($validated['per_page'] ?? 15), 1), 100),
            orderBy: in_array($validated['order_by'] ?? 'created_at', ['id', 'created_at'], true)
                ? (string) ($validated['order_by'] ?? 'created_at')
                : 'created_at',
            orderDir: strtolower((string) ($validated['order_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
        );
    }
}
