<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRevocationsTable extends Migration
{
    public function up()
    {
        Schema::create('revocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('structure_id')->nullable()->constrained()->onDelete('cascade');
            $table->enum('status', ['SENT', 'REJECTED', 'TRAITEDBYMANAGER', 'TRAITEDBYAGENT']);
            $table->string('identity_id');
            $table->string('group_id');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('revocations');
    }
}
