<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identities', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_agent_id')->nullable()->after('status');
            $table->enum('reject_stage', ['KYC', 'STRUCTURE'])->nullable()->after('assigned_agent_id');
            $table->text('reject_reasons')->nullable()->after('reject_stage');
            $table->text('review_comments')->nullable()->after('reject_reasons');

            $table->foreign('assigned_agent_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('identities', function (Blueprint $table) {
            $table->dropForeign(['assigned_agent_id']);
            $table->dropColumn(['assigned_agent_id', 'reject_stage', 'reject_reasons', 'review_comments']);
        });
    }
};
