<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds, drives and removes the synthetic door-load fixture used to stress
 * test the scan + live-dashboard endpoints from the Super_Admin "Stress test"
 * page.
 *
 * Everything lives under ONE clearly-labelled Company (slug `stress-test`), so
 * it never reads or mutates real tenants and can be removed in one call:
 *
 *   - 10 Scanner users (`scanner01@stress-test.test` …), each a member of the
 *     Company via BOTH `users.company_id` and the `company_user` pivot (the API
 *     reads memberships from the pivot).
 *   - 1 published, already-started Event with two Ticket_Types.
 *   - N confirmed, unscanned Orders (1–3 valid Tickets each) with deterministic
 *     references `STRESS000001` … so every Order has a scannable QR payload.
 *
 * Orders and Tickets are bulk-inserted (chunked) rather than created through
 * Eloquent, so thousands of rows seed inside a shared-host web request. The few
 * structural rows (Company, Event, Ticket_Types, Users) still go through
 * Eloquent so casts, slugs and relations stay correct.
 *
 * NON-PRODUCTION ONLY. Every public mutating method refuses unless APP_ENV is in
 * {@see ALLOWED_ENVIRONMENTS} (an allow-list, so a missing or misconfigured
 * APP_ENV fails safe), the same list the destructive pre-prod reseed uses.
 */
class StressTestFixture
{
    /**
     * Environments the fixture may run in. Never includes `production`.
     *
     * @var list<string>
     */
    public const ALLOWED_ENVIRONMENTS = ['local', 'staging', 'preprod', 'development'];

    public const COMPANY_SLUG = 'stress-test';

    public const EMAIL_DOMAIN = '@stress-test.test';

    /** Prefix of every seeded Order_Reference (12 chars total: prefix + 6 digits). */
    public const REFERENCE_PREFIX = 'STRESS';

    public const SCANNER_COUNT = 10;

    public const MIN_ORDERS = 100;

    public const MAX_ORDERS = 20000;

    public const DEFAULT_ORDERS = 4000;

    /** Lifetime of the tokens minted for a run. */
    private const TOKEN_LIFETIME_HOURS = 4;

    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly QrService $qr,
        private readonly RoleAuthorization $roles,
    ) {}

    /**
     * Whether the current APP_ENV may run the fixture.
     */
    public static function isAllowedHere(): bool
    {
        return app()->environment(self::ALLOWED_ENVIRONMENTS);
    }

    /**
     * Remove any previous fixture, then build a fresh one with `$orders`
     * confirmed, unscanned Orders.
     *
     * @return array{company_id: int, event_id: int, scanners: int, orders: int, tickets: int}
     */
    public function seed(int $orders): array
    {
        $this->guard();

        $orders = max(self::MIN_ORDERS, min(self::MAX_ORDERS, $orders));

        return DB::transaction(function () use ($orders): array {
            $this->removeAll();

            $company = $this->createCompany();
            $this->createScanners($company);
            $event = $this->createEvent($company);

            /** @var TicketType $general */
            $general = $this->createTicketType($company, $event, 'General Admission', 1000);
            /** @var TicketType $vip */
            $vip = $this->createTicketType($company, $event, 'VIP', 2500);

            $tickets = $this->bulkCreateOrders($company, $event, $general, $vip, $orders);

            return [
                'company_id' => (int) $company->id,
                'event_id' => (int) $event->id,
                'scanners' => self::SCANNER_COUNT,
                'orders' => $orders,
                'tickets' => $tickets,
            ];
        });
    }

    /**
     * Mint a fresh set of device-bound scanner tokens and the scannable
     * payloads for every still-unscanned Order. Previous stress tokens are
     * revoked first so tokens never accumulate across runs.
     *
     * @return array{
     *     event_id: int,
     *     scanners: list<array{name: string, token: string, device_id: string}>,
     *     codes: list<string>
     * }
     */
    public function issueSession(): array
    {
        $this->guard();

        $company = $this->company();

        if ($company === null) {
            throw new RuntimeException('No stress-test fixture exists yet. Seed it first.');
        }

        $event = Event::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->first();

        if ($event === null) {
            throw new RuntimeException('The stress-test event is missing. Re-seed the fixture.');
        }

        $scanners = $this->scannerUsers($company);
        $abilities = $this->roles->permittedActions(User::ROLE_SCANNER);
        $expiresAt = Carbon::now()->addHours(self::TOKEN_LIFETIME_HOURS);

        $this->revokeTokens($scanners->pluck('id')->all());

        $sessions = [];

        foreach ($scanners->values() as $index => $scanner) {
            $deviceId = sprintf('stress-device-%02d', $index + 1);

            $token = $scanner->createToken(name: $deviceId, abilities: $abilities, expiresAt: $expiresAt);

            // Same binding AuthController::issueToken() applies, plus the
            // Active_Company a real operator selects after sign-in.
            $token->accessToken->forceFill([
                'device_id' => $deviceId,
                'current_company_id' => $company->id,
                'expires_at' => $expiresAt,
            ])->save();

            $sessions[] = [
                'name' => (string) $scanner->name,
                'token' => $token->plainTextToken,
                'device_id' => $deviceId,
            ];
        }

        $codes = DB::table('orders')
            ->where('company_id', $company->id)
            ->whereNull('scanned_at')
            ->orderBy('id')
            ->pluck('order_reference')
            ->map(fn (string $reference): string => $this->qr->payload($reference))
            ->all();

        return [
            'event_id' => (int) $event->id,
            'scanners' => $sessions,
            'codes' => $codes,
        ];
    }

    /**
     * Clear every check-in and scan breadcrumb so the same Orders can be
     * scanned again by the next stage. Returns how many Orders were reset.
     */
    public function resetScans(): int
    {
        $this->guard();

        $company = $this->company();

        if ($company === null) {
            return 0;
        }

        $reset = DB::table('orders')
            ->where('company_id', $company->id)
            ->whereNotNull('scanned_at')
            ->update(['scanned_at' => null, 'scanned_by' => null]);

        DB::table('scan_events')->where('company_id', $company->id)->delete();

        return $reset;
    }

    /**
     * Remove the fixture and everything it created. Idempotent.
     */
    public function teardown(): string
    {
        $this->guard();

        return DB::transaction(fn (): string => $this->removeAll());
    }

    /**
     * Counts for the page header. Safe to call in any environment (read-only).
     *
     * @return array{seeded: bool, event_id: ?int, orders: int, tickets: int, scanned: int, scan_events: int}
     */
    public function status(): array
    {
        $company = $this->company();

        if ($company === null) {
            return ['seeded' => false, 'event_id' => null, 'orders' => 0, 'tickets' => 0, 'scanned' => 0, 'scan_events' => 0];
        }

        $orders = DB::table('orders')->where('company_id', $company->id);

        return [
            'seeded' => true,
            'event_id' => Event::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->value('id'),
            'orders' => (clone $orders)->count(),
            'tickets' => DB::table('tickets')->where('company_id', $company->id)->count(),
            'scanned' => (clone $orders)->whereNotNull('scanned_at')->count(),
            'scan_events' => DB::table('scan_events')->where('company_id', $company->id)->count(),
        ];
    }

    private function guard(): void
    {
        if (! self::isAllowedHere()) {
            throw new RuntimeException(
                'The stress-test fixture only runs when APP_ENV is one of: '
                .implode(', ', self::ALLOWED_ENVIRONMENTS).' (current: '.app()->environment().').',
            );
        }
    }

    private function company(): ?Company
    {
        return Company::withoutGlobalScopes()->where('slug', self::COMPANY_SLUG)->first();
    }

    private function createCompany(): Company
    {
        /** @var Company $company */
        $company = Company::withoutGlobalScopes()->create([
            'slug' => self::COMPANY_SLUG,
            'name' => 'Stress Test Co',
            'legal_name' => 'Stress Test Co',
            'organisation_type' => Company::TYPE_OTHER,
            'status' => Company::STATUS_ACTIVE,
            'country' => 'GB',
            'currency' => 'GBP',
        ]);

        return $company;
    }

    /**
     * Ten Scanner users sharing one unusable random password (nobody signs in
     * as these; tokens are minted directly).
     */
    private function createScanners(Company $company): void
    {
        $password = Hash::make(Str::random(40));

        for ($n = 1; $n <= self::SCANNER_COUNT; $n++) {
            /** @var User $user */
            $user = User::create([
                'company_id' => $company->id,
                'role' => User::ROLE_SCANNER,
                'name' => sprintf('Stress Scanner %02d', $n),
                'email' => sprintf('scanner%02d%s', $n, self::EMAIL_DOMAIN),
                'password' => $password,
                'agreed_to_terms_at' => now(),
            ]);

            $user->companies()->syncWithoutDetaching([
                $company->id => ['role' => User::ROLE_SCANNER],
            ]);
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function scannerUsers(Company $company)
    {
        return User::query()
            ->where('company_id', $company->id)
            ->where('email', 'like', '%'.self::EMAIL_DOMAIN)
            ->orderBy('email')
            ->get();
    }

    private function createEvent(Company $company): Event
    {
        /** @var Event $event */
        $event = Event::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'name' => 'Stress Test Event',
            'description' => 'Synthetic event used to load test the door scanners and live dashboard.',
            'venue' => 'Stress Test Arena',
            // Already started, so the live dashboard is past its countdown.
            'starts_at' => Carbon::now()->subHour(),
            'capacity' => self::MAX_ORDERS * 3,
            'is_published' => true,
            'location_mode' => Event::LOCATION_IN_PERSON,
            'address' => '1 Test Street, London',
        ]);

        return $event;
    }

    private function createTicketType(Company $company, Event $event, string $name, int $priceMinor): TicketType
    {
        /** @var TicketType $type */
        $type = TicketType::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'event_id' => $event->id,
            'name' => $name,
            'price_minor' => $priceMinor,
            'capacity' => self::MAX_ORDERS * 3,
            'capacity_mode' => TicketType::MODE_CAPPED,
        ]);

        return $type;
    }

    /**
     * Bulk-insert `$count` confirmed, unscanned Orders and their valid Tickets.
     * Every fifth Order is VIP; quantities cycle 1–3. Returns the Ticket count.
     */
    private function bulkCreateOrders(Company $company, Event $event, TicketType $general, TicketType $vip, int $count): int
    {
        $now = now();
        $plan = [];
        $orderRows = [];

        for ($n = 1; $n <= $count; $n++) {
            $reference = self::REFERENCE_PREFIX.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            $type = $n % 5 === 0 ? $vip : $general;
            $quantity = 1 + ($n % 3);
            $total = (int) $type->price_minor * $quantity;

            $plan[$reference] = [$type->id, $quantity];

            $orderRows[] = [
                'company_id' => $company->id,
                'event_id' => $event->id,
                'order_reference' => $reference,
                'customer_name' => 'Stress Attendee '.$n,
                'customer_email' => 'attendee'.$n.self::EMAIL_DOMAIN,
                'status' => Order::STATUS_PAID,
                'ticket_subtotal_minor' => $total,
                'booking_fee_minor' => 0,
                'application_fee_minor' => 0,
                'order_total_minor' => $total,
                'fee_handling_mode' => Company::FEE_MODE_ABSORB,
                'fulfilled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($orderRows, self::INSERT_CHUNK) as $chunk) {
            DB::table('orders')->insert($chunk);
        }

        $orderIds = DB::table('orders')
            ->where('company_id', $company->id)
            ->pluck('id', 'order_reference');

        $ticketRows = [];
        $tickets = 0;

        foreach ($plan as $reference => [$typeId, $quantity]) {
            for ($i = 0; $i < $quantity; $i++) {
                $ticketRows[] = [
                    'company_id' => $company->id,
                    'order_id' => $orderIds[$reference],
                    'ticket_type_id' => $typeId,
                    'status' => Ticket::STATUS_VALID,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $tickets++;
            }
        }

        foreach (array_chunk($ticketRows, self::INSERT_CHUNK) as $chunk) {
            DB::table('tickets')->insert($chunk);
        }

        return $tickets;
    }

    /**
     * @param  list<int>  $userIds
     */
    private function revokeTokens(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $userIds)
            ->delete();
    }

    /**
     * Delete everything scoped to the stress-test Company. Children first, as
     * the FKs may or may not cascade. Matched by slug / email domain only, so a
     * real tenant is never touched.
     */
    private function removeAll(): string
    {
        $company = $this->company();

        if ($company === null) {
            return 'Nothing to remove: no stress-test fixture exists.';
        }

        $userIds = User::query()
            ->where('company_id', $company->id)
            ->where('email', 'like', '%'.self::EMAIL_DOMAIN)
            ->pluck('id')
            ->all();

        $this->revokeTokens($userIds);

        $scanEvents = DB::table('scan_events')->where('company_id', $company->id)->delete();
        $tickets = DB::table('tickets')->where('company_id', $company->id)->delete();
        $orders = DB::table('orders')->where('company_id', $company->id)->delete();
        $types = DB::table('ticket_types')->where('company_id', $company->id)->delete();
        $events = DB::table('events')->where('company_id', $company->id)->delete();

        if ($userIds !== []) {
            DB::table('company_user')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
        }

        DB::table('companies')->where('id', $company->id)->delete();

        return 'Removed stress-test fixture: '.count($userIds)." users, {$events} event(s), {$types} ticket type(s), "
            ."{$orders} order(s), {$tickets} ticket(s), {$scanEvents} scan event(s), 1 company.";
    }
}
