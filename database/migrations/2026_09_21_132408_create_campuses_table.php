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
        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('cnpj', 14)->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->foreignId('address_id')->index()->constrained()->restrictOnDelete();
            $table->string('legal_representative_name')->nullable();
            $table->string('legal_representative_position')->nullable();
            $table->string('insurance_company_name')->nullable();
            $table->string('insurance_policy_number')->nullable();
            $table->timestamp('deactivated_at')->nullable()->index();
            $table->softDeletes('deleted_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campuses');
    }
};
