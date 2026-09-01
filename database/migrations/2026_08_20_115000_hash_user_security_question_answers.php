<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\SecurityQuestions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->whereNotNull('security_questions')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    if (! $user instanceof User) {
                        continue;
                    }

                    $current = $user->security_questions;
                    $migrated = SecurityQuestions::migrateStored(is_array($current) ? $current : null);
                    if ($migrated === $current) {
                        continue;
                    }

                    $user->security_questions = $migrated;
                    $user->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        // Irreversible: plaintext answers cannot be restored from hashes.
    }
};
