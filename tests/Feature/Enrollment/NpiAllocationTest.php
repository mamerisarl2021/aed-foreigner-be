<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Models\User;
use App\Support\NpiAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class NpiAllocationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function first_allocation_starts_the_ten_digit_range(): void
    {
        $this->assertSame('1000000001', NpiAllocator::nextForeignerNpi());
    }

    #[Test]
    public function allocation_continues_from_the_highest_npi_in_range(): void
    {
        User::factory()->create(['npi' => '1000000042']);

        $this->assertSame('1000000043', NpiAllocator::nextForeignerNpi());
    }

    #[Test]
    public function npis_outside_the_foreigner_range_do_not_shift_the_sequence(): void
    {
        User::factory()->create(['npi' => '199999999']);
        User::factory()->create(['npi' => '1234567890123']);
        User::factory()->create(['npi' => 'F-000000001']);

        $this->assertSame('1000000001', NpiAllocator::nextForeignerNpi());
    }
}
