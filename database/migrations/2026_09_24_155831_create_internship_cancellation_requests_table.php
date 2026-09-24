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
        Schema::create('internship_cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->string('status')->default('submitted');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->date('effective_date')->nullable();
            $table->timestamps();

            $table->index(['internship_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('internship_cancellation_requests');
    }
};
