<?php

declare(strict_types=1);

namespace Tests\Feature\Query;

use App\Enums\ActivityLogAction;
use App\Models\PsceqClient;
use App\Services\ActivityLog\ActivityLogService;
use App\Services\Psceq\PsceqClientAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PsceqClientHistoriqueQueryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function historique_filters_on_the_dedicated_psceq_client_column(): void
    {
        $client = PsceqClient::query()->create([
            'name' => 'Client A',
            'key_prefix' => 'psceq_histclia',
            'key_hash' => 'hash-a',
        ]);
        $other = PsceqClient::query()->create([
            'name' => 'Client B',
            'key_prefix' => 'psceq_histclib',
            'key_hash' => 'hash-b',
        ]);

        $logs = app(ActivityLogService::class);
        $logs->record(
            ActivityLogAction::PsceqClientCree,
            'created A',
            null,
            null,
            ['psceq_client_id' => $client->id],
        );
        $logs->record(
            ActivityLogAction::PsceqClientCree,
            'created B',
            null,
            null,
            ['psceq_client_id' => $other->id],
        );

        $this->assertDatabaseHas('activity_logs', [
            'description' => 'created A',
            'psceq_client_id' => $client->id,
        ]);

        $rows = app(PsceqClientAdminService::class)->historique($client->id);

        $this->assertCount(1, $rows);
        $this->assertSame('created A', $rows->first()?->description);
    }
}
