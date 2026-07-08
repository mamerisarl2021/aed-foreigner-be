<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RenameUserPackageIdToPackageIdInYourTableName extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            // 1️⃣ Supprimer la clé étrangère
            $table->dropForeign(['user_package_id']);

            // 2️⃣ Ajouter la nouvelle colonne `package_id`
            $table->unsignedBigInteger('package_id')->nullable();
        });

        // 3️⃣ Copier les anciennes valeurs
        DB::statement('UPDATE user_subscriptions SET package_id = user_package_id');

        Schema::table('user_subscriptions', function (Blueprint $table) {
            // 4️⃣ Supprimer l'ancienne colonne
            $table->dropColumn('user_package_id');

            // 5️⃣ Ajouter la clé étrangère sur `package_id`
            $table->foreign('package_id')
                ->references('id')
                ->on('user_packages')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            // 1️⃣ Supprimer la clé étrangère
            $table->dropForeign(['package_id']);

            // 2️⃣ Ajouter l'ancienne colonne
            $table->unsignedBigInteger('user_package_id')->nullable();
        });

        // 3️⃣ Copier les données dans l'ancienne colonne
        DB::statement('UPDATE user_subscriptions SET user_package_id = package_id');

        Schema::table('user_subscriptions', function (Blueprint $table) {
            // 4️⃣ Supprimer la colonne `package_id`
            $table->dropColumn('package_id');

            // 5️⃣ Réajouter la clé étrangère sur `user_package_id`
            $table->foreign('user_package_id')
                ->references('id')
                ->on('user_packages')
                ->onDelete('cascade');
        });
    }
}
