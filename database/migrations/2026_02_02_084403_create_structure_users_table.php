<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('structure_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('structure_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('role')->default('EMPLOYEE');
            $table->string('status')->default('PENDING'); // PENDING, ACTIVE, INACTIVE
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('invited_at')->useCurrent();
            $table->text('invitation_message')->nullable();
            $table->timestamps();
            
            $table->unique(['structure_id', 'user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('structure_users');
    }
};