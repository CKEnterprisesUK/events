<?php

namespace App\Services;

use App\Models\Event;
use App\Services\Reporting\EventReport;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The library of the super-admin's prebuilt, manually-triggered client emails.
 *
 * A "templated email" differs from a free-form platform announcement: the
 * super admin does not author the body from scratch. Instead each template here
 * returns a ready-made subject and a Markdown body, which the operator may then
 * tweak before the usual compose → review → send flow. The body still flows
 * through {@see AnnouncementRenderer} (raw HTML escaped, unsafe links rejected,
 * `{{name}}`/`{{company}}`/`{{platform}}`/`{{dashboard_url}}` placeholders
 * resolved per recipient), so the delivery pipeline, safety and personalisation
 * are identical to announcements — only the starting content is canned.
 *
 * Two templates are offered:
 *
 *   - PRE_EVENT_INFO: static operational guidance for a client — how to invite
 *     scanner staff and how to run door scanning (web and iOS). The body is a
 *     constant; it carries no per-event figures.
 *
 *   - SALES_REPORT: a per-event sales summary. The body is BUILT from the live
 *     {@see EventReport} for a chosen Event, so the figures are the platform's
 *     single accounting source of truth, never hand-typed. The operator picks
 *     the Event; the audience defaults to that Event's own Company.
 */
class TemplatedEmailLibrary
{
    public const PRE_EVENT_INFO = 'pre_event_info';

    public const SALES_REPORT = 'sales_report';

    /**
     * The template keys and their human-facing metadata, in display order.
     *
     * @var array<string, array{label: string, blurb: string}>
     */
    public const TEMPLATES = [
        self::PRE_EVENT_INFO => [
            'label' => 'Pre-event information',
            'blurb' => 'How to invite scanner staff and use ticket scanning on the door.',
        ],
        self::SALES_REPORT => [
            'label' => 'Sales report for an event',
            'blurb' => 'A sales summary for a chosen event, built from the live figures.',
        ],
    ];

    public function __construct(
        private readonly EventReportService $reports,
    ) {}

    /**
     * Whether the given key is a known template.
     */
    public function exists(string $template): bool
    {
        return array_key_exists($template, self::TEMPLATES);
    }

    /**
     * The human label for a template key, falling back to the raw key.
     */
    public static function label(string $template): string
    {
        return self::TEMPLATES[$template]['label'] ?? $template;
    }

    /**
     * The default subject line for a template. For the sales report the Event
     * name is woven in; for pre-event info the platform name is used.
     */
    public function defaultSubject(string $template, ?Event $event = null): string
    {
        return match ($template) {
            self::SALES_REPORT => $event !== null
                ? 'Sales report: '.$event->name
                : 'Sales report for your event',
            default => 'Getting ready for your event — scanning setup',
        };
    }

    /**
     * The default Markdown body for a template. The sales report requires the
     * Event it reports on; pre-event info ignores it.
     */
    public function defaultBody(string $template, ?Event $event = null): string
    {
        return match ($template) {
            self::SALES_REPORT => $this->salesReportBody($event),
            default => $this->preEventInfoBody(),
        };
    }

    /**
     * The canned "how to invite scanners and run scanning" guidance. Written as
     * Markdown with the shared personalisation placeholders; everything is
     * escaped at render, so the copy is safe as-is.
     */
    private function preEventInfoBody(): string
    {
        return <<<'MD'
        Hi {{name}},

        Your event is coming up, so here is a quick guide for {{company}} on getting your door team set up to scan tickets.

        ## 1. Invite your scanner staff

        1. In your dashboard, go to **Company → Team**.
        2. Choose **Invite** and enter each door person's email address.
        3. Set their role to **Scanner** — this role can only check attendees in at the door, nothing else.
        4. They receive an email invitation, set a password, and join your company.

        You can invite as many scanners as you need, and remove them again after the event.

        ## 2. Choose how your team will scan

        There are two ways to scan, and your team can mix and match:

        - **In the web browser (no app to install).** Sign in, open **Door → Scan tickets**, and allow camera access when prompted. The browser opens the camera and reads each ticket QR code.
        - **With the EventsScanner iOS app.** Download the app, sign in with the same login (plus two-factor if you have it enabled), then pick the event to scan.

        ## 3. On the day

        - Make sure the event is **published** — scanning only works for a live event.
        - Point the camera at each attendee's ticket QR code. You'll get a clear result: **checked in**, **already scanned**, or **invalid**.
        - Each ticket can only be checked in once, so duplicates are caught automatically.
        - Watch live check-in numbers on the event's live dashboard as your team works the door.

        If anything looks off on the night, your team can also type a ticket reference in manually as a fallback.

        You can open your dashboard any time here: {{dashboard_url}}

        Have a great event,
        The {{platform}} team
        MD;
    }

    /**
     * Build the per-event sales summary body from the live {@see EventReport}.
     * Figures come straight from {@see EventReportService} — the single
     * accounting source of truth — so this email can never diverge from the
     * in-app report. All money is formatted from integer minor units.
     */
    private function salesReportBody(?Event $event): string
    {
        if ($event === null) {
            return 'Select an event to generate its sales report.';
        }

        $report = $this->reports->for($event);
        $currency = $event->company?->currency ?? 'GBP';

        $money = fn (int $minor): string => Money::format($minor, $currency);

        $when = $event->starts_at instanceof Carbon
            ? $event->starts_at->format('j F Y, g:i a')
            : 'date to be confirmed';

        $utilisation = $report->utilisation();
        $utilisationLine = is_string($utilisation)
            ? 'Unlimited capacity'
            : $utilisation.'% of capacity';

        $lines = [];
        $lines[] = 'Hi {{name}},';
        $lines[] = '';
        $lines[] = 'Here is the latest sales summary for **'.$event->name.'** ('.$when.').';
        $lines[] = '';
        $lines[] = '## Headline figures';
        $lines[] = '';
        $lines[] = '- **Tickets sold:** '.number_format($report->ticketsSold);
        $lines[] = '- **Confirmed orders:** '.number_format($report->confirmedOrders);
        $lines[] = '- **Gross revenue:** '.$money($report->grossRevenueMinor);
        $lines[] = '- **Net to you (after platform fee):** '.$money($report->netToCompanyMinor);
        $lines[] = '- **Estimated net payout (after card fees):** '.$money($report->netPayoutMinor);
        $lines[] = '- **Capacity used:** '.$utilisationLine;
        $lines[] = '';

        if ($report->perTicketType !== []) {
            $lines[] = '## By ticket type';
            $lines[] = '';
            foreach ($report->perTicketType as $row) {
                $remaining = $row['remaining'] === null
                    ? 'shared pool'
                    : number_format($row['remaining']).' remaining';
                $lines[] = '- **'.$row['name'].':** '.number_format($row['sold']).' sold, '
                    .$remaining.' · '.$money($row['revenue_minor']);
            }
            $lines[] = '';
        }

        $lines[] = 'You can see the full, live report and export a CSV from your dashboard: {{dashboard_url}}';
        $lines[] = '';
        $lines[] = 'Thanks,';
        $lines[] = 'The {{platform}} team';

        return implode("\n", $lines);
    }
}
