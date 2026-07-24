<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->foreignUuid('assigned_responsable_id')->nullable()->after('assigned_agent_id')->constrained('users')->nullOnDelete();
            $table->timestamp('agent_decided_at')->nullable()->after('assigned_responsable_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_responsable_id');
            $table->dropColumn('agent_decided_at');
        });
    }
};
