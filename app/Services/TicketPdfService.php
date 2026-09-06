<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Branding\BrandingResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfInstance;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the downloadable A4 e-ticket PDF for a confirmed Order.
 *
 * The PDF mirrors the branded ticket email but is a print-ready A4 page: the
 * event heading + scannable QR at the top, the ticket line-items and booking
 * details in the middle, the organiser's custom entry instructions, and the
 * two optional per-Event sponsor banners (one across the top, one across the
 * bottom). Every image is inlined as a base64 data URI because dompdf renders
 * offline and cannot resolve CID attachments or relative asset URLs.
 *
 * The QR encodes the exact same `{Order_Reference}.HMAC` payload the email and
 * scanner use ({@see QrService::payloadFor()}), so a downloaded ticket scans
 * identically to the emailed one — the payload is a pure function of the Order
 * reference, nothing is stored.
 */
class TicketPdfService
{
    private const DISK = 'public';

    public function __construct(
        private readonly BrandingResolver $branding,
        private readonly QrService $qr,
    ) {}

    /**
     * Build the print-ready A4 ticket PDF for an Order and return the dompdf
     * instance (call `->download(...)` / `->stream(...)` / `->output()` on it).
     *
     * The Event is resolved without the tenant scope so this works from queue /
     * service context; the Order is supplied by the (already scoped) caller.
     */
    public function make(Order $order): PdfInstance
    {
        $event = Event::withoutGlobalScopes()->findOrFail($order->event_id);
        $branding = $this->branding->forEvent($event);

        $qrDataUri = $this->dataUri($this->qr->pngFor($order), 'image/png');

        return Pdf::loadView('tickets.pdf', [
            'order' => $order,
            'event' => $event,
            'branding' => $branding,
            'lineItems' => $this->lineItems($order),
            'qrDataUri' => $qrDataUri,
            'qrPayload' => $this->qr->payloadFor($order),
            'sponsorTop' => $this->imageDataUri($event->sponsor_top_path),
            'sponsorBottom' => $this->imageDataUri($event->sponsor_bottom_path),
            'logoDataUri' => $this->imageDataUri($branding->hasLogo() ? $branding->logoPath : null),
            'currencySymbol' => $this->currencySymbol($order),
        ])->setPaper('a4');
    }

    /**
     * A stable, filesystem-safe download filename for the Order's ticket PDF.
     */
    public function filename(Order $order): string
    {
        return 'ticket-'.$order->order_reference.'.pdf';
    }

    /**
     * The Order breakdown: each purchased Ticket_Type, its unit price and its
     * quantity, read from the Order's Tickets. Bypasses the tenant scope
     * because this may run in service/queue context with no resolved Company;
     * the Order is supplied by the (already scoped) caller.
     *
     * @return array<int, array{ticket_type: string, quantity: int, price_minor: int}>
     */
    private function lineItems(Order $order): array
    {
        $counts = Ticket::withoutGlobalScopes()
            ->where('order_id', $order->getKey())
            ->selectRaw('ticket_type_id, COUNT(*) as qty')
            ->groupBy('ticket_type_id')
            ->pluck('qty', 'ticket_type_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $types = TicketType::withoutGlobalScopes()
            ->whereIn('id', $counts->keys()->all())
            ->get(['id', 'name', 'price_minor'])
            ->keyBy('id');

        $items = [];

        foreach ($counts as $ticketTypeId => $qty) {
            $type = $types->get($ticketTypeId);

            $items[] = [
                'ticket_type' => (string) ($type->name ?? "Ticket #{$ticketTypeId}"),
                'quantity' => (int) $qty,
                'price_minor' => (int) ($type->price_minor ?? 0),
            ];
        }

        return $items;
    }

    /**
     * Read a stored image off the public disk and return it as a base64 data
     * URI for inline embedding in the PDF. Null/empty path or a missing file
     * yields null so the template simply omits the image.
     */
    private function imageDataUri(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($path)) {
            return null;
        }

        $mime = $disk->mimeType($path) ?: 'image/png';

        return $this->dataUri($disk->get($path), $mime);
    }

    /**
     * Wrap raw image bytes as a base64 data URI.
     */
    private function dataUri(string $bytes, string $mime): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * The currency symbol for the Order's Company, defaulting to the currency
     * code when no symbol is known.
     */
    private function currencySymbol(Order $order): string
    {
        $currency = $order->event?->company?->currency
            ?? Event::withoutGlobalScopes()->find($order->event_id)?->company?->currency
            ?? 'GBP';

        return ['GBP' => '£', 'USD' => '$', 'EUR' => '€'][$currency] ?? ($currency.' ');
    }
}
