<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('structure_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('structure_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('invited_by')->constrained('users')->onDelete('cascade');
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->string('role')->default('EMPLOYEE');
            $table->string('status')->default('PENDING'); // PENDING, ACCEPTED, REJECTED, EXPIRED
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index('token');
            $table->index(['structure_id', 'user_id']);
            $table->index(['email', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('structure_invitations');
    }
};
