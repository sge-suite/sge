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
        Schema::create('template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('file_sha256', 64);
            $table->unsignedBigInteger('file_size');
            $table->jsonb('required_variables');
            $table->jsonb('optional_variables');
            $table->jsonb('detected_variables')->nullable();
            $table->jsonb('validation_report')->nullable();
            $table->foreignId('uploaded_by_affiliation_id')->constrained('affiliations')->restrictOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by_affiliation_id')->nullable()->constrained('affiliations')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['document_template_id', 'version']);
            $table->unique(['document_template_id', 'file_sha256']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_versions');
    }
};
