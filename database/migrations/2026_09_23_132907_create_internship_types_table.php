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
        Schema::create('internship_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('required_hours');
            $table->integer('supervisor_evaluation_weight');
            $table->integer('report_weight');
            $table->integer('presentation_weight');
            $table->decimal('very_good_value', 5, 1);
            $table->decimal('good_value', 5, 1);
            $table->decimal('satisfactory_value', 5, 1);
            $table->decimal('unsatisfactory_value', 5, 1);
            $table->unsignedInteger('max_daily_hours')->default(6);
            $table->unsignedInteger('max_weekly_hours')->default(30);
            $table->unsignedInteger('safety_margin_days')->default(7);
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('internship_types');
    }
};
