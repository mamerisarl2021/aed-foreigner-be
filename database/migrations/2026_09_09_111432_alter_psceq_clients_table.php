<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('psceq_clients', function (Blueprint $table) {
            // Informations générales
            $table->string('raison_sociale')->nullable()->after('name');
            $table->string('rccm')->nullable()->after('raison_sociale');
            $table->string('pays')->nullable()->after('rccm');
            $table->string('adresse_siege')->nullable()->after('pays');
            $table->string('site_web')->nullable()->after('adresse_siege');

            // Informations de contact (du PSCEQ lui-même)
            $table->string('email')->nullable()->after('site_web');
            $table->string('telephone')->nullable()->after('email');

            // Point focal / représentant légal
            $table->string('point_focal_nom')->nullable()->after('telephone');
            $table->string('point_focal_prenom')->nullable()->after('point_focal_nom');
            $table->string('point_focal_fonction')->nullable()->after('point_focal_prenom');
            $table->string('point_focal_email')->nullable()->after('point_focal_fonction');
            $table->string('point_focal_telephone')->nullable()->after('point_focal_email');
        });
    }

    public function down(): void
    {
        Schema::table('psceq_clients', function (Blueprint $table) {
            $table->dropColumn([
                'raison_sociale',
                'rccm',
                'pays',
                'adresse_siege',
                'site_web',
                'email',
                'telephone',
                'point_focal_nom',
                'point_focal_prenom',
                'point_focal_fonction',
                'point_focal_email',
                'point_focal_telephone',
            ]);
        });
    }
};
