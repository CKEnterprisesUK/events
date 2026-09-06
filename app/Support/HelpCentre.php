<?php

namespace App\Support;

/**
 * The authored content behind the in-dashboard Help & Knowledge portal.
 *
 * The knowledge base is intentionally held in code rather than the database:
 * the articles describe how the product works, so they version and deploy
 * alongside it and are the same for every Company. Each section groups related
 * articles; each article is a title plus HTML-safe body paragraphs and an
 * optional bulleted list, rendered read-only by the Help view.
 *
 * If per-Company or operator-editable articles are ever needed, this can be
 * swapped for a `help_articles` table without changing the controller/view
 * contract (both consume {@see self::sections()}).
 */
final class HelpCentre
{
    /**
     * The public support mailbox shown as the fallback contact channel.
     */
    public const PLATFORM_SUPPORT_EMAIL = 'support@ckenterprises.co.uk';

    /**
     * The full knowledge base, grouped into sections.
     *
     * @return list<array{
     *     key: string,
     *     title: string,
     *     summary: string,
     *     articles: list<array{title: string, body: list<string>, list?: list<string>}>
     * }>
     */
    public static function sections(): array
    {
        return [
            [
                'key' => 'getting-started',
                'title' => 'Getting started',
                'summary' => 'Set your company up and get your first event selling.',
                'articles' => [
                    [
                        'title' => 'The first things to do after signing up',
                        'body' => [
                            'Your dashboard is organised around a few core areas: Events, Orders, Reports, and Company settings. The quickest path to selling tickets is to finish the onboarding checklist shown on your dashboard home.',
                            'The checklist walks the account Owner through connecting payouts, adding your contact details, and setting your branding. Once every step is done the checklist disappears and your storefront is ready to take orders.',
                        ],
                        'list' => [
                            'Connect Stripe so you can receive payouts (Owner only).',
                            'Add your support and GDPR contact emails under Settings.',
                            'Upload your logo and set your brand colour under Settings.',
                            'Create your first event and add at least one ticket type.',
                            'Publish the event, then share your storefront link.',
                        ],
                    ],
                    [
                        'title' => 'Where do I find my public storefront?',
                        'body' => [
                            'Your storefront lives at a web address based on your company slug. You can open it any time from the "View storefront" link at the bottom of the sidebar — it opens in a new tab so you can see exactly what your customers see.',
                            'Only published events appear on the storefront. Draft events stay private to your team until you publish them.',
                        ],
                    ],
                ],
            ],
            [
                'key' => 'events',
                'title' => 'Events & tickets',
                'summary' => 'Create events, set capacity, and manage ticket types.',
                'articles' => [
                    [
                        'title' => 'Creating and publishing an event',
                        'body' => [
                            'Open Events in the sidebar and choose "Create event". Fill in the details, set where and when it happens, then add your ticket types. An event stays a private draft until you publish it, so you can prepare everything before it goes live.',
                            'Publishing makes the event visible on your storefront and opens it for orders. You can unpublish at any time to take it off sale without deleting it.',
                        ],
                    ],
                    [
                        'title' => 'How capacity works',
                        'body' => [
                            'Each ticket type can have its own capped capacity, or you can run several types against a single shared event capacity pool. Set the overall event capacity on the Tickets screen, next to the ticket types it governs.',
                            'Capacity is held while a customer is checking out and released automatically if they do not complete payment within the reservation window, so seats are never lost to abandoned checkouts.',
                        ],
                    ],
                    [
                        'title' => 'Free tickets',
                        'body' => [
                            'Set a ticket type price to zero to make it free. Free tickets are confirmed immediately without going through payment, and the attendee still receives a QR-coded ticket by email.',
                        ],
                    ],
                ],
            ],
            [
                'key' => 'orders',
                'title' => 'Orders, refunds & check-in',
                'summary' => 'Manage orders, issue refunds, resend tickets, and scan on the door.',
                'articles' => [
                    [
                        'title' => 'Refunding or cancelling an order',
                        'body' => [
                            'Open Orders, find the order, and use the cancel or refund action. Cancelling voids the tickets and returns the held capacity so the seats go back on sale. Refunding a paid order also returns the money to the customer through Stripe.',
                            'You can issue a partial refund one or more times up to the order total. The order stays paid with valid tickets until the refunds add up to the full amount, at which point it becomes fully refunded.',
                        ],
                    ],
                    [
                        'title' => 'A customer did not get their ticket email',
                        'body' => [
                            'Open the order and use "Resend" to send the branded ticket email again. It reuses the same QR code as the original, so any copy the customer already has still works. You can also download the A4 ticket PDF and send it yourself.',
                        ],
                    ],
                    [
                        'title' => 'Scanning tickets on the door',
                        'body' => [
                            'The Scan tickets screen opens your phone camera in the browser and checks each ticket in as it is scanned. A ticket can only be checked in once; a second scan tells you it has already been used. Voided or unconfirmed tickets are rejected.',
                        ],
                    ],
                ],
            ],
            [
                'key' => 'payments',
                'title' => 'Payments & payouts',
                'summary' => 'Connect Stripe, understand fees, and get paid.',
                'articles' => [
                    [
                        'title' => 'Connecting Stripe to receive payouts',
                        'body' => [
                            'Payments run through Stripe Connect. The account Owner opens Payments in the sidebar and starts onboarding, which hands off to Stripe to collect your business and bank details. When you return, we read your account status so you know the moment you are ready to take money.',
                            'You cannot sell paid tickets until Stripe reports that charges are enabled on your connected account.',
                        ],
                    ],
                    [
                        'title' => 'Who pays the platform fee?',
                        'body' => [
                            'The Owner chooses how the platform fee is handled: either your company absorbs it, or it is passed on to the customer at checkout. Changing this only affects future orders — existing orders keep the fee handling that applied when they were placed.',
                        ],
                    ],
                    [
                        'title' => 'When do I get paid?',
                        'body' => [
                            'Money is collected on your own connected Stripe account, so payouts follow your Stripe payout schedule. Your net-to-company figure on the Reports screen is the order total less the platform fee.',
                        ],
                    ],
                ],
            ],
            [
                'key' => 'team',
                'title' => 'Team & roles',
                'summary' => 'Invite colleagues and understand who can do what.',
                'articles' => [
                    [
                        'title' => 'Inviting a colleague',
                        'body' => [
                            'Open Team in the sidebar and send an invitation by email. The person you invite gets a link to set up their own login. You pick their role when you invite them, which controls what they can see and do.',
                        ],
                    ],
                    [
                        'title' => 'What each role can do',
                        'body' => [
                            'Roles keep sensitive actions with the people you trust with them. The Owner can do everything, including billing, payouts setup, and managing the team.',
                        ],
                        'list' => [
                            'Owner — full access, including company settings, team, Stripe and billing.',
                            'Admin — events, ticket types, orders (including cancel/refund/comp), GDPR handling and the activity log.',
                            'Box office — runs events, ticketing and orders, but not settings, users, Stripe, billing or GDPR.',
                            'Accountant — read-only reports and payout figures.',
                            'Scanner — check-in only.',
                        ],
                    ],
                ],
            ],
            [
                'key' => 'privacy',
                'title' => 'Privacy & data (GDPR)',
                'summary' => 'Handle data-subject requests and manage your own data.',
                'articles' => [
                    [
                        'title' => 'Exporting or deleting a customer’s data',
                        'body' => [
                            'Open Customers, find the person, and use the export or anonymise tools. Export gives you a JSON copy of the personal data you hold on them. Anonymising removes their personal details while keeping the transactional records your accounts need. These tools are held by the Owner as the data controller.',
                        ],
                    ],
                    [
                        'title' => 'Downloading your own account data',
                        'body' => [
                            'From Your profile you can download a JSON copy of the personal data held on your own account at any time.',
                        ],
                    ],
                ],
            ],
            [
                'key' => 'troubleshooting',
                'title' => 'Troubleshooting',
                'summary' => 'Quick answers to the most common problems.',
                'articles' => [
                    [
                        'title' => 'I was signed out unexpectedly',
                        'body' => [
                            'For security, sessions time out after a period of inactivity. Simply sign back in. If you suspect a session was left open on another device, use "Sign out other sessions" on Your profile to end every other session while keeping your current one.',
                        ],
                    ],
                    [
                        'title' => 'My event or order is not showing up',
                        'body' => [
                            'You only ever see data for your own company. If you expect to see an event on your storefront, check it is published — drafts stay private. If an order is missing, check the status filter on the Orders screen is not hiding it.',
                        ],
                    ],
                    [
                        'title' => 'Still stuck?',
                        'body' => [
                            'If the answer is not here, raise a support request and the CK Enterprises team will help. You can optionally let us access your account to investigate faster — you choose whether to grant that when you send the request.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
