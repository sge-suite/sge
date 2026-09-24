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
        Schema::create('granting_party_registration_requests', function (Blueprint $table) {
            $table->id();
            $table->string('document_type')->nullable();
            $table->string('document_number')->nullable();
            $table->string('name')->nullable();
            $table->string('street')->nullable();
            $table->string('number')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('city')->nullable();
            $table->string('uf')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('representative_name')->nullable();
            $table->string('representative_role')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('field_of_activity')->nullable();
            $table->string('professional_council')->nullable();
            $table->string('council_registration_number')->nullable();
            $table->string('credentialing_process_number')->nullable();
            $table->string('status');
            $table->foreignId('granting_party_id')->nullable()->constrained('granting_parties')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('granting_party_registration_requests');
    }
};
