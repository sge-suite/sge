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
        Schema::create('internship_request_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_request_id')->constrained()->restrictOnDelete();
            $table->text('message');
            $table->jsonb('affected_sections');
            $table->string('status')->default('open');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['internship_request_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('internship_request_corrections');
    }
};
