<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('granting_parties', function (Blueprint $table) {
            $table->id();
            $table->string('document_type');
            $table->string('document_number');
            $table->string('name');
            $table->foreignId('address_id')->constrained()->restrictOnDelete();
            $table->string('representative_name');
            $table->string('representative_role');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('field_of_activity');
            $table->string('professional_council')->nullable();
            $table->string('council_registration_number')->nullable();
            $table->string('credentialing_process_number')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('granting_parties');
    }
};
