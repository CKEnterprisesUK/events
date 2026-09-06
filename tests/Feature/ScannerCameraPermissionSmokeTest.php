<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Task 19.3 — focused smoke test for the scanner page's camera / permission
 * behaviour. The full check-in flow (decode, verify, atomic single check-in) is
 * covered by ScanCheckInTest (task 19.1); this test deliberately narrows to the
 * two client-side concerns that a PHP feature test can assert about the
 * rendered page:
 *
 *   - 16.1: the Scanner operates in a phone browser using the device camera,
 *     with no app install — the page renders a <video> camera surface and wires
 *     up `getUserMedia` + a client-side QR decoder (loaded from the browser, no
 *     bundle/build step).
 *   - 16.2: when camera permission cannot be obtained, the page surfaces a
 *     "camera access is required" message and performs no scan — the
 *     permission-denied element is present and the decoder only submits the
 *     form on a successful decode (so nothing is scanned without the camera).
 *
 * JS camera behaviour itself cannot be driven in a PHP feature test, so this is
 * a rendered-markup smoke test: it asserts the camera surface, the
 * permission-denied message element, and the submit-only-on-decode wiring are
 * all present, and that the page is Scanner-role gated.
 *
 * Requirements: 16.1, 16.2 (Scanner-role gating 3.6/3.7 exercised alongside).
 */
class ScannerCameraPermissionSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 16.1 — the scanner page renders the phone-browser camera surface and the
     * client-side getUserMedia + QR-decoder wiring (no app install / bundle).
     */
    public function test_scanner_page_renders_camera_surface_and_getusermedia_wiring(): void
    {
        $scanner = User::factory()->scanner()->create();

        $response = $this->actingAs($scanner)->get('/dashboard/scan');

        $response->assertStatus(200);

        // A <video> camera surface is present (the phone camera preview).
        $response->assertSee('id="scanner-video"', false);
        $response->assertSee('<video', false);
        // The page uses the device camera via getUserMedia — no native app.
        $response->assertSee('getUserMedia', false);
        // A client-side QR decoder is wired up in the browser (no build step).
        $response->assertSee('Html5Qrcode', false);
    }

    /**
     * 16.2 — the page carries the "camera access is required" message element
     * and the decoder is wired to submit only on a successful decode, so no
     * scan is performed when the camera / permission is unavailable.
     */
    public function test_scanner_page_carries_permission_denied_message_and_no_scan_without_decode(): void
    {
        $scanner = User::factory()->scanner()->create();

        $response = $this->actingAs($scanner)->get('/dashboard/scan');

        $response->assertStatus(200);

        // The camera-access-required message element is present on the page.
        $response->assertSee('id="scanner-permission-denied"', false);
        $response->assertSee('Camera access is required', false);

        // The permission-denied path reveals the message and performs no scan
        // (the handler that surfaces it is wired to the getUserMedia failure).
        $response->assertSee('showPermissionDenied', false);

        // A scan is only submitted from a successful decode: the form submit is
        // reached exclusively inside the decode callback, so with no camera /
        // no decode the form is never submitted (no scan occurs).
        $response->assertSee('onDecoded', false);
        $response->assertSee('formEl.submit()', false);
    }

    /**
     * The scanner page is gated on the check-in permission (3.6/3.7): the
     * Scanner role holds it, and the Owner — as the account superuser — holds
     * every permission including check-in, so both are served the page. The
     * Admin and Accountant roles do not have check-in and are forbidden.
     */
    public function test_scanner_page_is_check_in_gated(): void
    {
        // Roles that hold the check-in permission are served the page.
        foreach ([
            User::factory()->scanner()->create(),
            User::factory()->owner()->create(),
        ] as $user) {
            $this->actingAs($user)->get('/dashboard/scan')->assertStatus(200);
        }

        // Roles without check-in are forbidden.
        foreach ([
            User::factory()->admin()->create(),
            User::factory()->accountant()->create(),
        ] as $user) {
            $this->actingAs($user)->get('/dashboard/scan')->assertForbidden();
        }
    }

    /**
     * The scanner page requires authentication — an unauthenticated visitor is
     * redirected to login rather than being served the camera page.
     */
    public function test_scanner_page_requires_authentication(): void
    {
        $this->get('/dashboard/scan')->assertRedirect(route('login'));
    }
}
