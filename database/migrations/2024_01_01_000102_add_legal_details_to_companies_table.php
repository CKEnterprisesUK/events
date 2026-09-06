<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Capture the legal/registration details a Company must provide so the
     * Platform can identify and (where applicable) verify the organisation
     * behind a tenant — a legal-team requirement gathered at signup and
     * maintained thereafter on the Company branding/settings surface.
     *
     * The registered `legal_name`, `organisation_type`, main `email`, and
     * registered address (`address_line_1`, `city`, `postcode`, `country`) are
     * required at signup. Public `trading_name`, `website`, `phone`, the
     * conditional registration numbers (`company_number` for Companies House,
     * `charity_number` for the Charity Commission) and `address_line_2` are
     * optional. Existing rows predate this requirement, so every new column is
     * nullable to keep the ALTER backfill-free; signup enforces the required
     * subset at the application layer.
     *
     * The `status` enum is widened from active/suspended to add `pending`
     * (awaiting verification) and `closed` (deactivated) so the full legal
     * lifecycle is representable. The default stays `active` because the
     * suspension gates (EnsureCompanyActive, ResolveTenant) key only off
     * `suspended`; the new values are available for the verification workflow
     * without changing today's signup/login behaviour.
     */
    public function up(): void
    {
        // Widen the status lifecycle first so the enum change is independent of
        // the new columns. MySQL rewrites the enum definition in place.
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('status', ['pending', 'active', 'suspended', 'closed'])
                ->default('active')
                ->change();
        });

        Schema::table('companies', function (Blueprint $table) {
            // Registered/legal organisation name (required at signup). Distinct
            // from `name`, which stays the working display name.
            $table->string('legal_name')->nullable()->after('name');
            // Public-facing name, when it differs from the legal name.
            $table->string('trading_name')->nullable()->after('legal_name');
            // Company / Charity / CIC / Sole trader / Club / Other.
            $table->enum('organisation_type', [
                'company',
                'charity',
                'cic',
                'sole_trader',
                'club',
                'other',
            ])->nullable()->after('trading_name');
            // Companies House number, where applicable to the org type.
            $table->string('company_number', 50)->nullable()->after('organisation_type');
            // Charity Commission number, where applicable to the org type.
            $table->string('charity_number', 50)->nullable()->after('company_number');
            // Optional website, useful for verification.
            $table->string('website')->nullable()->after('charity_number');
            // Main organisation email (required at signup). Separate from the
            // public `support_email` and the `gdpr_contact_email`.
            $table->string('email', 254)->nullable()->after('website');
            // Recommended main contact number.
            $table->string('phone', 50)->nullable()->after('email');
            // Registered/business address (line 1 required at signup).
            $table->string('address_line_1')->nullable()->after('phone');
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('city')->nullable()->after('address_line_2');
            $table->string('postcode', 20)->nullable()->after('city');
            // ISO 3166-1 alpha-2 country code; defaults to UK initially.
            $table->char('country', 2)->default('GB')->after('postcode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name',
                'trading_name',
                'organisation_type',
                'company_number',
                'charity_number',
                'website',
                'email',
                'phone',
                'address_line_1',
                'address_line_2',
                'city',
                'postcode',
                'country',
            ]);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->enum('status', ['active', 'suspended'])
                ->default('active')
                ->change();
        });
    }
};
