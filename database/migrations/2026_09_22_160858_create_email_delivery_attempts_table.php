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
        Schema::create('email_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_message_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_affiliation_id')->nullable()->constrained('affiliations')->restrictOnDelete();
            $table->string('purpose');
            $table->text('recipient_email');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status');
            $table->string('provider')->nullable();
            $table->text('provider_message_id')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 120)->nullable();
            $table->timestamps();
            $table->unique(['email_message_id', 'attempt_number']);
            $table->index(['email_message_id', 'status']);
            $table->index(['purpose', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_delivery_attempts');
    }
};
