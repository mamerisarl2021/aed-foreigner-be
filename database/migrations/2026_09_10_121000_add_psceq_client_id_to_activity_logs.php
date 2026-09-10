<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->uuid('psceq_client_id')->nullable()->after('enrollment_request_id');
            $table->index('psceq_client_id', 'activity_logs_psceq_client_id_idx');
        });

        DB::table('activity_logs')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $metadata = $row->metadata;
                    if (is_string($metadata)) {
                        $metadata = json_decode($metadata, true);
                    }
                    $clientId = is_array($metadata) ? ($metadata['psceq_client_id'] ?? null) : null;
                    if (! is_string($clientId) || $clientId === '') {
                        continue;
                    }

                    DB::table('activity_logs')
                        ->where('id', $row->id)
                        ->update(['psceq_client_id' => $clientId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_psceq_client_id_idx');
            $table->dropColumn('psceq_client_id');
        });
    }
};
