<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSignaturesTable extends Migration
{
    public function up()
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('signature_document_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('status', ['pending', 'signed', 'declined'])->default('pending');
            $table->timestamps();

            $table->foreign('signature_document_id')->references('id')->on('signature_documents')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('signatures');
    }
}
