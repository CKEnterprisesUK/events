<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the redesigned public marketing site: the primary navigation IA, the
 * auth-aware header, the dedicated marketing pages resolving, and the legacy
 * redirect. The shared header/footer live in layouts.app partials, so these
 * assertions apply across every public page.
 */
class MarketingSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_logged_out_header_shows_sign_in_and_get_started(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Sign in', false);
        $response->assertSee('Get started', false);
        // The primary destinations are present.
        $response->assertSee('Features', false);
        $response->assertSee('Pricing', false);
        $response->assertSee('How it works', false);
        $response->assertSee('For charities', false);
        $response->assertSee('Trust', false);
        // "Why us" has been removed from the primary navigation.
        $response->assertDontSee('Why us', false);
    }

    public function test_logged_out_header_does_not_show_a_dashboard_cta(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('>Dashboard<', false);
    }

    public function test_authenticated_header_shows_dashboard_instead_of_auth_ctas(): void
    {
        $user = User::factory()->owner()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('Dashboard', false);
        $response->assertDontSee('Get started', false);
        $response->assertDontSee('Sign in', false);
    }

    #[DataProvider('marketingRoutes')]
    public function test_marketing_routes_resolve(string $name): void
    {
        $response = $this->get(route($name));

        $response->assertOk();
    }

    public static function marketingRoutes(): array
    {
        return [
            'home' => ['landing'],
            'features' => ['features'],
            'pricing' => ['pricing'],
            'how it works' => ['how-it-works'],
            'for charities' => ['for-charities'],
            'trust' => ['trust.index'],
        ];
    }

    public function test_pricing_page_shows_the_live_platform_fee(): void
    {
        $response = $this->get(route('pricing'));

        $response->assertOk();
        // Default Global_Fee_Percent is 5% and drives the calculator + copy.
        $response->assertSee('5% per paid ticket', false);
        $response->assertSee('Pricing calculator', false);
        $response->assertSee('Estimated payout', false);
    }

    public function test_legacy_why_us_redirects_to_for_charities(): void
    {
        $response = $this->get('/why-us');

        $response->assertRedirect('/for-charities');
    }
}
