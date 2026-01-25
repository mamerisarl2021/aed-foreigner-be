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
        Schema::table('identities', function (Blueprint $table) {
            $table->integer('risk_score')->nullable()->after('status');
            $table->json('analysis_details')->nullable()->after('risk_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('identities', function (Blueprint $table) {
            $table->dropColumn(['risk_score', 'analysis_details']);
        });
    }
};
