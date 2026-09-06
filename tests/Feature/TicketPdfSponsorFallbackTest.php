<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Services\TicketPdfService;
use Barryvdh\DomPDF\PDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — ticket PDF sponsor/logo selection.
 *
 * The downloadable ticket shows a sponsor's logo only when that sponsor opted
 * in via its per-sponsor "show on ticket" toggle. When no sponsor logo is
 * shown, the ticket falls back to the organiser's own logo so it is never left
 * unbranded. These rules live in {@see TicketPdfService::make()}.
 *
 * dompdf re-encodes embedded images into its own compressed streams, so the
 * source bytes never appear verbatim in the output. Instead we intercept the
 * data handed to the `tickets.pdf` view (a base64 data URI per image slot) via
 * a fake dompdf wrapper and assert which artwork the service selected.
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

        // Replace the dompdf wrapper with a subclass that records the view data
        // and skips the (heavy, lossy) PDF render. It extends the real wrapper
        // so it still satisfies TicketPdfService::make()'s return type.
        $this->app->bind('dompdf.wrapper', function ($app) use (&$captured) {
            $real = $app->make(PDF::class);

            return new class($real, $captured) extends PDF
            {
                /** @param array<string, mixed> $captured */
                public function __construct(PDF $real, private array &$captured)
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
        $sponsorPath = $this->storePng('branding/sponsors/top.png');

        $company = Company::factory()->create(['logo_path' => $logoPath]);
        $event = Event::factory()->for($company)->create([
            // A sponsor banner exists but is NOT flagged for the ticket.
            'sponsor_top_path' => $sponsorPath,
            'sponsor_top_on_ticket' => false,
            'sponsor_bottom_path' => null,
            'logo_path' => null,
        ]);
        $order = Order::factory()->forEvent($event)->create(['status' => Order::STATUS_PAID]);

        $data = $this->capturedViewData($order);

        // No sponsor banner on the ticket; the organiser logo is the fallback.
        $this->assertNull($data['sponsorTop']);
        $this->assertNull($data['sponsorBottom']);
        $this->assertNotNull($data['logoDataUri']);
    }

    public function test_sponsor_logo_prints_and_suppresses_client_logo_when_opted_in(): void
    {
        Storage::fake('public');

        $logoPath = $this->storePng('branding/logos/logo.png');
        $sponsorPath = $this->storePng('branding/sponsors/top.png');

        $company = Company::factory()->create(['logo_path' => $logoPath]);
        $event = Event::factory()->for($company)->create([
            'sponsor_top_path' => $sponsorPath,
            'sponsor_top_on_ticket' => true,
            'sponsor_bottom_path' => null,
            'logo_path' => null,
        ]);
        $order = Order::factory()->forEvent($event)->create(['status' => Order::STATUS_PAID]);

        $data = $this->capturedViewData($order);

        // The opted-in sponsor banner prints; the client logo is suppressed.
        $this->assertNotNull($data['sponsorTop']);
        $this->assertNull($data['logoDataUri']);
    }

    public function test_sponsor_with_toggle_off_is_never_placed_on_the_ticket(): void
    {
        Storage::fake('public');

        $sponsorPath = $this->storePng('branding/sponsors/bottom.png');

        // No organiser logo at all, and the only sponsor is toggled off: the
        // ticket simply carries neither, rather than forcing the opted-out logo.
        $company = Company::factory()->create(['logo_path' => null]);
        $event = Event::factory()->for($company)->create([
            'sponsor_bottom_path' => $sponsorPath,
            'sponsor_bottom_on_ticket' => false,
            'sponsor_top_path' => null,
            'logo_path' => null,
        ]);
        $order = Order::factory()->forEvent($event)->create(['status' => Order::STATUS_PAID]);

        $data = $this->capturedViewData($order);

        $this->assertNull($data['sponsorTop']);
        $this->assertNull($data['sponsorBottom']);
        $this->assertNull($data['logoDataUri']);
    }
}
