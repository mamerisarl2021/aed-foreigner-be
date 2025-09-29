<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['IN_PERSON', 'ONLINE'])->default('ONLINE')->nullable();
            $table->enum('level', ['SIMPLE', 'ADVANCED'])->default('SIMPLE');
            $table->text('proof')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('status', ['APPROVED', 'APPROVED_BY_AGENT', 'REJECTED', 'PENDING'])->default('PENDING');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('identities');
    }
};
