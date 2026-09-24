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
        Schema::create('emancipation_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_request_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('submitted');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();

            $table->index(['internship_request_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emancipation_evidences');
    }
};
