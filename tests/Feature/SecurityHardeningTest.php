<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

/**
 * Security review hardening: API account gating, token revocation, response
 * headers, single-use TOTP codes and invitation acceptance.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    /**
     * @return array<string, string>
     */
    private function loginPayload(User $user): array
    {
        return [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'device_id' => 'device-security-test',
        ];
    }

    private function scannerFor(Company $company): User
    {
        return User::factory()->for($company)->scanner()->create([
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    // ---- API sign-in is gated like the web login -----------------------------

    public function test_api_login_issues_a_token_for_an_active_account(): void
    {
        $user = $this->scannerFor(Company::factory()->create());

        $this->postJson('/api/auth/login', $this->loginPayload($user))
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_api_login_refuses_a_disabled_account(): void
    {
        $user = $this->scannerFor(Company::factory()->create());
        $user->forceFill(['disabled_at' => now()])->save();

        $this->postJson('/api/auth/login', $this->loginPayload($user))
            ->assertStatus(422)
            ->assertJsonMissingPath('token');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_api_login_refuses_a_user_of_a_suspended_company(): void
    {
        $user = $this->scannerFor(Company::factory()->suspended()->create());

        $this->postJson('/api/auth/login', $this->loginPayload($user))
            ->assertStatus(422)
            ->assertJsonMissingPath('token');

        $this->assertSame(0, $user->tokens()->count());
    }

    // ---- Tokens die when the account's trust changes -------------------------

    public function test_changing_the_password_revokes_api_tokens(): void
    {
        $user = $this->scannerFor(Company::factory()->create());
        $user->createToken('device');

        $user->update(['password' => 'a-brand-new-password']);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_disabling_the_account_revokes_api_tokens(): void
    {
        $user = $this->scannerFor(Company::factory()->create());
        $user->createToken('device');

        $user->forceFill(['disabled_at' => now()])->save();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_changing_the_role_revokes_api_tokens(): void
    {
        $user = $this->scannerFor(Company::factory()->create());
        $user->createToken('device');

        $user->update(['role' => User::ROLE_ADMIN]);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_unrelated_profile_changes_keep_api_tokens(): void
    {
        $user = $this->scannerFor(Company::factory()->create());
        $user->createToken('device');

        $user->update(['name' => 'Renamed Person']);

        $this->assertSame(1, $user->tokens()->count());
    }

    // ---- Response headers ----------------------------------------------------

    public function test_pages_forbid_cross_origin_framing_and_sniffing(): void
    {
        $this->get('/')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_embed_widgets_stay_framable_by_any_site(): void
    {
        $company = Company::factory()->create();

        $response = $this->get("/{$company->slug}/embed/booking")->assertOk();

        $response->assertHeader('Content-Security-Policy', 'frame-ancestors *');
        $response->assertHeaderMissing('X-Frame-Options');
    }

    // ---- Single-use TOTP codes -----------------------------------------------

    public function test_a_totp_code_cannot_be_replayed_for_sign_in(): void
    {
        $user = User::factory()->create();
        $service = app(TwoFactorAuthenticationService::class);
        $service->startEnrolment($user);
        $user->refresh();

        $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);

        $this->assertTrue($service->verifyTotp($user, $code));
        $this->assertFalse($service->verifyTotp($user, $code));
    }

    // ---- Invitation acceptance -----------------------------------------------

    public function test_accepting_an_invitation_for_an_email_that_already_has_an_account_is_refused(): void
    {
        $company = Company::factory()->create();
        User::factory()->create(['email' => 'taken@example.com']);
        $invitation = Invitation::factory()->for($company)->admin()->create(['email' => 'taken@example.com']);

        $this->post("/invitations/{$invitation->token}", [
            'name' => 'Someone',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertSessionHasErrors('password');

        $this->assertSame(1, User::where('email', 'taken@example.com')->count());
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_accepting_an_invitation_enforces_the_password_policy(): void
    {
        $invitation = Invitation::factory()->for(Company::factory()->create())->admin()->create();

        $this->post("/invitations/{$invitation->token}", [
            'name' => 'Someone',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertNull($invitation->fresh()->accepted_at);
    }
}
