<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The `platform_settings` table holds Platform-wide configuration as a
     * single row. `global_fee_percent` is the default Platform fee applied to
     * Companies without a `company_fee_percent` override. (Requirements 20.5,
     * per the design `platform_settings` data model.)
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            // Default Platform fee %; used when a Company has no override. (20.5, 20.6)
            $table->decimal('global_fee_percent', 5, 2)->default('5.00');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
