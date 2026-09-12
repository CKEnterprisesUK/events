<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: SEO for public pages.
 *
 * Covers the search-engine and social-sharing surface added to the public
 * storefront and event pages, plus the dynamic sitemap.xml and robots.txt:
 *
 *  - storefront/event pages emit a meta description, canonical link, Open Graph
 *    and Twitter Card tags, and schema.org JSON-LD;
 *  - sitemap.xml lists only publicly indexable URLs (active storefronts +
 *    published, non-cancelled events) and excludes private/unpublished ones;
 *  - robots.txt references the sitemap and disallows private surfaces.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    // ---- Storefront meta ----------------------------------------------------

    public function test_storefront_emits_canonical_open_graph_and_organization_jsonld(): void
    {
        $company = Company::factory()->create([
            'name' => 'Bright Events',
            'about_text' => 'We run charity fundraisers across the UK.',
        ]);

        $response = $this->get("/{$company->slug}");

        $response->assertOk();
        $canonical = route('storefront', ['companySlug' => $company->slug]);

        $response->assertSee('<link rel="canonical" href="'.$canonical.'">', false);
        $response->assertSee('<meta property="og:title" content="Bright Events">', false);
        $response->assertSee('<meta name="description" content="We run charity fundraisers across the UK.">', false);
        $response->assertSee('"@type":"Organization"', false);
        $response->assertSee('"name":"Bright Events"', false);
    }

    // ---- Event page meta + schema.org Event ---------------------------------

    public function test_event_page_emits_event_jsonld_with_offer_and_canonical(): void
    {
        $company = Company::factory()->create(['currency' => 'GBP']);
        $event = Event::factory()->for($company)->published()->create([
            'name' => 'Summer Concert',
            'description' => 'An evening of live music.',
            'venue' => 'The Grand Hall',
        ]);
        TicketType::factory()->forEvent($event)->create([
            'name' => 'General',
            'price_minor' => 2500,
        ]);

        $response = $this->get("/{$company->slug}/{$event->getKey()}");

        $response->assertOk();
        $canonical = route('event.page', [
            'companySlug' => $company->slug,
            'event' => $event->getKey(),
        ]);

        $response->assertSee('<link rel="canonical" href="'.$canonical.'">', false);
        $response->assertSee('"@type":"Event"', false);
        $response->assertSee('"name":"Summer Concert"', false);
        $response->assertSee('"startDate"', false);
        // Lowest ticket price surfaced as an Offer in the event currency.
        $response->assertSee('"@type":"Offer"', false);
        $response->assertSee('"price":"25.00"', false);
        $response->assertSee('"priceCurrency":"GBP"', false);
        // Open Graph image type card + article type.
        $response->assertSee('<meta property="og:type" content="article">', false);
    }

    // ---- sitemap.xml --------------------------------------------------------

    public function test_sitemap_lists_active_storefronts_and_published_events_only(): void
    {
        $active = Company::factory()->create(['status' => Company::STATUS_ACTIVE]);
        $published = Event::factory()->for($active)->published()->create();
        $draft = Event::factory()->for($active)->unpublished()->create();

        $suspended = Company::factory()->create(['status' => Company::STATUS_SUSPENDED]);
        $suspendedEvent = Event::factory()->for($suspended)->published()->create();

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $activeStore = route('storefront', ['companySlug' => $active->slug]);
        $publishedUrl = route('event.page', ['companySlug' => $active->slug, 'event' => $published->getKey()]);
        $draftUrl = route('event.page', ['companySlug' => $active->slug, 'event' => $draft->getKey()]);
        $suspendedStore = route('storefront', ['companySlug' => $suspended->slug]);

        $response->assertSee('<loc>'.htmlspecialchars($activeStore, ENT_XML1).'</loc>', false);
        $response->assertSee('<loc>'.htmlspecialchars($publishedUrl, ENT_XML1).'</loc>', false);

        // Draft events, suspended storefronts and their events must be excluded.
        $response->assertDontSee($draftUrl, false);
        $response->assertDontSee($suspendedStore, false);
    }

    public function test_sitemap_excludes_cancelled_events(): void
    {
        $company = Company::factory()->create(['status' => Company::STATUS_ACTIVE]);
        $cancelled = Event::factory()->for($company)->published()->create([
            'cancelled_at' => now(),
        ]);

        $response = $this->get('/sitemap.xml');

        $cancelledUrl = route('event.page', ['companySlug' => $company->slug, 'event' => $cancelled->getKey()]);
        $response->assertOk();
        $response->assertDontSee($cancelledUrl, false);
    }

    // ---- robots.txt ---------------------------------------------------------

    public function test_robots_references_sitemap_and_disallows_private_paths(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('Sitemap: '.route('sitemap'), false);
        $response->assertSee('Disallow: /dashboard', false);
        $response->assertSee('Disallow: /superadmin', false);
    }
}
