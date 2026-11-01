<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * Define the model's default state.
     *
     * Defaults to an unpublished Event belonging to a freshly created Company,
     * with an overall capacity set. Use the `published()`/`unpublished()`
     * states, or override `company_id`/`capacity`, for other shapes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $event = fake()->randomElement(self::CHARITY_EVENTS);

        return [
            'company_id' => Company::factory(),
            'name' => $event['name'],
            'description' => $event['description'],
            'venue' => fake()->randomElement(self::VENUES),
            'starts_at' => fake()->dateTimeBetween('+1 week', '+3 months'),
            'capacity' => fake()->numberBetween(10, 1000),
            'is_published' => false,
            'primary_colour' => null,
            'logo_path' => null,
        ];
    }

    /**
     * A pool of believable UK charity fundraising events, each with a matching
     * description. Used so seeded/preprod data reads like real community
     * fundraisers (bonfires, cake sales, quiz nights, etc.) rather than faker
     * lorem-ipsum. Keep the names human and the descriptions short.
     *
     * @var list<array{name: string, description: string}>
     */
    public const CHARITY_EVENTS = [
        ['name' => 'Autumn Bonfire & Fireworks Night', 'description' => 'Join us for our annual bonfire and fireworks display. Hot dogs, mulled cider and a roaring fire, with all proceeds going to the local children\'s hospice.'],
        ['name' => 'Summer Charity Cake Sale', 'description' => 'Homemade bakes, brownies and a Victoria sponge or two. Every slice helps fund our community food bank. Bring your sweet tooth and your spare change.'],
        ['name' => 'Pub Quiz Night for Cancer Research', 'description' => 'Grab a team of up to six and test your trivia across six rounds. Prizes for the winners, a raffle at half time, and a good cause all round.'],
        ['name' => 'Spring Charity Fun Run 5K', 'description' => 'A friendly 5K around the park for all abilities. Walk it, jog it or sprint it. Medals for every finisher and funds raised for the air ambulance.'],
        ['name' => 'Christmas Carol Concert', 'description' => 'An evening of festive carols by candlelight with the community choir. Mince pies and mulled wine served in the interval. In aid of the night shelter.'],
        ['name' => 'Charity Dog Show & Fun Day', 'description' => 'Bring your four-legged friend along for waggiest tail, best rescue and prettiest pup. Stalls, games and a tombola, all for the local animal rescue.'],
        ['name' => 'Village Summer Fete', 'description' => 'The classic village fete returns with a coconut shy, bottle stall, cream teas and the ever-popular welly wanging. Proceeds support the parish hall roof fund.'],
        ['name' => 'Charity Golf Day', 'description' => 'Eighteen holes in a team stableford, followed by a two-course dinner and prize giving. Raising money for the regional stroke association.'],
        ['name' => 'Jumble Sale & Bric-a-Brac', 'description' => 'One person\'s clutter is another\'s treasure. Clothes, books, toys and the odd hidden gem. All donations and takings go to the hospice shop.'],
        ['name' => 'Race Night Fundraiser', 'description' => 'Back the horses on the big screen, place your bets and cheer them home. A lively night out with a licensed bar, raising funds for the scout group.'],
        ['name' => 'Charity Gala Dinner & Auction', 'description' => 'A black-tie evening with a three-course meal, live music and a charity auction of money-can\'t-buy lots. In support of the children\'s ward appeal.'],
        ['name' => 'Sponsored Coastal Walk', 'description' => 'A ten-mile sponsored walk along the clifftops with stunning sea views. Sturdy boots recommended. Every step raises money for mental health services.'],
    ];

    /**
     * Believable UK venues for community fundraising events.
     *
     * @var list<string>
     */
    public const VENUES = [
        'St Mary\'s Church Hall, High Street, Marlow, SL7 1AU',
        'The Memorial Recreation Ground, Park Road, Harrogate, HG1 2TY',
        'Rotary Club House, Market Square, Devizes, SN10 1JG',
        'Oakfield Primary School, School Lane, Chesterfield, S40 3PX',
        'The Royal British Legion Hall, Station Road, Bury St Edmunds, IP33 1AA',
        'Riverside Community Centre, Mill Lane, Shrewsbury, SY1 1PF',
        'The Village Green, Church End, Steeple Bumpstead, CB9 7DD',
        'Grange Park Golf Club, Links Road, Scarborough, YO12 4TY',
        'The Town Hall, Corporation Street, Taunton, TA1 4AQ',
        'Westbrook Scout Hut, Woodland Avenue, Warrington, WA5 8TE',
    ];

    /**
     * A published Event whose page is available to Customers. (5.4)
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
        ]);
    }

    /**
     * An unpublished Event that blocks Customer view/purchase. (5.5)
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => false,
        ]);
    }

    /**
     * An Event with no overall capacity limit. (5.2)
     */
    public function unlimitedCapacity(): static
    {
        return $this->state(fn (array $attributes) => [
            'capacity' => null,
        ]);
    }
}
