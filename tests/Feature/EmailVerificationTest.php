<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Email verification gate: a self-signed-up Owner is created at registration
 * but must verify their email before they can reach the dashboard or sign in.
 * Invited users are auto-verified on accept (the token proves email ownership).
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A valid self-signup payload for the given slug/email.
     *
     * @return array<string, string>
     */
    private function signupPayload(string $slug = 'acme-events', string $email = 'olivia@acme.test'): array
    {
        return [
            'company_name' => 'Acme Events',
            'slug' => $slug,
            'legal_name' => 'Acme Events Ltd',
            'organisation_type' => Company::TYPE_COMPANY,
            'company_number' => '01234567',
            'organisation_email' => 'hello@acme.test',
            'address_line_1' => '1 High Street',
            'city' => 'London',
            'postcode' => 'EC1A 1BB',
            'country' => 'gb',
            'name' => 'Olivia Owner',
            'email' => $email,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'agree_terms' => '1',
        ];
    }

    // ---- Registration --------------------------------------------------------

    public function test_registration_creates_an_unverified_owner_and_sends_verification(): void
    {
        Notification::fake();

        $this->post('/register', $this->signupPayload())
            ->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'olivia@acme.test')->firstOrFail();

        // Company + Owner exist immediately, but the Owner is not yet verified.
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertDatabaseHas('companies', ['slug' => 'acme-events']);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_dispatches_the_registered_event(): void
    {
        Event::fake();

        $this->post('/register', $this->signupPayload());

        Event::assertDispatched(Registered::class);
    }

    // ---- Dashboard gate ------------------------------------------------------

    public function test_unverified_user_cannot_reach_the_dashboard(): void
    {
        $user = User::factory()->owner()->unverified()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_verified_user_can_reach_the_dashboard(): void
    {
        $user = User::factory()->owner()->create(); // verified by default

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    // ---- Login gate ----------------------------------------------------------

    public function test_login_sends_an_unverified_user_to_the_notice(): void
    {
        $user = User::factory()->owner()->unverified()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect(route('verification.notice'));
    }

    public function test_login_lets_a_verified_user_through(): void
    {
        $user = User::factory()->owner()->create([
            'password' => Hash::make('secret-password'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect('/dashboard');
    }

    // ---- Verifying -----------------------------------------------------------

    public function test_signed_verification_link_verifies_the_user(): void
    {
        $user = User::factory()->owner()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
        );

        $this->actingAs($user)->get($url)->assertRedirect('/dashboard');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.email_verified']);
    }

    public function test_verification_notice_is_shown_to_an_authenticated_unverified_user(): void
    {
        $user = User::factory()->owner()->unverified()->create();

        $this->actingAs($user)->get(route('verification.notice'))
            ->assertOk()
            ->assertViewIs('auth.verify-email');
    }

    public function test_authenticated_user_can_resend_the_verification_email(): void
    {
        Notification::fake();
        $user = User::factory()->owner()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))
            ->assertRedirect();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    // ---- Invited users are auto-verified -------------------------------------

    public function test_invited_user_is_verified_on_accept(): void
    {
        $company = Company::factory()->create();
        $invitation = Invitation::factory()->for($company)->admin()->create([
            'email' => 'invitee@example.com',
        ]);

        $this->post("/invitations/{$invitation->token}", [
            'name' => 'Invited Admin',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect('/dashboard');

        $user = User::where('email', 'invitee@example.com')->firstOrFail();

        // Accepting proves email ownership, so the account is verified and can
        // reach the dashboard without a verification round-trip.
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasVerifiedEmail());
    }
}
