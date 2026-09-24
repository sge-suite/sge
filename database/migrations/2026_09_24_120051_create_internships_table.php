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
        Schema::create('internships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_affiliation_id')->constrained('affiliations')->restrictOnDelete();
            $table->foreignId('advisor_affiliation_id')->constrained('affiliations')->restrictOnDelete();
            $table->foreignId('supervisor_affiliation_id')->constrained('affiliations')->restrictOnDelete();
            $table->foreignId('student_address_id')->nullable()->constrained('addresses')->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('internship_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('granting_party_id')->constrained()->restrictOnDelete();
            $table->foreignId('workplace_address_id')->constrained('addresses')->restrictOnDelete();
            $table->jsonb('student_snapshot');
            $table->jsonb('internship_type_snapshot');
            $table->jsonb('granting_party_snapshot');
            $table->jsonb('supervisor_snapshot');
            $table->jsonb('weekly_hours');
            $table->text('activities');
            $table->string('internship_sector')->nullable();
            $table->date('planned_start_date');
            $table->date('projected_end_date');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_affiliation_id')->nullable()->constrained('affiliations')->restrictOnDelete();
            $table->boolean('is_remunerated')->default(false);
            $table->decimal('grant_value', 10, 2)->nullable();
            $table->decimal('transportation_allowance', 10, 2)->nullable();
            $table->string('protocol_number')->nullable();
            $table->text('observations')->nullable();
            $table->decimal('supervisor_grade', 5, 1)->nullable();
            $table->decimal('report_grade', 5, 1)->nullable();
            $table->decimal('presentation_grade', 5, 1)->nullable();
            $table->foreignId('report_graded_by_affiliation_id')->nullable()->constrained('affiliations')->restrictOnDelete();
            $table->foreignId('presentation_graded_by_affiliation_id')->nullable()->constrained('affiliations')->restrictOnDelete();
            $table->timestamp('report_graded_at')->nullable();
            $table->timestamp('presentation_graded_at')->nullable();
            $table->decimal('consolidated_grade', 5, 1)->nullable();
            $table->string('status')->default('pending_formalization');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('internships');
    }
};
