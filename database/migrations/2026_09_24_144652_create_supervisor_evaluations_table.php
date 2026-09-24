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
        Schema::create('supervisor_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete();
            $table->foreignId('supervisor_affiliation_id')->constrained('affiliations')->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->boolean('has_academic_background')->nullable();
            $table->string('training_course')->nullable();
            $table->string('education_level')->nullable();
            $table->string('job_role')->nullable();
            $table->string('experience_time')->nullable();
            $table->boolean('hours_requirement_met')->nullable();
            $table->unsignedSmallInteger('estimated_hours_remaining')->nullable();
            $table->string('performance')->nullable();
            $table->string('comprehension')->nullable();
            $table->string('technical_knowledge')->nullable();
            $table->string('organization')->nullable();
            $table->string('initiative')->nullable();
            $table->string('attendance')->nullable();
            $table->string('discipline')->nullable();
            $table->string('sociability')->nullable();
            $table->string('cooperation')->nullable();
            $table->string('responsibility')->nullable();
            $table->text('considerations')->nullable();
            $table->text('suggestions_to_institution')->nullable();
            $table->text('performance_issues')->nullable();
            $table->text('other_observations')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->unique(['internship_id', 'supervisor_affiliation_id']);
            $table->index(['internship_id', 'status', 'submitted_at']);
            $table->index('supervisor_affiliation_id');
        });

        Schema::table('internships', function (Blueprint $table) {
            $table->timestamp('evaluation_released_at')->nullable();
            $table->foreignId('evaluation_released_by_affiliation_id')->nullable()->constrained('affiliations')->restrictOnDelete();
            $table->foreignId('current_supervisor_evaluation_id')->nullable()->constrained('supervisor_evaluations')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('internships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_supervisor_evaluation_id');
            $table->dropConstrainedForeignId('evaluation_released_by_affiliation_id');
            $table->dropColumn('evaluation_released_at');
        });

        Schema::dropIfExists('supervisor_evaluations');
    }
};
