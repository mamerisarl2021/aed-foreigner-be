<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpdateStatusEnumInStructuresTable extends Migration
{
    public function up()
    {
        // Modifier la colonne 'status' pour ajouter 'WAITING_MANAGER'
        DB::statement("ALTER TABLE structures MODIFY status ENUM('APPROVED', 'REJECTED', 'PENDING', 'WAITING_MANAGER')");
    }

    public function down()
    {
        // Revenir à l'énumération précédente sans 'WAITING_MANAGER'
        DB::statement("ALTER TABLE structures MODIFY status ENUM('APPROVED', 'REJECTED', 'PENDING')");
    }
}