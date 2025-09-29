<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Étend l'ENUM status pour inclure APPROVED_BY_AGENT
        DB::statement("ALTER TABLE identities MODIFY COLUMN status ENUM('APPROVED','APPROVED_BY_AGENT','REJECTED','PENDING') NOT NULL DEFAULT 'PENDING'");
    }

    public function down(): void
    {
        // Revient à la liste initiale sans APPROVED_BY_AGENT
        DB::statement("ALTER TABLE identities MODIFY COLUMN status ENUM('APPROVED','REJECTED','PENDING') NOT NULL DEFAULT 'PENDING'");
    }
};
