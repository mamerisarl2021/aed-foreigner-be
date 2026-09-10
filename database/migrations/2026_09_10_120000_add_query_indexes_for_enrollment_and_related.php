<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->index(['status', 'type', 'created_at'], 'enrollment_requests_status_type_created_idx');
            $table->index(['submitted_by_user_id', 'type', 'status'], 'enrollment_requests_submitter_type_status_idx');
            $table->index(['status', 'sla_deadline_at'], 'enrollment_requests_status_sla_deadline_idx');
            $table->index(['status', 'agent_avis'], 'enrollment_requests_status_agent_avis_idx');
            $table->index('email', 'enrollment_requests_email_idx');
        });

        Schema::table('identities', function (Blueprint $table) {
            $table->index(['user_id', 'type', 'status'], 'identities_user_type_status_idx');
        });

        Schema::table('audits', function (Blueprint $table) {
            $table->index('created_at', 'audits_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->dropIndex('enrollment_requests_status_type_created_idx');
            $table->dropIndex('enrollment_requests_submitter_type_status_idx');
            $table->dropIndex('enrollment_requests_status_sla_deadline_idx');
            $table->dropIndex('enrollment_requests_status_agent_avis_idx');
            $table->dropIndex('enrollment_requests_email_idx');
        });

        Schema::table('identities', function (Blueprint $table) {
            $table->dropIndex('identities_user_type_status_idx');
        });

        Schema::table('audits', function (Blueprint $table) {
            $table->dropIndex('audits_created_at_idx');
        });
    }
};
