<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the public Storefront profile fields an Owner maintains to enrich
     * their public storefront: an "about the company" description, and a set of
     * external links (social profiles, plus the organiser's own Terms &
     * Conditions and Privacy Notice pages). The organisation `website` already
     * exists (added with the legal details) and is reused, so it is not added
     * here. All are nullable — the storefront simply omits anything unset.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Free-text "about the company" blurb shown on the storefront.
            $table->text('about_text')->nullable()->after('poster_path');

            // External links to the organiser's own pages / profiles.
            $table->string('facebook_url', 255)->nullable()->after('about_text');
            $table->string('instagram_url', 255)->nullable()->after('facebook_url');
            $table->string('x_url', 255)->nullable()->after('instagram_url');
            $table->string('linkedin_url', 255)->nullable()->after('x_url');

            // Organiser's own legal pages, linked from the storefront.
            $table->string('terms_url', 255)->nullable()->after('linkedin_url');
            $table->string('privacy_url', 255)->nullable()->after('terms_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'about_text',
                'facebook_url',
                'instagram_url',
                'x_url',
                'linkedin_url',
                'terms_url',
                'privacy_url',
            ]);
        });
    }
};
