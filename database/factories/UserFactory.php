<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Defaults to a Company_User with the Admin role belonging to a freshly
     * created Company. Use the `owner()`, `admin()`, `boxOffice()`,
     * `accountant()`, `scanner()`, and `superAdmin()` states, or override
     * `company_id`/`role`,
     * for other shapes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'is_super_admin' => false,
            'role' => User::ROLE_ADMIN,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'last_activity_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * The single Owner of a Company. (Requirement 3.2)
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_OWNER,
        ]);
    }

    /**
     * A Company Admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ADMIN,
        ]);
    }

    /**
     * A Company Box_Office user (events/ticketing/orders, no company settings).
     */
    public function boxOffice(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_BOX_OFFICE,
        ]);
    }

    /**
     * A Company Accountant (read-only reports/payouts).
     */
    public function accountant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ACCOUNTANT,
        ]);
    }

    /**
     * A Company Scanner (check-in only).
     */
    public function scanner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_SCANNER,
        ]);
    }

    /**
     * A CK Enterprises Super_Admin: no Company, no Company role. (20.1)
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => null,
            'role' => null,
            'is_super_admin' => true,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
