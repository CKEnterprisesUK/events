<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

/**
 * Covers self-service TOTP two-factor for a Super_Admin on the /admin surface,
 * and confirms the login challenge fires for a Super_Admin exactly as it does
 * for a Company_User (the challenge is role-agnostic).
 */
class SuperAdminTwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function currentCodeFor(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp($user->refresh()->two_factor_secret);
    }

    private function enableMfa(User $user): void
    {
        $service = app(TwoFactorAuthenticationService::class);
        $service->startEnrolment($user);
        $service->confirm($user, $this->currentCodeFor($user));
        $user->refresh();
    }

    public function test_super_admin_can_reach_the_security_page(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get('/admin/profile')->assertOk();
    }

    public function test_company_user_cannot_reach_the_super_admin_security_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/profile')->assertForbidden();
    }

    public function test_super_admin_can_enable_and_confirm_two_factor(): void
    {
        $admin = User::factory()->superAdmin()->create(['password' => Hash::make('secret-password')]);

        $this->actingAs($admin)
            ->post('/admin/profile/two-factor', ['current_password' => 'secret-password'])
            ->assertRedirect('/admin/profile/two-factor/setup');

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->assertFalse($admin->hasTwoFactorEnabled(), 'MFA must not be active before confirmation');
        $this->assertCount(8, $admin->two_factor_recovery_codes);

        // Setup screen renders and the QR route streams a real PNG.
        $this->actingAs($admin)->get('/admin/profile/two-factor/setup')->assertOk();
        $qr = $this->actingAs($admin)->get('/admin/profile/two-factor/qr');
        $qr->assertOk();
        $qr->assertHeader('Content-Type', 'image/png');

        // Confirm with a valid code → MFA active.
        $this->actingAs($admin)
            ->post('/admin/profile/two-factor/confirm', ['code' => $this->currentCodeFor($admin)])
            ->assertRedirect();

        $this->assertTrue($admin->refresh()->hasTwoFactorEnabled());
    }

    public function test_super_admin_login_fires_the_two_factor_challenge(): void
    {
        $admin = User::factory()->superAdmin()->create(['password' => Hash::make('secret-password')]);
        $this->enableMfa($admin);

        // Password step alone must NOT authenticate; it redirects to challenge.
        $this->post('/login', ['email' => $admin->email, 'password' => 'secret-password'])
            ->assertRedirect('/two-factor-challenge');
        $this->assertGuest();

        // A valid TOTP completes the login and lands the Super_Admin on /admin.
        $this->post('/two-factor-challenge', ['code' => $this->currentCodeFor($admin)])
            ->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_super_admin_can_disable_two_factor(): void
    {
        $admin = User::factory()->superAdmin()->create(['password' => Hash::make('secret-password')]);
        $this->enableMfa($admin);

        $this->actingAs($admin)
            ->delete('/admin/profile/two-factor', ['current_password' => 'secret-password'])
            ->assertRedirect();

        $this->assertFalse($admin->refresh()->hasTwoFactorEnabled());
        $this->assertNull($admin->two_factor_secret);
    }
}
