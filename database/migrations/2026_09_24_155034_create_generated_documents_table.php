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
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete();
            $table->foreignId('template_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('origin');
            $table->string('type');
            $table->string('status')->default('generated');
            $table->string('signature_availability_location', 500)->nullable();
            $table->jsonb('snapshot')->nullable();
            $table->uuid('generation_token')->unique();
            $table->string('output_filename')->nullable();
            $table->string('template_sha256', 64)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['internship_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
