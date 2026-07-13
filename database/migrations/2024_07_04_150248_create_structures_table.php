<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStructuresTable extends Migration
{
    public function up()
    {
        Schema::create('structures', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ifu');
            $table->string('searchbase');
            $table->foreignId('manager_id')->constrained('users');
            $table->enum('status',['APPROVED', 'REJECTED', 'PENDING']);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('structures');
    }
}
