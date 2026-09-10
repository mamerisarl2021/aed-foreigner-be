<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\DataTransferObjects\AuditLogListFilters;
use App\Models\User;
use App\Services\Audit\AuditLogQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuditLogDateFilterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function date_from_and_date_to_apply_independently(): void
    {
        $user = User::factory()->create(['email' => 'audit-dates@example.com']);

        $old = $this->insertAudit($user->id, 'old-event');
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $recent = $this->insertAudit($user->id, 'recent-event');
        $recent->forceFill(['created_at' => now()->subHour()])->save();

        $service = app(AuditLogQueryService::class);

        $fromOnly = $service->list(AuditLogListFilters::fromValidated([
            'date_from' => now()->subDays(2)->toDateTimeString(),
        ]));
        $fromIds = $fromOnly->getCollection()->pluck('id')->all();
        $this->assertContains($recent->id, $fromIds);
        $this->assertNotContains($old->id, $fromIds);

        $toOnly = $service->list(AuditLogListFilters::fromValidated([
            'date_to' => now()->subDays(5)->toDateTimeString(),
        ]));
        $toIds = $toOnly->getCollection()->pluck('id')->all();
        $this->assertContains($old->id, $toIds);
        $this->assertNotContains($recent->id, $toIds);
    }

    private function insertAudit(string $userId, string $event): Audit
    {
        return Audit::query()->create([
            'user_type' => User::class,
            'user_id' => $userId,
            'event' => $event,
            'auditable_type' => User::class,
            'auditable_id' => $userId,
            'old_values' => [],
            'new_values' => [],
            'url' => 'http://localhost/audits',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);
    }
}
