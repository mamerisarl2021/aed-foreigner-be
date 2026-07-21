<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    private const GUARD = 'web';

    /** @var list<string> */
    private const CANONICAL_ROLES = [
        'agent',
        'responsable_de_validation',
        'manager',
        'administrateur_plateforme',
        'client',
        'demandeur_authentifie',
        'auditeur',
    ];

    /** @var list<string> */
    private const LEGACY_ROLES = [
        'tech_one',
        'tech_two',
        'tech_three',
        'superviseur',
        'admin',
    ];

    /** @var array<string, string> */
    private const RENAME_MAP = [
        'tech_one' => 'agent',
        'tech_two' => 'agent',
        'tech_three' => 'agent',
        'superviseur' => 'responsable_de_validation',
        'admin' => 'administrateur_plateforme',
    ];

    public function up(): void
    {
        foreach (self::CANONICAL_ROLES as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);
        }

        $rolesTable = config('permission.table_names.roles');
        $pivotTable = config('permission.table_names.model_has_roles');
        $modelKey = config('permission.column_names.model_morph_key');
        $rolePivotKey = config('permission.column_names.role_pivot_key') ?? 'role_id';

        foreach (self::RENAME_MAP as $oldName => $newName) {
            $oldRole = Role::query()
                ->where('name', $oldName)
                ->where('guard_name', self::GUARD)
                ->first();

            if ($oldRole === null) {
                continue;
            }

            $newRole = Role::query()
                ->where('name', $newName)
                ->where('guard_name', self::GUARD)
                ->firstOrFail();

            $assignments = DB::table($pivotTable)
                ->where($rolePivotKey, $oldRole->id)
                ->get();

            foreach ($assignments as $assignment) {
                $alreadyAssigned = DB::table($pivotTable)
                    ->where($rolePivotKey, $newRole->id)
                    ->where('model_type', $assignment->model_type)
                    ->where($modelKey, $assignment->{$modelKey})
                    ->exists();

                if (! $alreadyAssigned) {
                    DB::table($pivotTable)->insert([
                        $rolePivotKey => $newRole->id,
                        'model_type' => $assignment->model_type,
                        $modelKey => $assignment->{$modelKey},
                    ]);
                }
            }

            DB::table($pivotTable)->where($rolePivotKey, $oldRole->id)->delete();
        }

        DB::table($rolesTable)
            ->where('guard_name', self::GUARD)
            ->whereIn('name', self::LEGACY_ROLES)
            ->delete();

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        $reverseMap = [
            'agent' => 'tech_one',
            'responsable_de_validation' => 'superviseur',
            'administrateur_plateforme' => 'admin',
        ];

        foreach (['tech_two', 'tech_three'] as $legacyOnly) {
            Role::firstOrCreate(['name' => $legacyOnly, 'guard_name' => self::GUARD]);
        }

        $pivotTable = config('permission.table_names.model_has_roles');
        $rolesTable = config('permission.table_names.roles');
        $modelKey = config('permission.column_names.model_morph_key');
        $rolePivotKey = config('permission.column_names.role_pivot_key') ?? 'role_id';

        foreach ($reverseMap as $newName => $oldName) {
            $newRole = Role::query()
                ->where('name', $newName)
                ->where('guard_name', self::GUARD)
                ->first();

            if ($newRole === null) {
                continue;
            }

            $oldRole = Role::firstOrCreate(['name' => $oldName, 'guard_name' => self::GUARD]);

            $assignments = DB::table($pivotTable)
                ->where($rolePivotKey, $newRole->id)
                ->get();

            foreach ($assignments as $assignment) {
                $alreadyAssigned = DB::table($pivotTable)
                    ->where($rolePivotKey, $oldRole->id)
                    ->where('model_type', $assignment->model_type)
                    ->where($modelKey, $assignment->{$modelKey})
                    ->exists();

                if (! $alreadyAssigned) {
                    DB::table($pivotTable)->insert([
                        $rolePivotKey => $oldRole->id,
                        'model_type' => $assignment->model_type,
                        $modelKey => $assignment->{$modelKey},
                    ]);
                }
            }

            DB::table($pivotTable)->where($rolePivotKey, $newRole->id)->delete();
        }

        DB::table($rolesTable)
            ->where('guard_name', self::GUARD)
            ->whereIn('name', ['agent', 'responsable_de_validation', 'administrateur_plateforme', 'demandeur_authentifie'])
            ->delete();

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
