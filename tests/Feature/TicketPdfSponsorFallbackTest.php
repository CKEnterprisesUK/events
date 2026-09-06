<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\EventSponsor;
use App\Models\Order;
use App\Services\TicketPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — ticket PDF sponsor/logo selection.
 *
 * A sponsor's logo prints on the ticket only when that sponsor is flagged
 * `on_ticket`. When no sponsor logo is shown, the ticket falls back to the
 * organiser's own logo so it is never left unbranded. These rules live in
 * {@see TicketPdfService::make()}.
 *
 * dompdf re-encodes embedded images into its own compressed streams, so the
 * source bytes never appear verbatim in the output. Instead we intercept the
 * data handed to the `tickets.pdf` view (the on-ticket sponsor logo data URIs
 * and the fallback logo) via a fake dompdf wrapper and assert the selection.
 */
class TicketPdfSponsorFallbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Swap the dompdf wrapper the Pdf facade resolves for a fake that captures
     * the view data, then run the service and return that captured data.
     *
     * @return array<string, mixed>
     */
    private function capturedViewData(Order $order): array
    {
        $captured = [];

        $this->app->bind('dompdf.wrapper', function ($app) use (&$captured) {
            $real = $app->make(\Barryvdh\DomPDF\PDF::class);

            return new class($real, $captured) extends \Barryvdh\DomPDF\PDF
            {
                /** @param array<string, mixed> $captured */
                public function __construct(\Barryvdh\DomPDF\PDF $real, private array &$captured)
                {
                    parent::__construct(
                        $real->getDomPDF(),
                        app('config'),
                        app('files'),
                        app('view'),
                    );
                }

                /**
                 * @param  array<string, mixed>  $data
                 * @param  array<string, mixed>  $mergeData
                 */
                public function loadView(string $view, array $data = [], array $mergeData = [], ?string $encoding = null): self
                {
                    $this->captured = $data;

                    return $this;
                }

                public function setPaper($paper, $orientation = 'portrait'): self
                {
                    return $this;
                }
            };
        });

        app(TicketPdfService::class)->make($order);

        return $captured;
    }

    private function storePng(string $path): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    public function test_client_logo_prints_when_no_sponsor_is_shown_on_the_ticket(): void
    {
        Storage::fake('public');

        $logoPath = $this->storePng('branding/logos/logo.png');
        $sponsorPath = $this->storePng('branding/sponsors/a.png');

        $company = Company::factory()->create(['logo_path' => $logoPath]);
        $event = Event::factory()->for($company)->create(['logo_path' => null]);

        // A sponsor exists but is NOT flagged for the ticket.
        EventSponsor::factory()->forEvent($event)->create([
            'image_path' => $sponsorPath,
            'on_ticket' => false,
        ]);

        $order = Order::factory()->forEvent($event)->create(['status' => Order::STATUS_PAID]);

        $data = $this->capturedViewData($order);

        // No sponsor logo on the ticket; the organiser logo is the fallback.
        $this->assertSame([], $data['sponsorLogos']);
        $this->assertNotNull($data['logoDataUri']);
    }

    public function test_on_ticket_sponsors_print_and_suppress_client_logo(): void
    {
        Storage::fake('public');

        $logoPath = $this->storePng('branding/logos/logo.png');
        $a = $this->storePng('branding/sponsors/a.png');
        $b = $this->storePng('branding/sponsors/b.png');

        $company = Company::factory()->create(['logo_path' => $logoPath]);
        $event = Event::factory()->for($company)->create(['logo_path' => null]);

        EventSponsor::factory()->forEvent($event)->onTicket()->create(['image_path' => $a, 'sort_order' => 0]);
        EventSponsor::factory()->forEvent($event)->onTicket()->create(['image_path' => $b, 'sort_order' => 1]);
        // A third, store-page-only sponsor must not appear on the ticket.
        EventSponsor::factory()->forEvent($event)->create(['image_path' => $this->storePng('branding/sponsors/c.png'), 'sort_order' => 2]);

        $order = Order::factory()->forEvent($event)->create(['status' => Order::STATUS_PAID]);

        $data = $this->capturedViewData($order);

        // Both opted-in sponsor logos are present; the client logo is suppressed.
        $this->assertCount(2, $data['sponsorLogos']);
        $this->assertNull($data['logoDataUri']);
    }

    public function test_sponsor_with_toggle_off_is_never_placed_on_the_ticket(): void
    {
        Storage::fake('public');

        $sponsorPath = $this->storePng('branding/sponsors/a.png');

        // No organiser logo, and the only sponsor is off the ticket: the ticket
        // carries neither, rather than forcing the opted-out logo.
        $company = Company::factory()->create(['logo_path' => null]);
        $event = Event::factory()->for($company)->create(['logo_path' => null]);

        EventSponsor::factory()->forEvent($event)->create([
            'image_path' => $sponsorPath,
            'on_ticket' => false,
        ]);

        $order = Order::factory()->forEvent($event)->create(['status' => Order::STATUS_PAID]);

        $data = $this->capturedViewData($order);

        $this->assertSame([], $data['sponsorLogos']);
        $this->assertNull($data['logoDataUri']);
    }
}
