<?php

declare(strict_types=1);

namespace Tests\Unit\Rules\Enrollment;

use App\Rules\Enrollment\Iso8601Instant;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Iso8601InstantTest extends TestCase
{
    #[Test]
    public function it_accepts_a_recent_iso8601_instant(): void
    {
        $this->freezeTime();

        $validator = Validator::make(
            ['capture_le' => now()->subMinutes(2)->toIso8601String()],
            ['capture_le' => ['nullable', 'string', new Iso8601Instant]],
        );

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_rejects_date_only_stale_future_and_garbage_values(): void
    {
        $this->freezeTime();

        foreach ([
            'not-a-date',
            '2026-03-06',
            now()->subHours(2)->toIso8601String(),
            now()->addDay()->toIso8601String(),
        ] as $value) {
            $validator = Validator::make(
                ['capture_le' => $value],
                ['capture_le' => ['nullable', 'string', new Iso8601Instant]],
            );

            $this->assertTrue($validator->fails(), 'expected failure for '.$value);
        }
    }
}
