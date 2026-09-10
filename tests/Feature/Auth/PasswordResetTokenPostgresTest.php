<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\PasswordResetToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PasswordResetTokenPostgresTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function password_resets_has_a_uuid_primary_key(): void
    {
        $this->assertTrue(Schema::hasColumn('password_resets', 'id'));
        $this->assertTrue(
            collect(Schema::getIndexes('password_resets'))
                ->contains(static fn (array $index): bool => ($index['primary'] ?? false) === true)
        );
    }

    #[Test]
    public function update_or_create_persists_without_returning_a_missing_id(): void
    {
        $token = PasswordResetToken::updateOrCreate(
            ['npi' => '1234567890123', 'type' => 'finalisation'],
            ['token' => hash('sha256', 'invite'), 'email' => 'invite@example.com', 'created_at' => now()],
        );

        $this->assertNotEmpty($token->id);
        $this->assertDatabaseHas('password_resets', [
            'npi' => '1234567890123',
            'type' => 'finalisation',
        ]);

        PasswordResetToken::updateOrCreate(
            ['npi' => '1234567890123', 'type' => 'finalisation'],
            ['token' => hash('sha256', 'rotated'), 'created_at' => now()],
        );

        $this->assertSame(1, PasswordResetToken::query()->where('npi', '1234567890123')->count());
    }
}
