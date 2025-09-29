<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStructureSubscriptionsTable extends Migration
{
    public function up()
    {
        Schema::create('structure_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('structure_id')->constrained();
            $table->foreignId('structure_package_id')->constrained();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('structure_subscriptions');
    }
}
