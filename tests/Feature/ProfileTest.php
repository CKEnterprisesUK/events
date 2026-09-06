<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature: self-service account profile.
 *
 * Any authenticated Company_User may view and edit their OWN name/email and
 * change their password (current password required, new password confirmed).
 * Guests are redirected to login.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_their_profile(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('dashboard.profile.edit'))->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard.profile.edit'))->assertRedirect(route('login'));
    }

    public function test_user_can_update_name_and_email(): void
    {
        $user = User::factory()->admin()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

        $this->actingAs($user)->put(route('dashboard.profile.update'), [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ])->assertRedirect(route('dashboard.profile.edit'));

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
    }

    public function test_email_must_be_unique_across_users(): void
    {
        $taken = User::factory()->admin()->create(['email' => 'taken@example.com']);
        $user = User::factory()->admin()->create(['email' => 'me@example.com']);

        $this->actingAs($user)->put(route('dashboard.profile.update'), [
            'name' => 'Me',
            'email' => 'taken@example.com',
        ])->assertSessionHasErrors('email');

        $this->assertSame('me@example.com', $user->fresh()->email);
    }

    public function test_user_can_change_password_with_correct_current_password(): void
    {
        $user = User::factory()->admin()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)->put(route('dashboard.profile.password'), [
            'current_password' => 'old-password',
            'password' => 'new-strong-password',
            'password_confirmation' => 'new-strong-password',
        ])->assertRedirect(route('dashboard.profile.edit'));

        $this->assertTrue(Hash::check('new-strong-password', $user->fresh()->password));
    }

    public function test_password_change_requires_the_correct_current_password(): void
    {
        $user = User::factory()->admin()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)->put(route('dashboard.profile.password'), [
            'current_password' => 'wrong-password',
            'password' => 'new-strong-password',
            'password_confirmation' => 'new-strong-password',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_new_password_must_be_confirmed(): void
    {
        $user = User::factory()->admin()->create(['password' => Hash::make('old-password')]);

        $this->actingAs($user)->put(route('dashboard.profile.password'), [
            'current_password' => 'old-password',
            'password' => 'new-strong-password',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }
}
