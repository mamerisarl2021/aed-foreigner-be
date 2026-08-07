<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sépare la position d'une demande de l'avis rendu par l'agent.
 *
 * `VALIDATION_AGENT` / `REJET_AGENT` portaient les deux à la fois : la position
 * devient `EN_ATTENTE_RESPONSABLE` (ou `EN_COURS_RESPONSABLE` si le responsable
 * avait déjà pris la décision en charge) et l'avis passe dans `agent_avis`.
 * La prise en charge, jusqu'ici invisible dans le statut, obtient ses propres
 * états `EN_COURS_AGENT` / `EN_COURS_RESPONSABLE`.
 */
return new class extends Migration
{
    private const OLD_STATUSES = [
        'AWAITING_CONTACT_VERIFICATION',
        'EN_ATTENTE',
        'VALIDATION_AGENT',
        'REJET_AGENT',
        'APPROUVEE',
        'REJETEE',
        'ENROLEE',
    ];

    private const NEW_STATUSES = [
        'AWAITING_CONTACT_VERIFICATION',
        'EN_ATTENTE_AGENT',
        'EN_COURS_AGENT',
        'EN_ATTENTE_RESPONSABLE',
        'EN_COURS_RESPONSABLE',
        'APPROUVEE',
        'REJETEE',
        'ENROLEE',
    ];

    public function up(): void
    {
        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->string('agent_avis', 20)->nullable()->after('status');
        });

        // La colonne est un ENUM : elle doit accepter les deux jeux de valeurs
        // le temps de réécrire les lignes.
        $this->setStatusEnum([...self::OLD_STATUSES, ...self::NEW_STATUSES], 'EN_ATTENTE_AGENT');

        DB::table('enrollment_requests')
            ->where('status', 'EN_ATTENTE')
            ->whereNull('assigned_agent_id')
            ->update(['status' => 'EN_ATTENTE_AGENT']);

        DB::table('enrollment_requests')
            ->where('status', 'EN_ATTENTE')
            ->whereNotNull('assigned_agent_id')
            ->update(['status' => 'EN_COURS_AGENT']);

        foreach (['VALIDATION_AGENT' => 'FAVORABLE', 'REJET_AGENT' => 'DEFAVORABLE'] as $from => $avis) {
            DB::table('enrollment_requests')
                ->where('status', $from)
                ->whereNull('assigned_responsable_id')
                ->update(['status' => 'EN_ATTENTE_RESPONSABLE', 'agent_avis' => $avis]);

            DB::table('enrollment_requests')
                ->where('status', $from)
                ->whereNotNull('assigned_responsable_id')
                ->update(['status' => 'EN_COURS_RESPONSABLE', 'agent_avis' => $avis]);
        }

        // Dossiers déjà tranchés : l'avis se déduit du sens de la décision
        // finale, sans quoi le bloc « décision agent » resterait vide en relecture.
        DB::table('enrollment_requests')
            ->whereIn('status', ['APPROUVEE', 'ENROLEE'])
            ->update(['agent_avis' => 'FAVORABLE']);

        DB::table('enrollment_requests')
            ->where('status', 'REJETEE')
            ->update(['agent_avis' => 'DEFAVORABLE']);

        $this->setStatusEnum(self::NEW_STATUSES, 'EN_ATTENTE_AGENT');
    }

    public function down(): void
    {
        $this->setStatusEnum([...self::OLD_STATUSES, ...self::NEW_STATUSES], 'EN_ATTENTE');

        DB::table('enrollment_requests')
            ->whereIn('status', ['EN_ATTENTE_AGENT', 'EN_COURS_AGENT'])
            ->update(['status' => 'EN_ATTENTE']);

        DB::table('enrollment_requests')
            ->whereIn('status', ['EN_ATTENTE_RESPONSABLE', 'EN_COURS_RESPONSABLE'])
            ->where('agent_avis', 'DEFAVORABLE')
            ->update(['status' => 'REJET_AGENT']);

        DB::table('enrollment_requests')
            ->whereIn('status', ['EN_ATTENTE_RESPONSABLE', 'EN_COURS_RESPONSABLE'])
            ->update(['status' => 'VALIDATION_AGENT']);

        $this->setStatusEnum(self::OLD_STATUSES, 'EN_ATTENTE');

        Schema::table('enrollment_requests', function (Blueprint $table) {
            $table->dropColumn('agent_avis');
        });
    }

    /**
     * Redéfinit l'ENUM via le Schema Builder plutôt qu'un ALTER brut : c'est lui
     * que lit l'analyse statique pour typer `status`, et un ALTER en SQL direct
     * la laisserait sur les valeurs de la migration d'origine.
     *
     * @param  list<string>  $statuses
     */
    private function setStatusEnum(array $statuses, string $default): void
    {
        $values = array_values(array_unique($statuses));

        Schema::table('enrollment_requests', function (Blueprint $table) use ($values, $default) {
            $table->enum('status', $values)->default($default)->nullable(false)->change();
        });
    }
};
