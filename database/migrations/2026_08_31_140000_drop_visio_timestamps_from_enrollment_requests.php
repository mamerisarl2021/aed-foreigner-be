<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La plateforme n'organise pas de visioconférence.
 *
 * Ces deux horodatages accompagnaient un statut `VISIO_REQUESTED` retiré du
 * circuit dès l'alignement sur le diagramme (migration du 2026_07_23, qui l'a
 * réécrit en `EN_ATTENTE`). Depuis, aucun code ne les lit ni ne les écrit :
 * ils ne survivaient que dans le `$fillable` du modèle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->dropColumn(['visio_requested_at', 'visio_completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->timestamp('visio_requested_at')->nullable()->after('visio_notes');
            $table->timestamp('visio_completed_at')->nullable()->after('visio_requested_at');
        });
    }
};
