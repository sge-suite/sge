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
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('notification_id')->nullable()->constrained('notifications')->restrictOnDelete();
            $table->string('purpose');
            $table->text('subject')->nullable();
            $table->text('content_text')->nullable();
            $table->text('content_html')->nullable();
            $table->string('template_key')->nullable();
            $table->string('template_version')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['purpose', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
