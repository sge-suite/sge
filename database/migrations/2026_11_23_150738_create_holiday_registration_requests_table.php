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
        Schema::create('holiday_registration_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campus_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_affiliation_id')->constrained('affiliations')->restrictOnDelete();
            $table->foreignId('reviewed_by_affiliation_id')->nullable()->constrained('affiliations')->nullOnDelete();
            $table->string('kind', 24);
            $table->string('name');
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->date('date')->nullable();
            $table->unsignedSmallInteger('month')->nullable();
            $table->unsignedSmallInteger('day')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('submitted')->index();
            $table->text('decision_notes')->nullable();
            $table->timestamp('reviewed_at', 0)->nullable();
            $table->foreignId('holiday_id')->nullable()->constrained('holidays')->nullOnDelete();
            $table->foreignId('holiday_recurrence_id')->nullable()->constrained('holiday_recurrences')->nullOnDelete();
            $table->timestamps(0);
            $table->index(['campus_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holiday_registration_requests');
    }
};
