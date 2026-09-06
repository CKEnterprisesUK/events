<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Feature: self-service password reset ("forgot password").
 *
 * Covers the four steps of the password-broker flow: request form renders, a
 * reset link is emailed for a real account, the reset form renders for a token,
 * and a valid token sets the new password. The request step is neutral about
 * whether an account exists.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_form_renders(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Forgot your password?');
    }

    public function test_login_page_links_to_forgot_password(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('password.request'));
    }

    public function test_a_reset_link_is_emailed_for_a_known_account(): void
    {
        Notification::fake();
        $user = User::factory()->owner()->create(['email' => 'reset-me@example.com']);

        $this->post(route('password.email'), ['email' => 'reset-me@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_request_is_neutral_for_an_unknown_email(): void
    {
        Notification::fake();

        // Same neutral confirmation, no notification, no error leak.
        $this->post(route('password.email'), ['email' => 'nobody@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_reset_form_renders_for_a_token(): void
    {
        $this->get(route('password.reset', ['token' => 'sometoken']).'?email=a@b.com')
            ->assertOk()
            ->assertSee('Choose a new password');
    }

    public function test_a_valid_token_resets_the_password(): void
    {
        $user = User::factory()->owner()->create([
            'email' => 'reset@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'reset@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_an_invalid_token_does_not_reset_the_password(): void
    {
        $user = User::factory()->owner()->create([
            'email' => 'reset2@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $this->post(route('password.update'), [
            'token' => 'totally-invalid-token',
            'email' => 'reset2@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }
}
