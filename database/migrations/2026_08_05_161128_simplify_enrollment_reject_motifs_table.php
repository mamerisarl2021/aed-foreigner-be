<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $legacy = Schema::hasTable('enrollment_reject_motifs')
            ? DB::table('enrollment_reject_motifs')->get()->all()
            : [];

        Schema::dropIfExists('enrollment_reject_motifs');

        Schema::create('enrollment_reject_motifs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title')->unique();
            $table->text('description');
            $table->timestamps();
        });

        $seen = [];
        foreach ($legacy as $row) {
            $label = isset($row->label_fr) ? (string) $row->label_fr : '';
            $code = isset($row->code) ? (string) $row->code : '';
            $existingTitle = isset($row->title) ? (string) $row->title : '';
            $existingDescription = isset($row->description) ? (string) $row->description : '';

            $base = $existingTitle !== ''
                ? $existingTitle
                : ($label !== '' ? $label : ($code !== '' ? $code : 'Motif'));

            $title = $base;
            $n = 2;
            while (isset($seen[$title])) {
                $title = $base.' ('.$n.')';
                $n++;
            }
            $seen[$title] = true;

            $description = $existingDescription !== ''
                ? $existingDescription
                : ($label !== '' ? $label : $title);

            DB::table('enrollment_reject_motifs')->insert([
                'id' => $row->id ?? (string) Str::uuid(),
                'title' => $title,
                'description' => $description,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        $rows = Schema::hasTable('enrollment_reject_motifs')
            ? DB::table('enrollment_reject_motifs')->get()->all()
            : [];

        Schema::dropIfExists('enrollment_reject_motifs');

        Schema::create('enrollment_reject_motifs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('label_fr');
            $table->string('stage');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        foreach ($rows as $row) {
            DB::table('enrollment_reject_motifs')->insert([
                'id' => $row->id,
                'code' => 'motif_'.substr(str_replace('-', '', (string) $row->id), 0, 12),
                'label_fr' => $row->title,
                'stage' => 'OTHER',
                'active' => true,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }
    }
};
