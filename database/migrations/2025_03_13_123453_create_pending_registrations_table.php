<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('npi');
            $table->string('email')->nullable();
            $table->string('profile_path')->nullable();
            $table->json('user_data');
            $table->string('registration_token')->unique();
            $table->timestamp('expires_at');
            $table->string('status')->default('PENDING');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('pending_registrations');
    }
};
