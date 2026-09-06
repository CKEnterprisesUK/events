<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * Define the model's default state.
     *
     * Defaults to a pending invitation for a freshly created Company assigning
     * the Admin role, with an unexpired window. The `role` is always one of the
     * invitable roles (Owner is not invitable, Requirement 4.6); use the
     * `accountant()`/`scanner()`/`accepted()`/`expired()` states or override
     * `company_id`/`role` for other shapes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => fake()->randomElement(User::INVITABLE_ROLES),
            'token' => Str::random(40),
            'accepted_at' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * An invitation assigning the Admin role.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ADMIN,
        ]);
    }

    /**
     * An invitation assigning the Accountant role.
     */
    public function accountant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ACCOUNTANT,
        ]);
    }

    /**
     * An invitation assigning the Scanner role.
     */
    public function scanner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_SCANNER,
        ]);
    }

    /**
     * An invitation that has already been accepted. (4.2)
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => now(),
        ]);
    }

    /**
     * An invitation past its expiry.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
