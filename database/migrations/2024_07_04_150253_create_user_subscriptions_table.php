<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserSubscriptionsTable extends Migration
{
    public function up()
    {
        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('structure_id')->nullable()->constrained();
            $table->enum('type', ['EMPLOYEE', 'CITIZEN']);
            $table->enum('status', ['SENT', 'TRAITEDBYSYSTEM', 'REJECTED', 'TRAITEDBYMANAGER', 'TRAITEDBYAGENT']);
            $table->foreignId('user_package_id')->constrained();
            $table->boolean('current')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_subscriptions');
    }
}
