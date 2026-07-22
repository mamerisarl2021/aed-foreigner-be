<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\NpiAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NpiAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocates_sequential_foreign_npi_values(): void
    {
        User::factory()->create(['npi' => 'F-00000074']);

        $this->assertSame('F-00000075', NpiAllocator::nextForeignerNpi());
    }

    public function test_starts_at_one_when_no_existing_npi(): void
    {
        $this->assertSame('F-00000001', NpiAllocator::nextForeignerNpi());
    }
}
