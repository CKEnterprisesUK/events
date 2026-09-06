<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The `companies` table is the tenant root: every Company-owned row
     * elsewhere carries `company_id` referencing this table. Money-related
     * defaults (fee handling, currency) and branding live here per the design
     * `companies` data model.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Unique, case-insensitively looked up; validated to 1-255 chars,
            // lowercase alphanumeric + hyphens. (Requirement 1.6)
            $table->string('slug', 255)->unique();
            // Suspension flag. (Requirement 2.x, 20.3, 20.4)
            $table->enum('status', ['active', 'suspended'])->default('active');
            // Who bears the Application_Fee; defaults to Absorb. (13.1, 13.2)
            $table->enum('fee_handling_mode', ['absorb', 'pass_on'])->default('absorb');
            // Per-Company fee override; NULL => use Global_Fee_Percent. (12.3, 12.4, 20.6)
            $table->decimal('company_fee_percent', 5, 2)->nullable();
            // Stripe Connect linkage. (11.2)
            $table->string('stripe_account_id')->nullable();
            // Mirrors connected-account capability updates. (11.3, 11.4)
            $table->boolean('stripe_charges_enabled')->default(false);
            // Company currency (ISO 4217).
            $table->char('currency', 3)->default('GBP');
            // Branding. (7.1, 7.2, 7.3, 7.4)
            $table->string('primary_colour', 7)->nullable();
            $table->string('logo_path')->nullable();
            $table->text('terms_text')->nullable();
            $table->json('ticket_field_defs')->nullable();
            $table->timestamps();
        });

        // The framework-scaffold `users` migration runs before this one (by
        // filename order) and creates `users.company_id` as a plain nullable
        // column; wire the foreign key now that `companies` exists. NULL is
        // retained for Super_Admins, who belong to no Company.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('company_id')
                ->references('id')->on('companies')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::dropIfExists('companies');
    }
};
