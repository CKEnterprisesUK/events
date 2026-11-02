<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\QrService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a self-contained demo tenant for Apple App Review / TestFlight on
 * PRODUCTION, without touching any real data.
 *
 * What it creates (all under one clearly-labelled demo Company):
 *   - 1 Company: "App Review Demo Co" (slug `app-review-demo`).
 *   - 3 users sharing ONE password, each a different role so the reviewer can
 *     see role-based tab visibility (owner, box_office, scanner). Each is
 *     linked to the Company via BOTH `users.company_id` AND the `company_user`
 *     pivot (the iOS API reads memberships from the pivot).
 *   - 1 published event with two ticket types.
 *   - 2 confirmed bookings (orders) with tickets, one left unscanned so the
 *     reviewer can perform a live check-in, one pre-scanned so they can see the
 *     "already scanned" path.
 *
 * It is IDEMPOTENT: re-running updates the same rows (matched by the demo
 * emails / slug) rather than duplicating them.
 *
 * Safety:
 *   - Everything is namespaced to the demo Company/emails, so it never reads or
 *     mutates real customer/company rows.
 *   - It uses the real Eloquent models (correct hashing, casts, relations, and
 *     the company_user pivot), so the data is internally consistent and the
 *     scanner's HMAC-backed QR verifies.
 *   - Wrapped in a transaction: any failure rolls the whole thing back.
 *
 * Run on production (one-off):
 *     php artisan db:seed --class=Database\\Seeders\\AppReviewDemoSeeder --force
 *
 * Tear down afterwards (see down-style note at the bottom), or just leave it;
 * it is harmless and isolated.
 */
class AppReviewDemoSeeder extends Seeder
{
    /** One password for every demo account, printed at the end. */
    private const DEMO_PASSWORD = 'AppReview2026!';

    private const COMPANY_SLUG = 'app-review-demo';

    /**
     * Remove everything this seeder created, and nothing else. Safe to run on
     * production: it only ever deletes rows scoped to the demo Company (matched
     * by its slug), so real tenants are never touched. Returns a short summary
     * string of what was removed. Idempotent — a no-op when nothing is present.
     */
    public static function teardown(): string
    {
        return DB::transaction(function (): string {
            /** @var Company|null $company */
            $company = Company::withoutGlobalScopes()->where('slug', self::COMPANY_SLUG)->first();

            if ($company === null) {
                return 'Nothing to remove: the App Review demo company does not exist.';
            }

            // Delete children first (defensive — FKs may or may not cascade).
            $orderIds = Order::withoutGlobalScopes()->where('company_id', $company->id)->pluck('id');
            $tickets = Ticket::withoutGlobalScopes()->whereIn('order_id', $orderIds)->delete();
            $orders = Order::withoutGlobalScopes()->where('company_id', $company->id)->delete();
            $ticketTypes = TicketType::withoutGlobalScopes()->where('company_id', $company->id)->delete();
            $events = Event::withoutGlobalScopes()->where('company_id', $company->id)->delete();

            // Users: detach the membership pivot, then delete the demo users
            // (matched by their demo emails so we never touch a real account).
            $users = User::where('company_id', $company->id)
                ->where('email', 'like', '%@app-review-demo.test')
                ->get();
            foreach ($users as $user) {
                $user->companies()->detach($company->id);
            }
            $userCount = $users->count();
            User::whereIn('id', $users->pluck('id'))->delete();

            $company->delete();

            return "Removed App Review demo data: {$userCount} users, {$events} event(s), "
                ."{$ticketTypes} ticket type(s), {$orders} order(s), {$tickets} ticket(s), 1 company.";
        });
    }

    public function run(): void
    {
        DB::transaction(function (): void {
            $company = $this->company();
            $users = $this->users($company);
            $event = $this->event($company);
            [$general, $vip] = $this->ticketTypes($company, $event);

            // A confirmed, UNSCANNED order the reviewer can check in live.
            $unscanned = $this->order($company, $event, [
                'customer_name' => 'Demo Attendee One',
                'customer_email' => 'attendee1@app-review-demo.test',
            ], [$general->id => 2]);

            // A confirmed order already scanned in, so the reviewer sees the
            // "already scanned" outcome too. Attributed to the scanner user.
            $scanned = $this->order($company, $event, [
                'customer_name' => 'Demo Attendee Two',
                'customer_email' => 'attendee2@app-review-demo.test',
            ], [$vip->id => 1], scannedBy: $users['scanner']->id);

            $this->printInstructions($company, $users, $event, $unscanned, $scanned);
        });
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = Company::withoutGlobalScopes()->updateOrCreate(
            ['slug' => self::COMPANY_SLUG],
            [
                'name' => 'App Review Demo Co',
                'legal_name' => 'App Review Demo Co',
                'organisation_type' => Company::TYPE_OTHER,
                'status' => Company::STATUS_ACTIVE,
                'country' => 'GB',
                'currency' => 'GBP',
                // Demo tenant does not take real payments; leave Stripe empty.
            ],
        );

        return $company;
    }

    /**
     * Three users sharing one password, each a different role. Linked via the
     * `company_user` pivot (what the iOS API reads) AND `users.company_id`.
     *
     * @return array{owner: User, box_office: User, scanner: User}
     */
    private function users(Company $company): array
    {
        $defs = [
            'owner' => ['email' => 'owner@app-review-demo.test', 'name' => 'Demo Owner', 'role' => User::ROLE_OWNER],
            'box_office' => ['email' => 'boxoffice@app-review-demo.test', 'name' => 'Demo Box Office', 'role' => User::ROLE_BOX_OFFICE],
            'scanner' => ['email' => 'scanner@app-review-demo.test', 'name' => 'Demo Scanner', 'role' => User::ROLE_SCANNER],
        ];

        $users = [];

        foreach ($defs as $key => $def) {
            /** @var User $user */
            $user = User::updateOrCreate(
                ['email' => $def['email']],
                [
                    'company_id' => $company->id,
                    'role' => $def['role'],
                    'name' => $def['name'],
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'email_verified_at' => now(),
                    'agreed_to_terms_at' => now(),
                ],
            );

            // Membership pivot the API scopes tokens to. syncWithoutDetaching so
            // re-runs don't duplicate, and the pivot role stays correct.
            $user->companies()->syncWithoutDetaching([
                $company->id => ['role' => $def['role']],
            ]);

            $users[$key] = $user;
        }

        return $users;
    }

    private function event(Company $company): Event
    {
        /** @var Event $event */
        $event = Event::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'name' => 'App Review Demo Event'],
            [
                'description' => 'A demo event for App Review. Check attendees in from the Scan tab.',
                'venue' => 'Demo Venue, London',
                'starts_at' => Carbon::now()->addDays(14)->setTime(19, 0),
                'capacity' => 200,
                'is_published' => true,
                'location_mode' => Event::LOCATION_IN_PERSON,
                'address' => '1 Demo Street, London',
            ],
        );

        return $event;
    }

    /**
     * Two ticket types. Returns [general, vip].
     *
     * @return array{0: TicketType, 1: TicketType}
     */
    private function ticketTypes(Company $company, Event $event): array
    {
        /** @var TicketType $general */
        $general = TicketType::withoutGlobalScopes()->updateOrCreate(
            ['event_id' => $event->id, 'name' => 'General Admission'],
            [
                'company_id' => $company->id,
                'price_minor' => 1000, // £10.00
                'capacity' => 150,
                'capacity_mode' => TicketType::MODE_CAPPED,
            ],
        );

        /** @var TicketType $vip */
        $vip = TicketType::withoutGlobalScopes()->updateOrCreate(
            ['event_id' => $event->id, 'name' => 'VIP'],
            [
                'company_id' => $company->id,
                'price_minor' => 2500, // £25.00
                'capacity' => 50,
                'capacity_mode' => TicketType::MODE_CAPPED,
            ],
        );

        return [$general, $vip];
    }

    /**
     * Create (or refresh) a confirmed order with tickets.
     *
     * @param  array{customer_name: string, customer_email: string}  $customer
     * @param  array<int, int>  $lines  ticketTypeId => quantity (keys are TicketType ids via spl cast)
     */
    private function order(Company $company, Event $event, array $customer, array $lines, ?int $scannedBy = null): Order
    {
        // $lines is [ticketTypeId => quantity].
        $byId = $lines;

        $subtotal = 0;
        foreach ($byId as $typeId => $qty) {
            $subtotal += (int) TicketType::withoutGlobalScopes()->find($typeId)?->price_minor * $qty;
        }

        /** @var Order $order */
        $order = Order::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'event_id' => $event->id, 'customer_email' => $customer['customer_email']],
            [
                'order_reference' => $this->referenceFor($customer['customer_email']),
                'customer_name' => $customer['customer_name'],
                'status' => Order::STATUS_PAID,
                'ticket_subtotal_minor' => $subtotal,
                'order_total_minor' => $subtotal,
                'fee_handling_mode' => Company::FEE_MODE_ABSORB,
                'fulfilled_at' => now(),
                'scanned_at' => $scannedBy !== null ? now() : null,
                'scanned_by' => $scannedBy,
            ],
        );

        // Rebuild tickets idempotently: clear any prior demo tickets for this
        // order, then create the requested lines.
        Ticket::withoutGlobalScopes()->where('order_id', $order->id)->delete();

        foreach ($byId as $typeId => $qty) {
            for ($i = 0; $i < $qty; $i++) {
                Ticket::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'order_id' => $order->id,
                    'ticket_type_id' => $typeId,
                ]);
            }
        }

        return $order;
    }

    /**
     * A deterministic 12-char uppercase [A-Z0-9] reference derived from the
     * email, so re-runs reuse the same reference (and the same QR stays valid).
     */
    private function referenceFor(string $email): string
    {
        return strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', sha1('app-review:'.$email)), 0, 12));
    }

    private function printInstructions(Company $company, array $users, Event $event, Order $unscanned, Order $scanned): void
    {
        $qr = app(QrService::class);

        $line = str_repeat('=', 68);
        $this->command?->getOutput()->writeln('');
        $this->command?->getOutput()->writeln($line);
        $this->command?->getOutput()->writeln('  APP REVIEW DEMO DATA SEEDED');
        $this->command?->getOutput()->writeln($line);
        $this->command?->getOutput()->writeln("  Company: {$company->name}  (slug: {$company->slug}, id: {$company->id})");
        $this->command?->getOutput()->writeln('  Shared password for ALL accounts: '.self::DEMO_PASSWORD);
        $this->command?->getOutput()->writeln('  Accounts:');
        foreach ($users as $user) {
            $this->command?->getOutput()->writeln("    - {$user->email}  (role: {$user->role})");
        }
        $this->command?->getOutput()->writeln("  Event: {$event->name}  (id: {$event->id}, published)");
        $this->command?->getOutput()->writeln('');
        $this->command?->getOutput()->writeln('  SCAN THIS to check someone in (unscanned order '.$unscanned->order_reference.'):');
        $this->command?->getOutput()->writeln('    QR payload: '.$qr->payloadFor($unscanned));
        $this->command?->getOutput()->writeln('  Already-scanned order (for the "already scanned" path): '.$scanned->order_reference);
        $this->command?->getOutput()->writeln('    QR payload: '.$qr->payloadFor($scanned));
        $this->command?->getOutput()->writeln('');
        $this->command?->getOutput()->writeln('  Tip: render a QR of the payload above (any QR generator) for the reviewer,');
        $this->command?->getOutput()->writeln('  or email the ticket from the dashboard so it carries the real QR.');
        $this->command?->getOutput()->writeln($line);
        $this->command?->getOutput()->writeln('');
    }
}
