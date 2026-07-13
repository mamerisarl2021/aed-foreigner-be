<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class UpdateStatusEnumInIdentitiesTable extends Migration
{
    public function up()
    {
        // Modifier la colonne 'status' pour ajouter 'WAITING_MANAGER'
        DB::statement("ALTER TABLE identities MODIFY status ENUM('APPROVED', 'REJECTED', 'PENDING', 'WAITING_MANAGER', 'PLANNED', 'RESCHEDULED')");
    }

    public function down()
    {
        // Revenir à l'énumération précédente sans 'WAITING_MANAGER'
        DB::statement("ALTER TABLE identities MODIFY status ENUM('APPROVED', 'APPROVED_BY_AGENT', 'REJECTED', 'PENDING', 'PLANNED', 'RESCHEDULED')");
    }
}
