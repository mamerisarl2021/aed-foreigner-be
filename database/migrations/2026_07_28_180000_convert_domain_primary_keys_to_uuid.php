<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Convert app-domain integer primary keys to UUIDs on databases migrated
 * before the create migrations became UUID-native. Fresh installs already
 * create UUID keys and skip this migration entirely.
 *
 * MySQL only (like the enum ALTERs in this repo). Dev databases on other
 * drivers should be rebuilt with `php artisan migrate:fresh`.
 */
return new class extends Migration
{
    private const TABLES = [
        'enrollment_requests',
        'activity_logs',
        'enrollment_reject_motifs',
        'identities',
        'otps',
    ];

    public function up(): void
    {
        if (! $this->needsConversion()) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            throw new RuntimeException(
                'Integer→UUID primary key conversion is only implemented for MySQL. '
                .'Rebuild non-MySQL dev databases with `php artisan migrate:fresh`.'
            );
        }

        // Parent with a child FK: enrollment_requests ← activity_logs.enrollment_request_id
        $this->convertEnrollmentRequestsWithActivityLogs();

        foreach (['activity_logs', 'enrollment_reject_motifs', 'identities', 'otps'] as $tableName) {
            $this->convertStandaloneTable($tableName);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Integer→UUID primary key conversion is irreversible.');
    }

    private function needsConversion(): bool
    {
        return Schema::hasTable('enrollment_requests')
            && in_array(Schema::getColumnType('enrollment_requests', 'id'), ['bigint', 'int', 'integer'], true);
    }

    private function convertEnrollmentRequestsWithActivityLogs(): void
    {
        DB::statement('ALTER TABLE enrollment_requests ADD COLUMN uuid_pk CHAR(36) NULL');
        $this->backfillUuids('enrollment_requests');

        if (Schema::hasTable('activity_logs') && Schema::hasColumn('activity_logs', 'enrollment_request_id')) {
            DB::statement('ALTER TABLE activity_logs DROP FOREIGN KEY activity_logs_enrollment_request_id_foreign');
            DB::statement('ALTER TABLE activity_logs ADD COLUMN enrollment_request_uuid CHAR(36) NULL');
            DB::statement(
                'UPDATE activity_logs al JOIN enrollment_requests er ON er.id = al.enrollment_request_id '
                .'SET al.enrollment_request_uuid = er.uuid_pk'
            );
            DB::statement('ALTER TABLE activity_logs DROP COLUMN enrollment_request_id');
            DB::statement('ALTER TABLE activity_logs CHANGE enrollment_request_uuid enrollment_request_id CHAR(36) NULL');
        }

        DB::statement('ALTER TABLE enrollment_requests DROP PRIMARY KEY, DROP COLUMN id');
        DB::statement('ALTER TABLE enrollment_requests CHANGE uuid_pk id CHAR(36) NOT NULL, ADD PRIMARY KEY (id)');

        if (Schema::hasTable('activity_logs') && Schema::hasColumn('activity_logs', 'enrollment_request_id')) {
            DB::statement(
                'ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_enrollment_request_id_foreign '
                .'FOREIGN KEY (enrollment_request_id) REFERENCES enrollment_requests (id) ON DELETE SET NULL'
            );
        }
    }

    private function convertStandaloneTable(string $tableName): void
    {
        if (! Schema::hasTable($tableName)
            || ! in_array(Schema::getColumnType($tableName, 'id'), ['bigint', 'int', 'integer'], true)) {
            return;
        }

        DB::statement("ALTER TABLE {$tableName} ADD COLUMN uuid_pk CHAR(36) NULL");
        $this->backfillUuids($tableName);
        DB::statement("ALTER TABLE {$tableName} DROP PRIMARY KEY, DROP COLUMN id");
        DB::statement("ALTER TABLE {$tableName} CHANGE uuid_pk id CHAR(36) NOT NULL, ADD PRIMARY KEY (id)");
    }

    private function backfillUuids(string $tableName): void
    {
        DB::table($tableName)->select('id')->orderBy('id')->chunk(500, function ($rows) use ($tableName) {
            foreach ($rows as $row) {
                DB::table($tableName)->where('id', $row->id)->update(['uuid_pk' => (string) Str::uuid()]);
            }
        });
    }
};
