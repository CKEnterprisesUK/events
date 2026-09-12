<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers the Company_User authentication scaffolding (login / logout / session)
 * established for task 1.2. Role and tenant scoping are exercised in later
 * tasks; here we verify the session base behaves correctly.
 */
class AuthScaffoldingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertViewIs('auth.login');
    }

    public function test_user_can_authenticate_and_gets_a_session(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
            // Suppress the post-login MFA recommendation nudge so this test
            // asserts the plain "session established → dashboard" path. The
            // nudge redirect has its own coverage below.
            'mfa_prompt_dismissed_at' => now(),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/dashboard');
    }

    public function test_user_without_mfa_is_nudged_to_recommendation_after_login(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/two-factor-recommendation');
    }

    public function test_user_can_permanently_dismiss_mfa_recommendation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/two-factor-recommendation/never')
            ->assertRedirect('/dashboard');

        $this->assertNotNull($user->fresh()->mfa_prompt_dismissed_at);
    }

    public function test_invalid_credentials_are_rejected_with_no_session(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
    }

    public function test_authenticated_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
    }
}
