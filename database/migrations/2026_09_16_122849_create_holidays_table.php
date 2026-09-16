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
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('name');
            $table->string('scope')->index();
            $table->char('state_code', 2)->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->restrictOnDelete();
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
            ])->nullsNotDistinct();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
