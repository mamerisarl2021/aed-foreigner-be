<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Aucun compte staff n'est semé : Keycloak est l'annuaire, et les rôles
        // Spatie doivent exister avant la première connexion pour que syncRoles
        // puisse les poser.
        $this->call([
            RoleSeeder::class,
            EnrollmentRejectMotifSeeder::class,
        ]);
    }
}
