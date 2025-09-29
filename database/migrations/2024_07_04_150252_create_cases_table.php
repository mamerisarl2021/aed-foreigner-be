<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCasesTable extends Migration
{
    public function up()
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->string('titre');
            $table->string('type');
            $table->enum('status', ['CLOSED', 'PENDING']);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cases');
    }
}
