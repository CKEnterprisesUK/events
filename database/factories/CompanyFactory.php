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
     * Believable names for the kinds of organisations that run community
     * fundraising events — charities, clubs, PTAs and community groups — so
     * seeded companies read like real organisers rather than faker corporate
     * names. There are enough here to cover the preprod dataset without the
     * factory's `unique()` modifier running dry.
     *
     * @var list<string>
     */
    public const ORGANISATION_NAMES = [
        'Hope & Hearts Children\'s Hospice',
        'Marlow Rotary Club',
        'St Mary\'s Church PTA',
        'Riverside Community Trust',
        'Paws & Claws Animal Rescue',
        'Harrogate Round Table',
        'The Oakfield School Association',
        'Shrewsbury Lions Club',
        'Helping Hands Food Bank',
        'Devizes Scout Group',
        'The Air Ambulance Appeal',
        'Taunton Stroke Support Group',
        'Bright Futures Youth Charity',
        'The Village Hall Committee',
        'Coastal Mind Wellbeing',
        'Age Together Befriending',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(self::ORGANISATION_NAMES);

        return [
            'name' => $name,
            // Registered legal name captured at signup; defaults to the working
            // name here. Trading name left null (defaults to the legal name).
            'legal_name' => $name,
            'trading_name' => null,
            'organisation_type' => Company::TYPE_COMPANY,
            'company_number' => null,
            'charity_number' => null,
            'website' => null,
            'email' => fake()->unique()->companyEmail(),
            'phone' => null,
            'address_line_1' => fake()->streetAddress(),
            'address_line_2' => null,
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
            'country' => 'GB',
            // Slug is lowercase alphanumeric + hyphens (Requirement 1.6),
            // kept unique per generated row.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => Company::STATUS_ACTIVE,
            'fee_handling_mode' => Company::FEE_MODE_PASS_ON,
            'company_fee_percent' => null,
            'stripe_account_id' => null,
            'stripe_charges_enabled' => false,
            'currency' => 'GBP',
            'primary_colour' => null,
            'logo_path' => null,
            'terms_text' => null,
        ];
    }

    /**
     * Indicate the Company has a connected Stripe account with charges enabled,
     * so it can take card payments (and therefore publish Events selling paid
     * tickets). Mirrors the {@see Company::canAcceptPayments()} predicate.
     */
    public function stripeReady(): static
    {
        return $this->state(fn (array $attributes) => [
            'stripe_account_id' => 'acct_'.fake()->unique()->bothify('##########'),
            'stripe_charges_enabled' => true,
        ]);
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

    /**
     * Indicate that the Company is pending verification.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Company::STATUS_PENDING,
        ]);
    }

    /**
     * Indicate that the Company is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Company::STATUS_CLOSED,
        ]);
    }

    /**
     * Indicate that the Company is a registered charity, with a Charity
     * Commission number instead of a Companies House number.
     */
    public function charity(): static
    {
        return $this->state(fn (array $attributes) => [
            'organisation_type' => Company::TYPE_CHARITY,
            'charity_number' => (string) fake()->numberBetween(1000000, 9999999),
            'company_number' => null,
        ]);
    }
}
