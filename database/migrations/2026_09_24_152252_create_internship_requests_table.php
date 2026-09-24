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
        Schema::create('internship_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliation_id')->constrained('affiliations')->restrictOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('internship_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('advisor_affiliation_id')->nullable()->constrained('affiliations')->restrictOnDelete();
            $table->foreignId('granting_party_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('granting_party_registration_request_id')->nullable()->constrained('granting_party_registration_requests')->restrictOnDelete();
            $table->foreignId('supervisor_affiliation_id')->nullable()->constrained('affiliations')->restrictOnDelete();
            $table->foreignId('supervisor_registration_request_id')->nullable()->constrained('supervisor_registration_requests')->restrictOnDelete();
            $table->string('student_year_semester')->nullable();
            $table->string('legal_capacity_declaration')->nullable();
            $table->string('legal_guardian_name')->nullable();
            $table->string('legal_guardian_cpf')->nullable();
            $table->string('legal_guardian_kinship', 80)->nullable();
            $table->string('legal_guardian_email')->nullable();
            $table->text('activities')->nullable();
            $table->string('internship_sector')->nullable();
            $table->jsonb('weekly_hours')->nullable();
            $table->date('planned_start_date')->nullable();
            $table->date('projected_end_date')->nullable();
            $table->boolean('is_remunerated')->nullable();
            $table->decimal('grant_value', 10, 2)->nullable();
            $table->decimal('transportation_allowance', 10, 2)->nullable();
            $table->text('observations')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('internship_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamps();

            $table->index(['affiliation_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('internship_requests');
    }
};
