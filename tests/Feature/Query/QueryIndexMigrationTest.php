<?php

declare(strict_types=1);

namespace Tests\Feature\Query;

use App\Models\ActivityLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class QueryIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function enrollment_identity_and_audit_query_indexes_exist(): void
    {
        $enrollment = $this->indexNames('enrollment_requests');
        $this->assertContains('enrollment_requests_status_type_created_idx', $enrollment);
        $this->assertContains('enrollment_requests_submitter_type_status_idx', $enrollment);
        $this->assertContains('enrollment_requests_status_sla_deadline_idx', $enrollment);
        $this->assertContains('enrollment_requests_status_agent_avis_idx', $enrollment);
        $this->assertContains('enrollment_requests_email_idx', $enrollment);

        $this->assertContains('identities_user_type_status_idx', $this->indexNames('identities'));
        $this->assertContains('audits_created_at_idx', $this->indexNames('audits'));
        $this->assertContains('activity_logs_psceq_client_id_idx', $this->indexNames('activity_logs'));
        $this->assertTrue(Schema::hasColumn('password_resets', 'id'));

        $foreign = collect(Schema::getForeignKeys('activity_logs'))->first(
            static fn (array $fk): bool => in_array('psceq_client_id', $fk['columns'] ?? [], true)
        );
        $this->assertNotNull($foreign);
        $this->assertSame('psceq_clients', $foreign['foreign_table'] ?? null);
        $this->assertSame(['id'], $foreign['foreign_columns'] ?? null);
        $this->assertSame('set null', strtolower((string) ($foreign['on_delete'] ?? '')));
    }

    #[Test]
    public function activity_logs_reject_unknown_psceq_client_ids(): void
    {
        $this->expectException(QueryException::class);

        ActivityLog::query()->create([
            'action_code' => 'CONSULTATION PSCEQ',
            'description' => 'orphan psceq client',
            'psceq_client_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        return array_values(array_filter(array_map(
            static fn (array $index): string => (string) ($index['name'] ?? ''),
            Schema::getIndexes($table),
        )));
    }
}
