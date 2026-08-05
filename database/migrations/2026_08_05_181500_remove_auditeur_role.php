<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $role = Role::query()->where('name', 'auditeur')->where('guard_name', 'web')->first();
        if (! $role) {
            return;
        }

        DB::table(config('permission.table_names.model_has_roles'))
            ->where(config('permission.column_names.role_pivot_key') ?? 'role_id', $role->id)
            ->delete();

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::firstOrCreate(['name' => 'auditeur', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
