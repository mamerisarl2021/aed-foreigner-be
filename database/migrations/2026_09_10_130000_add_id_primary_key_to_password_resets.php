<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('password_resets', 'id')) {
            return;
        }

        Schema::table('password_resets', function (Blueprint $table) {
            $table->uuid('id')->nullable();
        });

        DB::statement('UPDATE password_resets SET id = gen_random_uuid() WHERE id IS NULL');
        DB::statement('ALTER TABLE password_resets ALTER COLUMN id SET NOT NULL');
        DB::statement('ALTER TABLE password_resets ADD PRIMARY KEY (id)');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('password_resets', 'id')) {
            return;
        }

        Schema::table('password_resets', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropColumn('id');
        });
    }
};
