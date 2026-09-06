<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Services\QrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Feature: event-management-and-reporting
 *
 * Covers task 9.6 — the dashboard QR endpoint (`dashboard.events.qr` =>
 * `EventController::qr`). The endpoint is Admin-gated
 * (`ACTION_MANAGE_EVENTS`), tenant-scoped (foreign Events 404), and returns a
 * PNG QR image encoding the Event's public page URL for both draft and
 * published Events. When QR rendering is unavailable (e.g. the GD extension is
 * missing) it surfaces a 500 rather than returning broken/partial bytes.
 *
 * Requirements: 4.3 (QR available pre-publish for draft Events), 4.5 (Admin
 * gating — non-admins 403), 4.6 (tenant isolation — foreign Event 404), 4.7
 * (render failure returns an error, never PNG bytes).
 */
class EventQrTest extends TestCase
{
    use RefreshDatabase;

    // ---- Success (Admin, draft event) ---------------------------------------

    public function test_admin_downloads_a_png_qr_for_their_draft_event(): void
    {
        $admin = User::factory()->admin()->create();
        // A DRAFT (unpublished) Event — the QR is available before go-live.
        $event = Event::factory()
            ->for(Company::find($admin->company_id))
            ->unpublished()
            ->create();

        // Requirement 4.3 — QR works pre-publish.
        $response = $this->actingAs($admin)->get("/dashboard/events/{$event->id}/qr");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertNotEmpty($response->getContent());
    }

    // ---- Role gating ---------------------------------------------------------

    public function test_non_admin_roles_cannot_download_the_qr(): void
    {
        $company = Company::factory()->create();

        foreach ([
            User::factory()->for($company)->scanner()->create(),
            User::factory()->for($company)->accountant()->create(),
        ] as $user) {
            $event = Event::factory()->for($company)->create();

            // Requirement 4.5 — only Admin (ACTION_MANAGE_EVENTS) may fetch it.
            $this->actingAs($user)->get("/dashboard/events/{$event->id}/qr")
                ->assertForbidden();
        }
    }

    // ---- Tenant isolation ----------------------------------------------------

    public function test_admin_cannot_download_the_qr_for_another_companys_event(): void
    {
        $admin = User::factory()->admin()->create();
        $otherEvent = Event::factory()->create(); // different Company

        // Requirement 4.6 — a foreign Event id never matches the tenant scope.
        $this->actingAs($admin)->get("/dashboard/events/{$otherEvent->id}/qr")
            ->assertNotFound();
    }

    // ---- Render failure (GD unavailable) -------------------------------------

    public function test_render_failure_returns_an_error_and_never_png_bytes(): void
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->for(Company::find($admin->company_id))->create();

        // Simulate the QR writer failing (e.g. the GD extension is absent) by
        // binding a QrService whose png() throws. The endpoint must surface a
        // 500 rather than return broken/partial PNG bytes. (Requirement 4.7)
        $this->instance(QrService::class, new class extends QrService
        {
            public function png(string $payload, int $size = 300): string
            {
                throw new RuntimeException('GD extension unavailable.');
            }
        });

        $response = $this->actingAs($admin)->get("/dashboard/events/{$event->id}/qr");

        $response->assertStatus(500);
        $this->assertNotSame('image/png', $response->headers->get('Content-Type'));
    }
}
