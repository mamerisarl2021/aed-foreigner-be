<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trustedx_registered_at')->nullable()->after('npi');
            $table->json('security_questions')->nullable()->after('trustedx_registered_at');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE enrollment_requests MODIFY COLUMN status ENUM('PENDING','VISIO_REQUESTED','APPROVED_BY_AGENT','REJECTED_BY_AGENT','RETURNED_TO_AGENT','APPROVED','FINALIZED','REJECTED') NOT NULL DEFAULT 'PENDING'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE enrollment_requests MODIFY COLUMN status ENUM('PENDING','VISIO_REQUESTED','APPROVED_BY_AGENT','RETURNED_TO_AGENT','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING'");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['trustedx_registered_at', 'security_questions']);
        });
    }
};
