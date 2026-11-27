<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            $table->text('general_message')->nullable();
            $table->jsonb('affected_sections');
            $table->jsonb('section_messages')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['internship_request_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX internship_request_corrections_one_open_unique ON internship_request_corrections (internship_request_id) WHERE status = 'open'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('internship_request_corrections');
    }
};
