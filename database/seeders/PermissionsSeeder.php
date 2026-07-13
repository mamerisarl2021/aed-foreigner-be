<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // =======================================================================================#
        //			               Reset cached roles and permissions                          	#
        // =======================================================================================#
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // =======================================================================================#
        //			                            Create permissions                              	#
        // =======================================================================================#
        $permissions = [
            // User management
            'view users',
            'create users',
            'edit users',
            'delete users',

            // Structure management
            'view structures',
            'create structures',
            'edit structures',
            'delete structures',

            // Document management
            'view documents',
            'create documents',
            'edit documents',
            'delete documents',

            // Case management
            'view cases',
            'create cases',
            'edit cases',
            'delete cases',

            // Attachment management
            'view attachments',
            'create attachments',
            'edit attachments',
            'delete attachments',

            // Message management
            'view messages',
            'create messages',
            'edit messages',
            'delete messages',
            'edit subscriptions',

            // Subscription and package management
            'view subscriptions',
            'manage subscriptions',
            'view packages',
            'manage packages',

            // Signing identity management
            'view signing identities',
            'provision signing identities',
            'update signing identities',
            'delete signing identities',

            // Management actions
            'update document status',
            'update structure status',
            'update attachment status',
            'update user status',
            'update identity status',
            'validate employee request',
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // =======================================================================================#
        //			                            Create roles                                      #
        // =======================================================================================#
        $roleAdmin = Role::create(['name' => 'admin']);
        $roleClient = Role::create(['name' => 'client']);
        $roleAuditeur = Role::create(['name' => 'auditeur']);
        $roleSuperviseur = Role::create(['name' => 'superviseur']);
        $roleAnip = Role::create(['name' => 'anip']);
        $roleTechOne = Role::create(['name' => 'tech_one']);
        $roleTechTwo = Role::create(['name' => 'tech_two']);
        $roleTechThree = Role::create(['name' => 'tech_three']);

        // =======================================================================================#
        //			                            Assign permissions to roles                      #
        // =======================================================================================#

        // Assign all permissions to admin
        $roleAdmin->givePermissionTo(Permission::all());

        // Define permissions for client
        $roleClient->givePermissionTo([
            'view users',
            'create structures',
            'view structures',
            'edit documents',
            'view documents',
            'view cases',
            'edit messages',
            'view messages',
            'edit subscriptions',
            'view subscriptions',
            'view packages',
            'view signing identities',
            'edit cases',
            'view attachments',
            'edit attachments',
            'view signing identities',
            'provision signing identities',
        ]);

        // Define permissions for anip
        $roleAnip->givePermissionTo([
            'view structures',
            'edit structures',
            'view documents',
            'edit documents',
            'view cases',
            'edit cases',
            'view attachments',
            'edit attachments',
        ]);

        // Define permissions for tech_one
        $roleTechOne->givePermissionTo([
            'view users',
            'edit users',
            'view documents',
            'edit documents',
            'view attachments',
            'edit attachments',
            'update document status',
        ]);

        // Define permissions for tech_one
        $roleSuperviseur->givePermissionTo([
            'view users',
            'edit users',
            'view documents',
            'edit documents',
            'view attachments',
            'edit attachments',
            'update document status',
        ]);

        // Define permissions for tech_one
        $roleAuditeur->givePermissionTo([
            'view users',
            'edit users',
            'view documents',
            'edit documents',
            'view attachments',
            'edit attachments',
            'update document status',
        ]);

        // Define permissions for tech_two
        $roleTechTwo->givePermissionTo([
            'view structures',
            'create structures',
            'edit structures',
            'view documents',
            'create documents',
            'edit documents',
        ]);

        // Define permissions for tech_three
        $roleTechThree->givePermissionTo([
            'view subscriptions',
            'manage subscriptions',
            'view packages',
            'manage packages',
            'validate employee request',
            'update identity status',
        ]);
    }
}
