<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Filters for GET /admin/enrolled-companies — built from a validated Form Request payload.
 */
final readonly class EnrolledCompanyListFilters
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $orderBy = $validated['order_by'] ?? 'enrolled_at';
        if (! is_string($orderBy) || ! in_array($orderBy, ['enrolled_at', 'legal_name', 'email'], true)) {
            $orderBy = 'enrolled_at';
        }

        return new self(
            q: isset($validated['q']) ? (string) $validated['q'] : null,
            perPage: min(max((int) ($validated['per_page'] ?? 15), 1), 100),
            orderBy: $orderBy,
            orderDir: strtolower((string) ($validated['order_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
        );
    }

    public function __construct(
        public ?string $q,
        public int $perPage,
        public string $orderBy,
        public string $orderDir,
    ) {}
}
