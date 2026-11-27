<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('document_type');
            $table->string('remuneration')->default('any')->index();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });

        DB::statement("CREATE UNIQUE INDEX document_templates_one_orientation_certificate_unique ON document_templates (document_type) WHERE document_type = 'orientation_certificate'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
