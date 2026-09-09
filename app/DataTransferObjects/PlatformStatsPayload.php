<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use Illuminate\Support\Collection;

final readonly class PlatformStatsPayload
{
    /**
     * @param  array<string, int>  $stats
     * @param  array<string, int>  $roles
     * @param  array<string, int>  $enrollmentByStatus
     */
    public function __construct(
        public array $stats,
        public array $roles,
        public array $enrollmentByStatus,
    ) {}

    public static function from(mixed $resource): self
    {
        if ($resource instanceof self) {
            return $resource;
        }

        return self::fromArray(is_array($resource) ? $resource : []);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            stats: self::intMap($data['stats'] ?? []),
            roles: self::intMap($data['roles'] ?? []),
            enrollmentByStatus: self::intMap($data['enrollment_by_status'] ?? []),
        );
    }

    /**
     * @return array{stats: array<string, int>, roles: array<string, int>, enrollment_by_status: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'stats' => $this->stats,
            'roles' => $this->roles,
            'enrollment_by_status' => $this->enrollmentByStatus,
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function intMap(mixed $value): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $count) {
            $map[(string) $key] = (int) $count;
        }

        return $map;
    }
}
