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
            $table->char('cnpj', 14);
            $table->string('phone');
            $table->foreignId('address_id')->index()->constrained()->restrictOnDelete();
            $table->string('legal_representative_name');
            $table->string('legal_representative_position');
            $table->string('insurance_company_name');
            $table->string('insurance_policy_number');
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
