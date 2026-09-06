<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            // Slug is lowercase alphanumeric + hyphens (Requirement 1.6),
            // kept unique per generated row.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => Company::STATUS_ACTIVE,
            'fee_handling_mode' => Company::FEE_MODE_ABSORB,
            'company_fee_percent' => null,
            'stripe_account_id' => null,
            'stripe_charges_enabled' => false,
            'currency' => 'GBP',
            'primary_colour' => null,
            'logo_path' => null,
            'terms_text' => null,
            'ticket_field_defs' => null,
        ];
    }

    /**
     * Indicate that the Company is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Company::STATUS_SUSPENDED,
        ]);
    }
}
