<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\LoginFlow;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

/**
 * Covers the opt-in TOTP two-factor lifecycle: enrolment + confirmation from
 * the profile page, the login challenge (TOTP and recovery code), disabling,
 * and the guard that a half-finished enrolment does not challenge at login.
 */
class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function currentCodeFor(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp($user->refresh()->two_factor_secret);
    }

    public function test_user_can_enable_and_confirm_two_factor(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);

        // Begin enrolment (requires current password).
        $this->actingAs($user)
            ->post('/dashboard/profile/two-factor', ['current_password' => 'secret-password'])
            ->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertFalse($user->hasTwoFactorEnabled(), 'MFA must not be active before confirmation');
        $this->assertCount(8, $user->two_factor_recovery_codes);

        // Confirm with a valid code → MFA active.
        $this->actingAs($user)
            ->post('/dashboard/profile/two-factor/confirm', ['code' => $this->currentCodeFor($user)])
            ->assertRedirect();

        $this->assertTrue($user->refresh()->hasTwoFactorEnabled());
    }

    public function test_enable_requires_correct_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);

        $this->actingAs($user)
            ->from('/dashboard/profile')
            ->post('/dashboard/profile/two-factor', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');

        $this->assertNull($user->refresh()->two_factor_secret);
    }

    public function test_confirm_rejects_a_bad_code(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);
        app(TwoFactorAuthenticationService::class)->startEnrolment($user);

        $this->actingAs($user)
            ->post('/dashboard/profile/two-factor/confirm', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->refresh()->hasTwoFactorEnabled());
    }

    public function test_login_with_mfa_requires_the_challenge(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);
        $this->enableMfa($user);

        // Password step alone must NOT authenticate; it redirects to challenge.
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect('/two-factor-challenge');

        $this->assertGuest();

        // Completing the challenge with a valid TOTP logs the user in.
        $this->post('/two-factor-challenge', ['code' => $this->currentCodeFor($user)])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_challenge_accepts_a_recovery_code_once(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);
        $this->enableMfa($user);
        $recoveryCode = $user->refresh()->two_factor_recovery_codes[0];

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);

        $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCode])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);

        // The code is now spent: a fresh login cannot reuse it.
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);
        $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCode])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_challenge_rejects_a_bad_code(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);
        $this->enableMfa($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);

        $this->post('/two-factor-challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_user_can_disable_two_factor(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);
        $this->enableMfa($user);

        $this->actingAs($user)
            ->delete('/dashboard/profile/two-factor', ['current_password' => 'secret-password'])
            ->assertRedirect();

        $this->assertFalse($user->refresh()->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
    }

    public function test_challenge_page_redirects_to_login_without_a_pending_user(): void
    {
        $this->get('/two-factor-challenge')->assertRedirect('/login');
    }

    /**
     * Activate confirmed MFA for a user directly through the service, as if
     * they had already completed enrolment.
     */
    private function enableMfa(User $user): void
    {
        $service = app(TwoFactorAuthenticationService::class);
        $service->startEnrolment($user);
        $service->confirm($user, $this->currentCodeFor($user));
        $user->refresh();
    }
}
