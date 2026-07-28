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
        Schema::create('enrollment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('phonenumber')->nullable();
            $table->json('kyc_data')->comment('Stores name, dob, nationality, etc.');
            $table->json('documents')->nullable()->comment('Stores paths to selfie, recto, verso');
            $table->string('liveness')->nullable();
            $table->string('similarity')->nullable();
            $table->enum('status', [
                'PENDING',
                'VISIO_REQUESTED',
                'APPROVED_BY_AGENT',
                'REJECTED_BY_AGENT',
                'RETURNED_TO_AGENT',
                'APPROVED',
                'FINALIZED',
                'REJECTED',
            ])->default('PENDING');
            $table->foreignUuid('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reject_stage')->nullable();
            $table->json('reject_reasons')->nullable();
            $table->text('review_comments')->nullable();
            $table->string('type')->default('PERSONNE_PHYSIQUE');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollment_requests');
    }
};
