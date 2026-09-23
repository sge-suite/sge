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
        Schema::create('user_personal_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('rg')->nullable();
            $table->string('rg_issuer')->nullable();
            $table->date('rg_issue_date')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('phone')->nullable();
            $table->string('job_role')->nullable();
            $table->string('qualification')->nullable();
            $table->text('training')->nullable();
            $table->text('professional_experience')->nullable();
            $table->foreignId('address_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->timestamp('emancipation_verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_personal_data');
    }
};
