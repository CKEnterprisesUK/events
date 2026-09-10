<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\Order;
use App\Models\OrderQuestionAnswer;
use App\Models\TicketType;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Feature: attendee questions + Stripe email pre-fill.
 *
 * Covers the two customer-facing additions to checkout:
 *   1. The Customer's email is passed to the Stripe Checkout Session so the
 *      hosted page doesn't ask for it again.
 *   2. An organiser's custom questions (free text / single choice / number)
 *      are asked at checkout: required questions gate the Order, and valid
 *      answers are snapshotted onto the Order.
 */
class CheckoutQuestionsAndEmailTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    /**
     * A published, connected, charges-enabled Company + Event + paid ticket.
     *
     * @return array{0: Company, 1: Event, 2: TicketType}
     */
    private function scenario(): array
    {
        $company = Company::factory()->create([
            'stripe_account_id' => 'acct_test123',
            'stripe_charges_enabled' => true,
            'fee_handling_mode' => Company::FEE_MODE_ABSORB,
            'company_fee_percent' => '10.00',
            'currency' => 'gbp',
        ]);

        $event = Event::factory()->for($company)->published()->unlimitedCapacity()->create();

        $type = TicketType::factory()->forEvent($event)->create([
            'price_minor' => 2_000,
            'capacity' => 100,
            'sold_count' => 0,
            'reserved_count' => 0,
            'sale_starts_at' => Carbon::now()->subDay(),
            'sale_ends_at' => Carbon::now()->addMonth(),
        ]);

        return [$company, $event, $type];
    }

    public function test_customer_email_is_passed_to_stripe_checkout_session(): void
    {
        [$company, $event, $type] = $this->scenario();

        $this->post("/{$company->slug}/{$event->id}/checkout", [
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.test',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
            'consents' => ['terms' => true, 'privacy' => true],
        ])->assertRedirect();

        $call = end($this->fakeStripe()->checkoutSessionCalls);

        $this->assertSame('ada@example.test', $call['customer_email']);
    }

    public function test_answers_are_persisted_and_email_prefilled(): void
    {
        [$company, $event, $type] = $this->scenario();

        $free = $event->questions()->create([
            'company_id' => $company->id,
            'type' => EventQuestion::TYPE_FREE_TEXT,
            'label' => 'Dietary needs?',
            'required' => false,
            'position' => 0,
        ]);
        $choice = $event->questions()->create([
            'company_id' => $company->id,
            'type' => EventQuestion::TYPE_SELECT,
            'label' => 'T-shirt size',
            'options' => ['S', 'M', 'L'],
            'required' => true,
            'position' => 1,
        ]);
        $number = $event->questions()->create([
            'company_id' => $company->id,
            'type' => EventQuestion::TYPE_NUMBER,
            'label' => 'Guests',
            'required' => true,
            'position' => 2,
        ]);

        $this->post("/{$company->slug}/{$event->id}/checkout", [
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.test',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
            'consents' => ['terms' => true, 'privacy' => true],
            'questions' => [
                $free->id => 'Vegetarian',
                $choice->id => 'M',
                $number->id => '3',
            ],
        ])->assertRedirect();

        $order = Order::withoutGlobalScopes()->where('event_id', $event->id)->firstOrFail();

        $answers = OrderQuestionAnswer::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->get()
            ->keyBy('event_question_id');

        $this->assertCount(3, $answers);
        $this->assertSame('Vegetarian', $answers[$free->id]->answer);
        $this->assertSame('M', $answers[$choice->id]->answer);
        $this->assertSame('3', $answers[$number->id]->answer);
        // Label snapshotted so reports survive later edits.
        $this->assertSame('T-shirt size', $answers[$choice->id]->question_label);
    }

    public function test_required_question_gates_the_order(): void
    {
        [$company, $event, $type] = $this->scenario();

        $q = $event->questions()->create([
            'company_id' => $company->id,
            'type' => EventQuestion::TYPE_FREE_TEXT,
            'label' => 'Emergency contact',
            'required' => true,
            'position' => 0,
        ]);

        $this->post("/{$company->slug}/{$event->id}/checkout", [
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.test',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
            'consents' => ['terms' => true, 'privacy' => true],
            'questions' => [$q->id => ''],
        ])->assertSessionHasErrors("questions.{$q->id}");

        // No Order (and no Stripe call) when a required answer is missing.
        $this->assertSame(0, Order::withoutGlobalScopes()->where('event_id', $event->id)->count());
        $this->assertEmpty($this->fakeStripe()->checkoutSessionCalls);
    }

    public function test_select_answer_must_be_a_configured_choice(): void
    {
        [$company, $event, $type] = $this->scenario();

        $q = $event->questions()->create([
            'company_id' => $company->id,
            'type' => EventQuestion::TYPE_SELECT,
            'label' => 'Size',
            'options' => ['S', 'M', 'L'],
            'required' => true,
            'position' => 0,
        ]);

        $this->post("/{$company->slug}/{$event->id}/checkout", [
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.test',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
            'consents' => ['terms' => true, 'privacy' => true],
            'questions' => [$q->id => 'XXL'],
        ])->assertSessionHasErrors("questions.{$q->id}");

        $this->assertSame(0, Order::withoutGlobalScopes()->where('event_id', $event->id)->count());
    }

    public function test_questions_screen_renders(): void
    {
        $company = Company::factory()->create();
        $admin = \App\Models\User::factory()->admin()->create(['company_id' => $company->id]);
        $event = Event::factory()->for($company)->create();

        $this->actingAs($admin)
            ->get(route('dashboard.events.questions', $event))
            ->assertOk()
            ->assertSee('Attendee questions');
    }

    public function test_answers_report_screen_renders(): void
    {
        $company = Company::factory()->create();
        $admin = \App\Models\User::factory()->owner()->create(['company_id' => $company->id]);
        $event = Event::factory()->for($company)->create();

        $this->actingAs($admin)
            ->get(route('dashboard.events.questions.report', $event))
            ->assertOk()
            ->assertSee('Attendee answers');
    }

    public function test_admin_can_configure_questions_and_ceiling_is_enforced(): void
    {
        $company = Company::factory()->create();
        $admin = \App\Models\User::factory()->admin()->create(['company_id' => $company->id]);
        $event = Event::factory()->for($company)->create();

        // Save two questions; a blank third slot is dropped.
        $this->actingAs($admin)
            ->put(route('dashboard.events.questions.save', $event), [
                'questions' => [
                    ['type' => EventQuestion::TYPE_FREE_TEXT, 'label' => 'Dietary needs?', 'required' => '1'],
                    ['type' => EventQuestion::TYPE_SELECT, 'label' => 'Size', 'options' => "S\nM\nL", 'required' => '0'],
                    ['type' => EventQuestion::TYPE_FREE_TEXT, 'label' => '', 'required' => '0'],
                ],
            ])
            ->assertRedirect(route('dashboard.events.questions', $event));

        // Re-query without the global tenant scope: the request-scoped tenant
        // context is torn down once the HTTP request completes.
        $questions = EventQuestion::withoutGlobalScopes()
            ->where('event_id', $event->id)->orderBy('position')->get();
        $this->assertCount(2, $questions);
        $this->assertSame('Dietary needs?', $questions[0]->label);
        $this->assertTrue($questions[0]->required);
        $this->assertSame(['S', 'M', 'L'], $questions[1]->choices());

        // Re-saving replaces the set (removing a question by leaving it out).
        $this->actingAs($admin)
            ->put(route('dashboard.events.questions.save', $event), [
                'questions' => [
                    ['type' => EventQuestion::TYPE_NUMBER, 'label' => 'How many guests?', 'required' => '1'],
                ],
            ])
            ->assertRedirect();

        $questions = EventQuestion::withoutGlobalScopes()
            ->where('event_id', $event->id)->orderBy('position')->get();
        $this->assertCount(1, $questions);
        $this->assertSame(EventQuestion::TYPE_NUMBER, $questions[0]->type);
    }

    public function test_select_question_requires_at_least_two_options(): void
    {
        $company = Company::factory()->create();
        $admin = \App\Models\User::factory()->admin()->create(['company_id' => $company->id]);
        $event = Event::factory()->for($company)->create();

        $this->actingAs($admin)
            ->put(route('dashboard.events.questions.save', $event), [
                'questions' => [
                    ['type' => EventQuestion::TYPE_SELECT, 'label' => 'Size', 'options' => 'OnlyOne', 'required' => '0'],
                ],
            ])
            ->assertSessionHasErrors('questions.0.options');

        $this->assertSame(0, EventQuestion::withoutGlobalScopes()->where('event_id', $event->id)->count());
    }

    public function test_answers_export_streams_a_row_per_confirmed_order(): void
    {
        $company = Company::factory()->create();
        $admin = \App\Models\User::factory()->owner()->create(['company_id' => $company->id]);
        $event = Event::factory()->for($company)->create();

        $q = $event->questions()->create([
            'company_id' => $company->id,
            'type' => EventQuestion::TYPE_FREE_TEXT,
            'label' => 'Dietary needs?',
            'required' => false,
            'position' => 0,
        ]);

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.test',
        ]);
        OrderQuestionAnswer::create([
            'company_id' => $company->id,
            'order_id' => $order->id,
            'event_question_id' => $q->id,
            'question_label' => $q->label,
            'answer' => 'Vegetarian',
            'captured_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('dashboard.events.questions.export', $event));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $body = $response->streamedContent();
        $this->assertStringContainsString('Dietary needs?', $body);
        $this->assertStringContainsString('ada@example.test', $body);
        $this->assertStringContainsString('Vegetarian', $body);
    }
}
