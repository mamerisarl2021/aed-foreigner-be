<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dernière trace de la visioconférence en base.
 *
 * Les deux horodatages sont partis avec la migration précédente ; ces notes
 * étaient la troisième colonne du même parcours abandonné, sans lecteur ni
 * écrivain depuis le retrait du statut `VISIO_REQUESTED`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->dropColumn('visio_notes');
        });
    }

    public function down(): void
    {
        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->text('visio_notes')->nullable()->after('type');
        });
    }
};
