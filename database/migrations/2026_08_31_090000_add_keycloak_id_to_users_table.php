<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keycloak devient l'annuaire du staff : la ligne locale n'est plus qu'une
 * projection, rattachée au sujet du JWT et non plus à l'email — un changement
 * d'email dans Keycloak ne doit pas créer un doublon.
 *
 * `must_change_password` disparaît avec les mots de passe staff locaux :
 * Keycloak refuse d'émettre un token tant que l'action requise UPDATE_PASSWORD
 * n'est pas jouée, donc l'application n'a plus rien à signaler au front.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('keycloak_id', 64)->nullable()->unique()->after('email');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['keycloak_id']);
            $table->dropColumn('keycloak_id');
        });
    }
};
