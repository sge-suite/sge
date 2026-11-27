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
        Schema::create('affiliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('registration_number')->nullable();
            $table->string('email');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'deactivated_at'], 'affiliations_user_active_idx');
            $table->index(['campus_id', 'type', 'deactivated_at'], 'affiliations_campus_type_active_idx');
            $table->index('registration_number', 'affiliations_registration_number_idx');
        });

        DB::statement("CREATE UNIQUE INDEX affiliations_student_registration_number_unique ON affiliations (registration_number) WHERE type = 'student' AND registration_number IS NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliations');
    }
};
