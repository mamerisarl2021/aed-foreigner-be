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
            $table->text('visio_notes')->nullable()->after('type');
            $table->timestamp('visio_requested_at')->nullable()->after('visio_notes');
            $table->timestamp('visio_completed_at')->nullable()->after('visio_requested_at');
            $table->timestamp('returned_at')->nullable()->after('visio_completed_at');
            $table->json('return_reasons')->nullable()->after('returned_at');
            $table->timestamp('sla_deadline_at')->nullable()->after('return_reasons');
            $table->string('sla_alert_level')->nullable()->after('sla_deadline_at');
        });

        // MySQL enum alteration — expand allowed statuses (SQLite uses string; no MODIFY)
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE enrollment_requests MODIFY COLUMN status ENUM('PENDING','VISIO_REQUESTED','APPROVED_BY_AGENT','REJECTED_BY_AGENT','RETURNED_TO_AGENT','APPROVED','FINALIZED','REJECTED') NOT NULL DEFAULT 'PENDING'");
        }

        Schema::create('enrollment_reject_motifs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('label_fr');
            $table->string('stage'); // KYC|DOCUMENT|BIOMETRY|OTHER
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_reject_motifs');

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE enrollment_requests MODIFY COLUMN status ENUM('PENDING','APPROVED_BY_AGENT','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING'");
        }

        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->dropColumn([
                'visio_notes',
                'visio_requested_at',
                'visio_completed_at',
                'returned_at',
                'return_reasons',
                'sla_deadline_at',
                'sla_alert_level',
            ]);
        });
    }
};
