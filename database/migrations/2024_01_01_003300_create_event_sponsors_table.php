<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A repeatable per-Event sponsors list, replacing the two fixed
     * top/bottom sponsor slots that previously lived as columns on `events`.
     *
     * Each row is one sponsor: a logo image plus optional store-page details
     * (name, website, bio) and a per-sponsor `on_ticket` flag choosing whether
     * the logo is printed on the ticket PDF. `sort_order` drives the display
     * order on the public event/storefront page. The number of sponsors per
     * event is unlimited for the public page; the app caps how many may be
     * flagged `on_ticket` (enforced in the controller, not the schema).
     *
     * Existing top/bottom sponsor data on `events` is copied across so nothing
     * is lost. The old `events.sponsor_*` columns are intentionally LEFT IN
     * PLACE (unused) to keep this migration cheap and reversible; a later
     * migration may drop them once the new table has bedded in.
     */
    public function up(): void
    {
        Schema::create('event_sponsors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('image_path');
            $table->string('name')->nullable();
            $table->string('website_url')->nullable();
            $table->text('bio')->nullable();
            $table->boolean('on_ticket')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'sort_order']);
        });

        $this->migrateExistingSponsors();
    }

    /**
     * Copy the legacy top-then-bottom sponsor columns into sponsor rows,
     * preserving order (top = 0, bottom = 1) and the per-slot metadata.
     */
    private function migrateExistingSponsors(): void
    {
        $slots = [
            ['sponsor_top_path', 'sponsor_top_name', 'sponsor_top_website', 'sponsor_top_bio', 'sponsor_top_on_ticket'],
            ['sponsor_bottom_path', 'sponsor_bottom_name', 'sponsor_bottom_website', 'sponsor_bottom_bio', 'sponsor_bottom_on_ticket'],
        ];

        DB::table('events')
            ->select([
                'id',
                'company_id',
                'sponsor_top_path', 'sponsor_top_name', 'sponsor_top_website', 'sponsor_top_bio', 'sponsor_top_on_ticket',
                'sponsor_bottom_path', 'sponsor_bottom_name', 'sponsor_bottom_website', 'sponsor_bottom_bio', 'sponsor_bottom_on_ticket',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($events) use ($slots) {
                $now = now();
                $rows = [];

                foreach ($events as $event) {
                    $order = 0;

                    foreach ($slots as [$pathCol, $nameCol, $websiteCol, $bioCol, $onTicketCol]) {
                        $path = $event->{$pathCol} ?? null;

                        if ($path === null || $path === '') {
                            continue;
                        }

                        $rows[] = [
                            'company_id' => $event->company_id,
                            'event_id' => $event->id,
                            'image_path' => $path,
                            'name' => $event->{$nameCol} ?? null,
                            'website_url' => $event->{$websiteCol} ?? null,
                            'bio' => $event->{$bioCol} ?? null,
                            'on_ticket' => (bool) ($event->{$onTicketCol} ?? false),
                            'sort_order' => $order++,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('event_sponsors')->insert($rows);
                }
            });
    }

    /**
     * Reverse the migration. The legacy `events.sponsor_*` columns were never
     * removed, so dropping this table restores the previous state exactly.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_sponsors');
    }
};
