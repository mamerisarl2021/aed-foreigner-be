<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MAP = [
        'PENDING' => 'EN_ATTENTE',
        'VISIO_REQUESTED' => 'EN_ATTENTE',
        'RETURNED_TO_AGENT' => 'EN_ATTENTE',
        'APPROVED_BY_AGENT' => 'VALIDATION_AGENT',
        'REJECTED_BY_AGENT' => 'REJET_AGENT',
        'APPROVED' => 'APPROUVEE',
        'REJECTED' => 'REJETEE',
        'FINALIZED' => 'ENROLEE',
    ];

    public function up(): void
    {
        foreach (self::MAP as $from => $to) {
            DB::table('enrollment_requests')->where('status', $from)->update(['status' => $to]);
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE enrollment_requests MODIFY COLUMN status ENUM(
                'AWAITING_CONTACT_VERIFICATION',
                'EN_ATTENTE',
                'VALIDATION_AGENT',
                'REJET_AGENT',
                'APPROUVEE',
                'REJETEE',
                'ENROLEE'
            ) NOT NULL DEFAULT 'EN_ATTENTE'");
        }
    }

    public function down(): void
    {
        $reverse = [
            'EN_ATTENTE' => 'PENDING',
            'VALIDATION_AGENT' => 'APPROVED_BY_AGENT',
            'REJET_AGENT' => 'REJECTED_BY_AGENT',
            'APPROUVEE' => 'APPROVED',
            'REJETEE' => 'REJECTED',
            'ENROLEE' => 'FINALIZED',
        ];

        foreach ($reverse as $from => $to) {
            DB::table('enrollment_requests')->where('status', $from)->update(['status' => $to]);
        }

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
};
