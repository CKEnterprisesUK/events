<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Requirement 8.1: WHEN a Customer requests events.domain, THE Platform SHALL
 * display the Landing_Page.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_returns_200_and_renders_landing_page(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertViewIs('landing');
        // The landing page carries the product branding.
        $response->assertSee('CK Enterprises', false);
    }

    public function test_landing_page_offers_a_login_link(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee(url('/login'), false);
    }
}
