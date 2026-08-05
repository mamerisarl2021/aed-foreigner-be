<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->nullable()->index();
            $table->string('npi')->nullable()->index();
            $table->string('otp', 64);
            $table->timestamp('valid_until');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otps');
    }
};
