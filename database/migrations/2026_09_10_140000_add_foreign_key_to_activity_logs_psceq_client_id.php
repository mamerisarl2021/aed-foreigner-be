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
        DB::statement(<<<'SQL'
            UPDATE activity_logs
            SET psceq_client_id = NULL
            WHERE psceq_client_id IS NOT NULL
              AND NOT EXISTS (
                SELECT 1 FROM psceq_clients WHERE psceq_clients.id = activity_logs.psceq_client_id
              )
            SQL);

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreign('psceq_client_id')
                ->references('id')
                ->on('psceq_clients')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropForeign(['psceq_client_id']);
        });
    }
};
