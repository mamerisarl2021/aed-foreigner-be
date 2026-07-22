<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->foreignUuid('submitted_by_user_id')->nullable()->after('type')->constrained('users')->nullOnDelete();
            $table->string('email_verification_token', 64)->nullable()->after('submitted_by_user_id');
            $table->timestamp('email_verified_at')->nullable()->after('email_verification_token');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->timestamp('verification_deadline_at')->nullable()->after('phone_verified_at');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE enrollment_requests MODIFY COLUMN status ENUM(
                'AWAITING_CONTACT_VERIFICATION',
                'PENDING',
                'VISIO_REQUESTED',
                'APPROVED_BY_AGENT',
                'REJECTED_BY_AGENT',
                'RETURNED_TO_AGENT',
                'APPROVED',
                'FINALIZED',
                'REJECTED'
            ) NOT NULL DEFAULT 'PENDING'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE enrollment_requests MODIFY COLUMN status ENUM(
                'PENDING',
                'VISIO_REQUESTED',
                'APPROVED_BY_AGENT',
                'REJECTED_BY_AGENT',
                'RETURNED_TO_AGENT',
                'APPROVED',
                'FINALIZED',
                'REJECTED'
            ) NOT NULL DEFAULT 'PENDING'");
        }

        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by_user_id');
            $table->dropColumn([
                'email_verification_token',
                'email_verified_at',
                'phone_verified_at',
                'verification_deadline_at',
            ]);
        });
    }
};
