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
        Schema::create('holiday_recurrences', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('month');
            $table->unsignedSmallInteger('day');
            $table->unsignedSmallInteger('starts_year')->nullable();
            $table->unsignedSmallInteger('ends_year')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes('deleted_at', 0);
            $table->timestamps(0);
            $table->unique(['name', 'city_id', 'month', 'day'], 'holiday_recurrences_territory_unique')->nullsNotDistinct();
        });

        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->index();
            $table->string('name');
            $table->string('scope')->index();
            $table->char('state_code', 2)->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('origin')->default('manual')->index();
            $table->boolean('is_manually_adjusted')->default(false);
            $table->foreignId('holiday_recurrence_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('occurrence_year')->nullable();
            $table->index(['state_code', 'date']);
            $table->index(['city_id', 'date']);
            $table->softDeletes('deleted_at', 0);
            $table->timestamps(0);
            $table->unique([
                'date',
                'name',
                'scope',
                'state_code',
                'city_id',
                'deleted_at',
            ], 'holidays_territory_unique')->nullsNotDistinct();
            $table->unique(['holiday_recurrence_id', 'occurrence_year']);
        });

        Schema::create('holiday_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->string('territory', 8);
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('queried_at', 0)->nullable();
            $table->timestamp('completed_at', 0)->nullable();
            $table->text('error')->nullable();
            $table->string('source_url')->nullable();
            $table->jsonb('payload')->nullable();
            $table->jsonb('summary')->nullable();
            $table->timestamps(0);
            $table->unique(['year', 'territory']);
        });

        Schema::create('holiday_sources', function (Blueprint $table): void {
            $table->id();
            $table->char('source_key', 64)->unique();
            $table->foreignId('holiday_import_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('holiday_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status')->default('pending')->index();
            $table->jsonb('payload');
            $table->jsonb('candidate_ids')->nullable();
            $table->text('reason')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->boolean('missing_from_source')->default(false);
            $table->timestamp('last_seen_at', 0);
            $table->timestamps(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holiday_sources');
        Schema::dropIfExists('holiday_import_runs');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('holiday_recurrences');
    }
};
