<?php

namespace Database\Seeders;

use App\Models\HelpArticle;
use App\Models\HelpCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the Knowledge Base (`help_categories` + `help_articles`) with the
 * standard product guidance that used to live hardcoded in
 * `App\Support\HelpCentre`. This is the migration of that content into the
 * database so a Super_Admin can edit it and it can be published publicly.
 *
 * Idempotent: categories and articles are keyed by slug (article slug is
 * derived from its title, unique within the category) and upserted via
 * updateOrCreate, so running the seeder again refreshes the default content
 * without creating duplicates. It never deletes operator-authored rows.
 */
class HelpContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->sections() as $sectionOrder => $section) {
            $category = HelpCategory::updateOrCreate(
                ['slug' => $section['slug']],
                [
                    'title' => $section['title'],
                    'summary' => $section['summary'],
                    'is_published' => true,
                    'sort_order' => $sectionOrder + 1,
                ],
            );

            foreach ($section['articles'] as $articleOrder => $article) {
                HelpArticle::updateOrCreate(
                    [
                        'help_category_id' => $category->getKey(),
                        'slug' => Str::slug($article['title']),
                    ],
                    [
                        'title' => $article['title'],
                        'body' => $this->dedent($article['body']),
                        'is_published' => true,
                        'sort_order' => $articleOrder + 1,
                    ],
                );
            }
        }
    }

    /**
     * Strip the common leading indentation from a heredoc body so the stored
     * Markdown starts at column zero (otherwise 4-space-indented lines would be
     * rendered as code blocks). Trims surrounding blank lines too.
     */
    private function dedent(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?: [];

        $indents = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            preg_match('/^[ \t]*/', $line, $m);
            $indents[] = strlen($m[0]);
        }

        $strip = $indents === [] ? 0 : min($indents);

        $out = array_map(
            static fn (string $line): string => $strip > 0 ? substr($line, $strip) : $line,
            $lines,
        );

        return trim(implode("\n", $out));
    }

    /**
     * The default Knowledge Base content, ported from the former
     * `App\Support\HelpCentre::sections()`. Article bodies are Markdown (the
     * former paragraph arrays joined with blank lines, and the former optional
     * bullet list appended as a Markdown list).
     *
     * @return list<array{slug: string, title: string, summary: string, articles: list<array{title: string, body: string}>}>
     */
    private function sections(): array
    {
        return [
            [
                'slug' => 'getting-started',
                'title' => 'Getting started',
                'summary' => 'Set your company up and get your first event selling.',
                'articles' => [
                    [
                        'title' => 'The first things to do after signing up',
                        'body' => <<<'MD'
                        Your dashboard is organised around a few core areas: Events, Orders, Reports, and Company settings. The quickest path to selling tickets is to finish the onboarding checklist shown on your dashboard home.

                        The checklist walks the account Owner through connecting payouts, adding your contact details, and setting your branding. Once every step is done the checklist disappears and your storefront is ready to take orders.

                        - Connect Stripe so you can receive payouts (Owner only).
                        - Add your support and GDPR contact emails under Settings.
                        - Upload your logo and set your brand colour under Settings.
                        - Create your first event and add at least one ticket type.
                        - Publish the event, then share your storefront link.
                        MD,
                    ],
                    [
                        'title' => 'Where do I find my public storefront?',
                        'body' => <<<'MD'
                        Your storefront lives at a web address based on your company slug. You can open it any time from the "View storefront" link at the bottom of the sidebar — it opens in a new tab so you can see exactly what your customers see.

                        Only published events appear on the storefront. Draft events stay private to your team until you publish them.
                        MD,
                    ],
                ],
            ],
            [
                'slug' => 'events',
                'title' => 'Events & tickets',
                'summary' => 'Create events, set capacity, and manage ticket types.',
                'articles' => [
                    [
                        'title' => 'Creating and publishing an event',
                        'body' => <<<'MD'
                        Open Events in the sidebar and choose "Create event". Fill in the details, set where and when it happens, then add your ticket types. An event stays a private draft until you publish it, so you can prepare everything before it goes live.

                        Publishing makes the event visible on your storefront and opens it for orders. You can unpublish at any time to take it off sale without deleting it.
                        MD,
                    ],
                    [
                        'title' => 'How capacity works',
                        'body' => <<<'MD'
                        Each ticket type can have its own capped capacity, or you can run several types against a single shared event capacity pool. Set the overall event capacity on the Tickets screen, next to the ticket types it governs.

                        Capacity is held while a customer is checking out and released automatically if they do not complete payment within the reservation window, so seats are never lost to abandoned checkouts.
                        MD,
                    ],
                    [
                        'title' => 'Free tickets',
                        'body' => <<<'MD'
                        Set a ticket type price to zero to make it free. Free tickets are confirmed immediately without going through payment, and the attendee still receives a QR-coded ticket by email.
                        MD,
                    ],
                ],
            ],
            [
                'slug' => 'orders',
                'title' => 'Orders, refunds & check-in',
                'summary' => 'Manage orders, issue refunds, resend tickets, and scan on the door.',
                'articles' => [
                    [
                        'title' => 'Refunding or cancelling an order',
                        'body' => <<<'MD'
                        Open Orders, find the order, and use the cancel or refund action. Cancelling voids the tickets and returns the held capacity so the seats go back on sale. Refunding a paid order also returns the money to the customer through Stripe.

                        You can issue a partial refund one or more times up to the order total. The order stays paid with valid tickets until the refunds add up to the full amount, at which point it becomes fully refunded.
                        MD,
                    ],
                    [
                        'title' => 'A customer did not get their ticket email',
                        'body' => <<<'MD'
                        Open the order and use "Resend" to send the branded ticket email again. It reuses the same QR code as the original, so any copy the customer already has still works. You can also download the A4 ticket PDF and send it yourself.
                        MD,
                    ],
                    [
                        'title' => 'Scanning tickets on the door',
                        'body' => <<<'MD'
                        The Scan tickets screen opens your phone camera in the browser and checks each ticket in as it is scanned. A ticket can only be checked in once; a second scan tells you it has already been used. Voided or unconfirmed tickets are rejected.
                        MD,
                    ],
                ],
            ],
            [
                'slug' => 'payments',
                'title' => 'Payments & payouts',
                'summary' => 'Connect Stripe, understand fees, and get paid.',
                'articles' => [
                    [
                        'title' => 'Connecting Stripe to receive payouts',
                        'body' => <<<'MD'
                        Payments run through Stripe Connect. The account Owner opens Payments in the sidebar and starts onboarding, which hands off to Stripe to collect your business and bank details. When you return, we read your account status so you know the moment you are ready to take money.

                        You cannot sell paid tickets until Stripe reports that charges are enabled on your connected account.
                        MD,
                    ],
                    [
                        'title' => 'Who pays the platform fee?',
                        'body' => <<<'MD'
                        The Owner chooses how the platform fee is handled: either your company absorbs it, or it is passed on to the customer at checkout. Changing this only affects future orders — existing orders keep the fee handling that applied when they were placed.
                        MD,
                    ],
                    [
                        'title' => 'When do I get paid?',
                        'body' => <<<'MD'
                        Money is collected on your own connected Stripe account, so payouts follow your Stripe payout schedule. Your net-to-company figure on the Reports screen is the order total less the platform fee.
                        MD,
                    ],
                ],
            ],
            [
                'slug' => 'team',
                'title' => 'Team & roles',
                'summary' => 'Invite colleagues and understand who can do what.',
                'articles' => [
                    [
                        'title' => 'Inviting a colleague',
                        'body' => <<<'MD'
                        Open Team in the sidebar and send an invitation by email. The person you invite gets a link to set up their own login. You pick their role when you invite them, which controls what they can see and do.
                        MD,
                    ],
                    [
                        'title' => 'What each role can do',
                        'body' => <<<'MD'
                        Roles keep sensitive actions with the people you trust with them. The Owner can do everything, including billing, payouts setup, and managing the team.

                        - **Owner** — full access, including company settings, team, Stripe fees and billing.
                        - **Admin** — events, ticket types, orders (including cancel/refund/comp), reports, check-in, GDPR handling, the activity log, the team and Stripe setup — but not billing, Stripe fees or company settings.
                        - **Box office** — runs events, ticketing, orders and check-in, but not reports, settings, users, Stripe, billing or GDPR.
                        - **Accountant** — reports and payout figures, plus managing and cancelling/refunding orders.
                        - **Scanner** — check-in only.
                        MD,
                    ],
                ],
            ],
            [
                'slug' => 'privacy',
                'title' => 'Privacy & data (GDPR)',
                'summary' => 'Handle data-subject requests and manage your own data.',
                'articles' => [
                    [
                        'title' => "Exporting or deleting a customer's data",
                        'body' => <<<'MD'
                        Open Customers, find the person, and use the export or anonymise tools. Export gives you a JSON copy of the personal data you hold on them. Anonymising removes their personal details while keeping the transactional records your accounts need. These tools are held by the Owner as the data controller.
                        MD,
                    ],
                    [
                        'title' => 'Downloading your own account data',
                        'body' => <<<'MD'
                        From Your profile you can download a JSON copy of the personal data held on your own account at any time.
                        MD,
                    ],
                    [
                        'title' => 'How customer data retention works',
                        'body' => <<<'MD'
                        To keep personal data no longer than you need it, customer names and email addresses are anonymised automatically once the retention period you choose has passed. This happens every night with no action from you.

                        **Setting your retention period**
                        Open your company settings and go to the Privacy & data tab. Enter the number of months to keep customer details after their last activity. The default is 24 months, the minimum is 6 months and the maximum is 1,200 months (100 years). Changes apply to future sweeps straight away.

                        **When the clock starts**
                        Retention is counted per customer, not per order, and starts from the later of:
                        - the date of their most recent order, or
                        - the date of the event, if they hold a live ticket for an event that has not happened yet.

                        So someone who buys a ticket today for an event in two years is kept until two years from now plus your retention period, not from the purchase date. A customer is only anonymised once all of their orders have passed their dates, so a returning customer is never scrubbed part way through the relationship.

                        **What counts as a live ticket**
                        Paid, free, reserved and disputed orders for an event that is still to come and has not been cancelled. Cancelled, refunded, expired and voided orders do not extend retention, and neither do orders for a cancelled event. These run from the order date.

                        **What is anonymised, and what is kept**
                        The customer's name and email are replaced with a placeholder on every order they placed with your company. Order references, amounts, fees, ticket and check-in records and refunds are always kept so your accounts, payouts and disputes still add up. Anonymised data cannot be recovered, so a ticket email can no longer be resent to that customer.

                        **Checking a customer**
                        Open Customers and select the person. The Data retention panel shows the date their details will be anonymised. Their data export also includes this date. If someone asks for their data to be removed sooner, use the Anonymise tool on the same page (Owners and Admins).

                        **Your own records**
                        Every automatic run is recorded in the activity log as "Auto-anonymised customer data past retention", showing how many orders were affected but never any personal details.
                        MD,
                    ],
                ],
            ],
            [
                'slug' => 'troubleshooting',
                'title' => 'Troubleshooting',
                'summary' => 'Quick answers to the most common problems.',
                'articles' => [
                    [
                        'title' => 'I was signed out unexpectedly',
                        'body' => <<<'MD'
                        For security, sessions time out after a period of inactivity. Simply sign back in. If you suspect a session was left open on another device, use "Sign out other sessions" on Your profile to end every other session while keeping your current one.
                        MD,
                    ],
                    [
                        'title' => 'My event or order is not showing up',
                        'body' => <<<'MD'
                        You only ever see data for your own company. If you expect to see an event on your storefront, check it is published — drafts stay private. If an order is missing, check the status filter on the Orders screen is not hiding it.
                        MD,
                    ],
                    [
                        'title' => 'Still stuck?',
                        'body' => <<<'MD'
                        If the answer is not here, raise a support request and the CK Enterprises team will help. You can optionally let us access your account to investigate faster — you choose whether to grant that when you send the request.
                        MD,
                    ],
                ],
            ],
        ];
    }
}
