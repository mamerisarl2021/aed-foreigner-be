<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Filters for GET /audits — built from a validated Form Request payload.
 * `date_from` and `date_to` are independent (single-sided bounds are applied).
 */
final readonly class AuditLogListFilters
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            event: isset($validated['event']) ? (string) $validated['event'] : null,
            userId: isset($validated['user_id']) ? (string) $validated['user_id'] : null,
            auditableType: isset($validated['auditable_type']) ? (string) $validated['auditable_type'] : null,
            auditableId: isset($validated['auditable_id']) ? (string) $validated['auditable_id'] : null,
            ipAddress: isset($validated['ip_address']) ? (string) $validated['ip_address'] : null,
            dateFrom: isset($validated['date_from']) ? (string) $validated['date_from'] : null,
            dateTo: isset($validated['date_to']) ? (string) $validated['date_to'] : null,
            perPage: min(max((int) ($validated['per_page'] ?? 15), 1), 100),
        );
    }

    public function __construct(
        public ?string $event,
        public ?string $userId,
        public ?string $auditableType,
        public ?string $auditableId,
        public ?string $ipAddress,
        public ?string $dateFrom,
        public ?string $dateTo,
        public int $perPage,
    ) {}
}
