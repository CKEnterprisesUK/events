<?php

namespace Tests\Feature;

use App\Http\Controllers\SuperAdmin\StressTestController;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\StressTestFixture;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The Super_Admin door-load stress-test tool: the synthetic fixture
 * ({@see StressTestFixture}) and the page/endpoints that drive it
 * ({@see StressTestController}).
 *
 * The fixture only runs in allow-listed pre-prod environments, so tests that
 * exercise it flip the application environment to `staging` (the suite itself
 * runs as `testing`).
 *
 * The important behaviour is that what the fixture mints really works against
 * the real scan API: a seeded scanner token, device id and QR payload must
 * check an order in over HTTP, exactly as the browser runner will use them.
 */
class StressTestToolTest extends TestCase
{
    use RefreshDatabase;

    private const SEED_ORDERS = 100;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function asPreprod(): void
    {
        $this->app['env'] = 'staging';
    }

    private function fixture(): StressTestFixture
    {
        return app(StressTestFixture::class);
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * @return array<string, string>
     */
    private function deviceHeader(string $deviceId): array
    {
        return ['X-Device-Id' => $deviceId];
    }

    // ---- Fixture ------------------------------------------------------------

    public function test_seeded_scanner_credentials_check_orders_in_over_the_real_scan_api(): void
    {
        $this->asPreprod();

        $summary = $this->fixture()->seed(self::SEED_ORDERS);
        $session = $this->fixture()->issueSession();

        $this->assertSame(self::SEED_ORDERS, $summary['orders']);
        $this->assertCount(StressTestFixture::SCANNER_COUNT, $session['scanners']);
        $this->assertCount(self::SEED_ORDERS, $session['codes']);

        $scanner = $session['scanners'][0];
        $headers = $this->deviceHeader($scanner['device_id']);

        $this->withToken($scanner['token'])->withHeaders($headers)
            ->postJson('/api/scan', ['payload' => $session['codes'][0], 'event_id' => $session['event_id']])
            ->assertOk()
            ->assertJsonPath('status', 'checked_in');

        $this->withToken($scanner['token'])->withHeaders($headers)
            ->postJson('/api/scan', ['payload' => $session['codes'][0], 'event_id' => $session['event_id']])
            ->assertOk()
            ->assertJsonPath('status', 'already_scanned');

        $this->withToken($scanner['token'])->withHeaders($headers)
            ->getJson('/api/dashboard/live?event='.$session['event_id'])
            ->assertOk()
            // The first seeded order (STRESS000001) holds 2 tickets, and the
            // headline counts are tickets, not orders.
            ->assertJsonPath('stats.scanned', 2)
            ->assertJsonPath('stats.sold', $summary['tickets']);
    }

    public function test_a_scanner_token_is_rejected_from_a_different_device(): void
    {
        $this->asPreprod();

        $this->fixture()->seed(self::SEED_ORDERS);
        $session = $this->fixture()->issueSession();

        $this->withToken($session['scanners'][0]['token'])
            ->withHeaders($this->deviceHeader($session['scanners'][1]['device_id']))
            ->postJson('/api/scan', ['payload' => $session['codes'][0]])
            ->assertUnauthorized();
    }

    public function test_reset_scans_makes_checked_in_orders_scannable_again(): void
    {
        $this->asPreprod();

        $this->fixture()->seed(self::SEED_ORDERS);
        $session = $this->fixture()->issueSession();
        $scanner = $session['scanners'][0];

        $this->withToken($scanner['token'])->withHeaders($this->deviceHeader($scanner['device_id']))
            ->postJson('/api/scan', ['payload' => $session['codes'][0]])
            ->assertJsonPath('status', 'checked_in');

        $this->assertSame(1, $this->fixture()->resetScans());
        $this->assertSame(0, $this->fixture()->status()['scanned']);
        $this->assertSame(0, $this->fixture()->status()['scan_events']);

        $this->withToken($scanner['token'])->withHeaders($this->deviceHeader($scanner['device_id']))
            ->postJson('/api/scan', ['payload' => $session['codes'][0]])
            ->assertJsonPath('status', 'checked_in');
    }

    public function test_issuing_a_new_session_revokes_the_previous_tokens(): void
    {
        $this->asPreprod();

        $this->fixture()->seed(self::SEED_ORDERS);
        $first = $this->fixture()->issueSession();
        $this->fixture()->issueSession();

        $this->assertSame(StressTestFixture::SCANNER_COUNT, DB::table('personal_access_tokens')->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($first['scanners'][0]['token'])
            ->withHeaders($this->deviceHeader($first['scanners'][0]['device_id']))
            ->postJson('/api/scan', ['payload' => $first['codes'][0]])
            ->assertUnauthorized();
    }

    public function test_teardown_removes_the_fixture_and_leaves_real_companies_alone(): void
    {
        $realCompany = Company::factory()->create();
        $realEvent = Event::factory()->for($realCompany)->create();
        $realOrder = Order::factory()->forEvent($realEvent)->create();

        $this->asPreprod();
        $this->fixture()->seed(self::SEED_ORDERS);
        $this->fixture()->issueSession();

        $this->fixture()->teardown();

        $this->assertFalse($this->fixture()->status()['seeded']);
        $this->assertDatabaseMissing('companies', ['slug' => StressTestFixture::COMPANY_SLUG]);
        $this->assertDatabaseMissing('users', ['email' => 'scanner01'.StressTestFixture::EMAIL_DOMAIN]);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertDatabaseHas('companies', ['id' => $realCompany->id]);
        $this->assertDatabaseHas('orders', ['id' => $realOrder->id]);
    }

    public function test_reseeding_replaces_the_fixture_instead_of_duplicating_it(): void
    {
        $this->asPreprod();

        $this->fixture()->seed(self::SEED_ORDERS);
        $this->fixture()->seed(self::SEED_ORDERS + 20);

        $this->assertSame(self::SEED_ORDERS + 20, $this->fixture()->status()['orders']);
        $this->assertSame(1, Company::withoutGlobalScopes()->where('slug', StressTestFixture::COMPANY_SLUG)->count());
        $this->assertSame(StressTestFixture::SCANNER_COUNT, User::where('email', 'like', '%'.StressTestFixture::EMAIL_DOMAIN)->count());
    }

    public function test_the_fixture_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);

        $this->fixture()->seed(self::SEED_ORDERS);
    }

    // ---- Page and endpoints -------------------------------------------------

    public function test_super_admin_can_open_the_page_and_drive_the_fixture_from_the_browser(): void
    {
        $this->asPreprod();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.stress-test.index'))
            ->assertOk()
            ->assertSee('Stress test')
            ->assertSee('not seeded yet');

        $this->actingAs($admin)->post(route('admin.stress-test.seed'), ['orders' => self::SEED_ORDERS])
            ->assertRedirect()
            ->assertSessionHas('ops_status');

        $this->actingAs($admin)->get(route('admin.stress-test.index'))
            ->assertOk()
            ->assertSee('id="stress-runner"', false);

        $response = $this->actingAs($admin)->postJson(route('admin.stress-test.session'))
            ->assertOk()
            ->assertJsonCount(StressTestFixture::SCANNER_COUNT, 'scanners')
            ->assertJsonCount(self::SEED_ORDERS, 'codes');

        // The response carries live bearer tokens, so it must never be cached.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_session_endpoint_reports_a_clear_error_before_the_fixture_is_seeded(): void
    {
        $this->asPreprod();

        $this->actingAs($this->superAdmin())->postJson(route('admin.stress-test.session'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'No stress-test fixture exists yet. Seed it first.');
    }

    public function test_seed_rejects_an_order_count_outside_the_allowed_range(): void
    {
        $this->asPreprod();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.stress-test.seed'), ['orders' => StressTestFixture::MIN_ORDERS - 1])
            ->assertSessionHasErrors('orders');

        $this->actingAs($admin)->post(route('admin.stress-test.seed'), ['orders' => StressTestFixture::MAX_ORDERS + 1])
            ->assertSessionHasErrors('orders');

        $this->assertFalse($this->fixture()->status()['seeded']);
    }

    public function test_company_users_are_denied_and_guests_are_sent_to_login(): void
    {
        $this->asPreprod();

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('admin.stress-test.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('admin.stress-test.session'))
            ->assertForbidden();

        auth()->logout();

        $this->get(route('admin.stress-test.index'))->assertRedirect('/login');
    }
}
