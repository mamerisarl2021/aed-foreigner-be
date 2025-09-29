<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStructurePackagesTable extends Migration
{
    public function up()
    {
        Schema::create('structure_packages', function (Blueprint $table) {
            $table->id();
            $table->integer('prix');
            $table->integer('validity');
            $table->integer('quantity');
            $table->enum('type', ['EPF', 'VID']);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('structure_packages');
    }
}
