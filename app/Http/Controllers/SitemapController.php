<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Event;
use Illuminate\Http\Response;

/**
 * Public XML sitemap for search engines.
 *
 * Served at the reserved `/sitemap.xml` path (no tenant, no auth). Lists only
 * publicly indexable URLs: the landing page, each active Company's storefront,
 * and every published, non-cancelled Event. Private surfaces (dashboard,
 * super-admin, embeds, checkout, auth) are intentionally excluded — they are
 * either behind auth or marked noindex.
 *
 * The `.xml` suffix means this path is never captured by the `/{companySlug}`
 * storefront catch-all (whose slug regex excludes dots), but it is declared
 * before that catch-all as a reserved prefix for clarity and safety.
 */
class SitemapController extends Controller
{
    /**
     * Search engines re-crawl frequently changing sitemaps; keep the response
     * small and cacheable at the edge for a short window.
     */
    private const CACHE_SECONDS = 3600;

    public function index(): Response
    {
        // Active Companies only (suspended/pending/closed storefronts 404 for
        // visitors, so they must not appear in the sitemap). Bypass the tenant
        // global scope: this route resolves no active Company.
        $companies = Company::query()
            ->withoutGlobalScopes()
            ->where('status', Company::STATUS_ACTIVE)
            ->orderBy('id')
            ->get(['id', 'slug']);

        $companySlugs = $companies->pluck('slug', 'id');

        // Published, non-cancelled Events belonging to those active Companies.
        // Bypass the tenant scope for the same reason as above.
        $events = Event::query()
            ->withoutGlobalScopes()
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('is_published', true)
            ->whereNull('cancelled_at')
            ->orderBy('company_id')
            ->orderBy('id')
            ->get(['id', 'company_id', 'starts_at', 'updated_at']);

        $urls = [];

        // Landing page.
        $urls[] = [
            'loc' => route('landing'),
            'changefreq' => 'daily',
            'priority' => '1.0',
        ];

        // One entry per active storefront.
        foreach ($companies as $company) {
            $urls[] = [
                'loc' => route('storefront', ['companySlug' => $company->slug]),
                'changefreq' => 'daily',
                'priority' => '0.8',
            ];
        }

        // One entry per published event.
        foreach ($events as $event) {
            $slug = $companySlugs[$event->company_id] ?? null;

            if ($slug === null) {
                continue;
            }

            $urls[] = [
                'loc' => route('event.page', [
                    'companySlug' => $slug,
                    'event' => $event->getKey(),
                ]),
                'lastmod' => $event->updated_at?->toAtomString(),
                'changefreq' => 'daily',
                'priority' => '0.7',
            ];
        }

        $xml = $this->render($urls);

        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }

    /**
     * Serve robots.txt dynamically so the absolute `Sitemap:` URL always matches
     * the current host/scheme (dev vs production), which a static file cannot.
     * Public storefronts and event pages are crawlable; private/tenant-scoped
     * and already-noindex surfaces are disallowed to preserve crawl budget.
     */
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /dashboard',
            'Disallow: /superadmin',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /password',
            'Disallow: /email',
            'Disallow: /invitations',
            'Disallow: /profile',
            'Disallow: /*/embed',
            'Disallow: /*/checkout',
            '',
            'Sitemap: '.route('sitemap'),
            '',
        ];

        return response(implode("\n", $lines), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }

    /**
     * Render the URL set as a urlset XML document. Locations are XML-escaped;
     * optional lastmod/changefreq/priority are emitted only when present.
     *
     * @param  list<array{loc:string, lastmod?:?string, changefreq?:string, priority?:string}>  $urls
     */
    private function render(array $urls): string
    {
        $lines = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $url) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>'.htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>';

            if (! empty($url['lastmod'])) {
                $lines[] = '    <lastmod>'.htmlspecialchars($url['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</lastmod>';
            }
            if (! empty($url['changefreq'])) {
                $lines[] = '    <changefreq>'.$url['changefreq'].'</changefreq>';
            }
            if (! empty($url['priority'])) {
                $lines[] = '    <priority>'.$url['priority'].'</priority>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }
}
