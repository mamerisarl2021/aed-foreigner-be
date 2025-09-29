<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserPackagesTable extends Migration
{
    public function up()
    {
        Schema::create('user_packages', function (Blueprint $table) {
            $table->id();
            $table->integer('prix');
            $table->integer('validity');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_packages');
    }
}
