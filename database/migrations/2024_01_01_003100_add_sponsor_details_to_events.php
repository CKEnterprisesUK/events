<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-sponsor metadata for the two Event sponsor slots. Alongside each
     * sponsor banner image the organiser can now record a company name, a
     * website link and a short bio, and choose whether that sponsor's logo is
     * printed on the downloadable ticket.
     *
     *   - events.sponsor_{top,bottom}_name       : sponsor's display/company
     *     name. Shown next to the logo on the public store page. NULL = omit.
     *   - events.sponsor_{top,bottom}_website     : sponsor's external URL. The
     *     store-page logo/name links here when set. NULL = not clickable.
     *   - events.sponsor_{top,bottom}_bio         : short blurb shown alongside
     *     the logo on the public store page only. NULL = omit.
     *   - events.sponsor_{top,bottom}_on_ticket   : whether this sponsor's
     *     banner is printed on the ticket PDF. Defaults to true so existing
     *     sponsor banners keep appearing on tickets as before.
     *
     * The name/website/bio surfaces are store-page only; the ticket PDF uses
     * only the image + the on_ticket toggle. When no sponsor banner is printed
     * on the ticket, the ticket falls back to the organiser's own logo.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('sponsor_top_name')->nullable()->after('sponsor_top_path');
            $table->string('sponsor_top_website')->nullable()->after('sponsor_top_name');
            $table->text('sponsor_top_bio')->nullable()->after('sponsor_top_website');
            $table->boolean('sponsor_top_on_ticket')->default(true)->after('sponsor_top_bio');

            $table->string('sponsor_bottom_name')->nullable()->after('sponsor_bottom_path');
            $table->string('sponsor_bottom_website')->nullable()->after('sponsor_bottom_name');
            $table->text('sponsor_bottom_bio')->nullable()->after('sponsor_bottom_website');
            $table->boolean('sponsor_bottom_on_ticket')->default(true)->after('sponsor_bottom_bio');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'sponsor_top_name',
                'sponsor_top_website',
                'sponsor_top_bio',
                'sponsor_top_on_ticket',
                'sponsor_bottom_name',
                'sponsor_bottom_website',
                'sponsor_bottom_bio',
                'sponsor_bottom_on_ticket',
            ]);
        });
    }
};
