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
        Schema::create('supervisor_registration_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('cpf')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('job_role')->nullable();
            $table->boolean('has_academic_background')->nullable();
            $table->string('training_course')->nullable();
            $table->string('education_level')->nullable();
            $table->string('experience_time')->nullable();
            $table->string('status');
            $table->foreignId('supervisor_affiliation_id')->nullable()->constrained('affiliations')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('cpf');
            $table->index('supervisor_affiliation_id', 'supervisor_reg_requests_supervisor_aff_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supervisor_registration_requests');
    }
};
