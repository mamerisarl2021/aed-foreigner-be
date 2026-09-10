<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Filters for GET /admin/activity-logs — built from a validated Form Request payload.
 */
final readonly class ActivityLogListFilters
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            q: isset($validated['q']) ? (string) $validated['q'] : null,
            action: isset($validated['action']) ? (string) $validated['action'] : null,
            from: isset($validated['from']) ? (string) $validated['from'] : null,
            to: isset($validated['to']) ? (string) $validated['to'] : null,
            perPage: min(max((int) ($validated['per_page'] ?? 20), 1), 100),
        );
    }

    public function __construct(
        public ?string $q,
        public ?string $action,
        public ?string $from,
        public ?string $to,
        public int $perPage,
    ) {}
}
