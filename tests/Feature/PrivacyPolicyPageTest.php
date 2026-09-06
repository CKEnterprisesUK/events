<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — task 25.3.
 *
 * Focused feature test for privacy policy page rendering (Requirement 22.3).
 *
 * `/privacy` is a public, reserved-prefix page declared before the
 * `/{company-slug}` storefront catch-all. It requires no authentication,
 * establishes no active Company, and always renders the Platform privacy
 * policy. These assertions are deliberately scoped to that rendering behaviour;
 * the GDPR export/anonymise dashboard tools are covered separately by
 * GdprDataToolsTest (task 25.1).
 */
class PrivacyPolicyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_the_privacy_policy_page(): void
    {
        // Requirement 22.3 — public: no auth required, returns 200 for a guest.
        $this->assertGuest();

        $response = $this->get('/privacy');

        $response->assertOk();
        $response->assertViewIs('privacy');
    }

    public function test_named_privacy_route_renders_the_policy_content(): void
    {
        // Requirement 22.3 — the reserved `privacy` route renders the actual
        // policy body, not just a shell page.
        $response = $this->get(route('privacy'));

        $response->assertOk();
        $response->assertSee('Privacy Policy');
        $response->assertSee('What we collect');
        $response->assertSee('Your rights');
    }

    public function test_privacy_page_establishes_no_active_company(): void
    {
        // Requirement 22.3 — the reserved prefix runs outside tenant
        // resolution, so viewing it never binds an active Company.
        $this->get('/privacy')->assertOk();

        $this->assertFalse(app(TenantContext::class)->hasCompany());
    }

    public function test_reserved_privacy_route_wins_over_a_company_slug(): void
    {
        // Requirement 22.3 — a Company whose slug is literally "privacy" must
        // not shadow the reserved policy page; the reserved route takes
        // precedence and the storefront is never resolved for `/privacy`.
        Company::factory()->create([
            'slug' => 'privacy',
            'name' => 'Privacy Shadowing Co',
        ]);

        $response = $this->get('/privacy');

        $response->assertOk();
        $response->assertViewIs('privacy');
        $response->assertSee('Privacy Policy');
        // The shadowing Company's storefront must not have rendered.
        $response->assertDontSee('Privacy Shadowing Co');
    }
}
