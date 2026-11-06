-- =============================================================================
-- 052_seed_help_content.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Seed the public Knowledge Base with the standard product guidance: 7
--   sections (`help_categories`) and their 19 articles (`help_articles`). This
--   is the raw-SQL counterpart of `Database\Seeders\HelpContentSeeder`, for
--   applying the default content on shared cPanel hosting (no SSH / artisan) by
--   pasting into phpMyAdmin.
--
--   Idempotent: categories are keyed by `slug` and articles by
--   (`help_category_id`, `slug`) — the same keys the seeder uses — and upserted
--   via INSERT ... ON DUPLICATE KEY UPDATE, so re-running refreshes the default
--   content in place without creating duplicates and without touching any
--   operator-authored rows. Article slugs are the Str::slug() of the title,
--   matching what the app generates.
--
--   Content provenance: ported verbatim from HelpContentSeeder::sections()
--   (itself ported from the former App\Support\HelpCentre). Bodies are Markdown.
--
-- Applying on prod:
--   Paste this file into phpMyAdmin AFTER 050_create_help_categories.sql and
--   051_create_help_articles.sql (the tables must already exist). This file
--   seeds data only — it writes NO row to the `migrations` ledger because it
--   corresponds to a seeder, not a migration.
--
-- Changelog:
--   052 (initial) — seed 7 help categories and 18 help articles.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- -----------------------------------------------------------------------------
-- Categories (keyed by slug)
-- -----------------------------------------------------------------------------
INSERT INTO `help_categories` (`slug`, `title`, `summary`, `is_published`, `sort_order`, `created_at`, `updated_at`) VALUES
  ('getting-started', 'Getting started', 'Set your company up and get your first event selling.', 1, 1, NOW(), NOW()),
  ('events', 'Events & tickets', 'Create events, set capacity, and manage ticket types.', 1, 2, NOW(), NOW()),
  ('orders', 'Orders, refunds & check-in', 'Manage orders, issue refunds, resend tickets, and scan on the door.', 1, 3, NOW(), NOW()),
  ('payments', 'Payments & payouts', 'Connect Stripe, understand fees, and get paid.', 1, 4, NOW(), NOW()),
  ('team', 'Team & roles', 'Invite colleagues and understand who can do what.', 1, 5, NOW(), NOW()),
  ('privacy', 'Privacy & data (GDPR)', 'Handle data-subject requests and manage your own data.', 1, 6, NOW(), NOW()),
  ('troubleshooting', 'Troubleshooting', 'Quick answers to the most common problems.', 1, 7, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `summary` = VALUES(`summary`),
  `is_published` = VALUES(`is_published`),
  `sort_order` = VALUES(`sort_order`),
  `updated_at` = NOW();

-- -----------------------------------------------------------------------------
-- Articles (keyed by help_category_id + slug; category resolved by slug)
-- -----------------------------------------------------------------------------

-- Getting started
INSERT INTO `help_articles` (`help_category_id`, `slug`, `title`, `body`, `is_published`, `sort_order`, `created_at`, `updated_at`) VALUES
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'getting-started'), 'the-first-things-to-do-after-signing-up', 'The first things to do after signing up',
'Your dashboard is organised around a few core areas: Events, Orders, Reports, and Company settings. The quickest path to selling tickets is to finish the onboarding checklist shown on your dashboard home.

The checklist walks the account Owner through connecting payouts, adding your contact details, and setting your branding. Once every step is done the checklist disappears and your storefront is ready to take orders.

- Connect Stripe so you can receive payouts (Owner only).
- Add your support and GDPR contact emails under Settings.
- Upload your logo and set your brand colour under Settings.
- Create your first event and add at least one ticket type.
- Publish the event, then share your storefront link.', 1, 1, NOW(), NOW()),
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'getting-started'), 'where-do-i-find-my-public-storefront', 'Where do I find my public storefront?',
'Your storefront lives at a web address based on your company slug. You can open it any time from the "View storefront" link at the bottom of the sidebar — it opens in a new tab so you can see exactly what your customers see.

Only published events appear on the storefront. Draft events stay private to your team until you publish them.', 1, 2, NOW(), NOW())
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `body` = VALUES(`body`), `is_published` = VALUES(`is_published`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- Events & tickets
INSERT INTO `help_articles` (`help_category_id`, `slug`, `title`, `body`, `is_published`, `sort_order`, `created_at`, `updated_at`) VALUES
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'events'), 'creating-and-publishing-an-event', 'Creating and publishing an event',
'Open Events in the sidebar and choose "Create event". Fill in the details, set where and when it happens, then add your ticket types. An event stays a private draft until you publish it, so you can prepare everything before it goes live.

Publishing makes the event visible on your storefront and opens it for orders. You can unpublish at any time to take it off sale without deleting it.', 1, 1, NOW(), NOW()),
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'events'), 'how-capacity-works', 'How capacity works',
'Each ticket type can have its own capped capacity, or you can run several types against a single shared event capacity pool. Set the overall event capacity on the Tickets screen, next to the ticket types it governs.

Capacity is held while a customer is checking out and released automatically if they do not complete payment within the reservation window, so seats are never lost to abandoned checkouts.', 1, 2, NOW(), NOW()),
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'events'), 'free-tickets', 'Free tickets',
'Set a ticket type price to zero to make it free. Free tickets are confirmed immediately without going through payment, and the attendee still receives a QR-coded ticket by email.', 1, 3, NOW(), NOW())
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `body` = VALUES(`body`), `is_published` = VALUES(`is_published`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- Orders, refunds & check-in
INSERT INTO `help_articles` (`help_category_id`, `slug`, `title`, `body`, `is_published`, `sort_order`, `created_at`, `updated_at`) VALUES
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'orders'), 'refunding-or-cancelling-an-order', 'Refunding or cancelling an order',
'Open Orders, find the order, and use the cancel or refund action. Cancelling voids the tickets and returns the held capacity so the seats go back on sale. Refunding a paid order also returns the money to the customer through Stripe.

You can issue a partial refund one or more times up to the order total. The order stays paid with valid tickets until the refunds add up to the full amount, at which point it becomes fully refunded.', 1, 1, NOW(), NOW()),
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'orders'), 'a-customer-did-not-get-their-ticket-email', 'A customer did not get their ticket email',
'Open the order and use "Resend" to send the branded ticket email again. It reuses the same QR code as the original, so any copy the customer already has still works. You can also download the A4 ticket PDF and send it yourself.', 1, 2, NOW(), NOW()),
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'orders'), 'scanning-tickets-on-the-door', 'Scanning tickets on the door',
'The Scan tickets screen opens your phone camera in the browser and checks each ticket in as it is scanned. A ticket can only be checked in once; a second scan tells you it has already been used. Voided or unconfirmed tickets are rejected.', 1, 3, NOW(), NOW())
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `body` = VALUES(`body`), `is_published` = VALUES(`is_published`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- Payments & payouts
INSERT INTO `help_articles` (`help_category_id`, `slug`, `title`, `body`, `is_published`, `sort_order`, `created_at`, `updated_at`) VALUES
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'payments'), 'connecting-stripe-to-receive-payouts', 'Connecting Stripe to receive payouts',
'Payments run through Stripe Connect. The account Owner opens Payments in the sidebar and starts onboarding, which hands off to Stripe to collect your business and bank details. When you return, we read your account status so you know the moment you are ready to take money.

You cannot sell paid tickets until Stripe reports that charges are enabled on your connected account.', 1, 1, NOW(), NOW()),
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'payments'), 'who-pays-the-platform-fee', 'Who pays the platform fee?',
'The Owner chooses how the platform fee is handled: either your company absorbs it, or it is passed on to the customer at checkout. Changing this only affects future orders — existing orders keep the fee handling that applied when they were placed.', 1, 2, NOW(), NOW()),
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'payments'), 'when-do-i-get-paid', 'When do I get paid?',
'Money is collected on your own connected Stripe account, so payouts follow your Stripe payout schedule. Your net-to-company figure on the Reports screen is the order total less the platform fee.', 1, 3, NOW(), NOW())
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `body` = VALUES(`body`), `is_published` = VALUES(`is_published`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- Team & roles
INSERT INTO `help_articles` (`help_category_id`, `slug`, `title`, `body`, `is_published`, `sort_order`, `created_at`, `updated_at`) VALUES
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'team'), 'inviting-a-colleague', 'Inviting a colleague',
'Open Team in the sidebar and send an invitation by email. The person you invite gets a link to set up their own login. You pick their role when you invite them, which controls what they can see and do.', 1, 1, NOW(), NOW()),
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'team'), 'what-each-role-can-do', 'What each role can do',
'Roles keep sensitive actions with the people you trust with them. The Owner can do everything, including billing, payouts setup, and managing the team.

- **Owner** — full access, including company settings, team, Stripe fees and billing.
- **Admin** — events, ticket types, orders (including cancel/refund/comp), reports, check-in, GDPR handling, the activity log, the team and Stripe setup — but not billing, Stripe fees or company settings.
- **Box office** — runs events, ticketing, orders and check-in, but not reports, settings, users, Stripe, billing or GDPR.
- **Accountant** — reports and payout figures, plus managing and cancelling/refunding orders.
- **Scanner** — check-in only.', 1, 2, NOW(), NOW())
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `body` = VALUES(`body`), `is_published` = VALUES(`is_published`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- Privacy & data (GDPR)
INSERT INTO `help_articles` (`help_category_id`, `slug`, `title`, `body`, `is_published`, `sort_order`, `created_at`, `updated_at`) VALUES
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'privacy'), 'exporting-or-deleting-a-customers-data', 'Exporting or deleting a customer''s data',
'Open Customers, find the person, and use the export or anonymise tools. Export gives you a JSON copy of the personal data you hold on them. Anonymising removes their personal details while keeping the transactional records your accounts need. These tools are held by the Owner as the data controller.', 1, 1, NOW(), NOW()),
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'privacy'), 'downloading-your-own-account-data', 'Downloading your own account data',
'From Your profile you can download a JSON copy of the personal data held on your own account at any time.', 1, 2, NOW(), NOW()),
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'privacy'), 'how-customer-data-retention-works', 'How customer data retention works',
'To keep personal data no longer than you need it, customer names and email addresses are anonymised automatically once the retention period you choose has passed. This happens every night with no action from you.

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
The customer''s name and email are replaced with a placeholder on every order they placed with your company. Order references, amounts, fees, ticket and check-in records and refunds are always kept so your accounts, payouts and disputes still add up. Anonymised data cannot be recovered, so a ticket email can no longer be resent to that customer.

**Checking a customer**
Open Customers and select the person. The Data retention panel shows the date their details will be anonymised. Their data export also includes this date. If someone asks for their data to be removed sooner, use the Anonymise tool on the same page (Owners and Admins).

**Your own records**
Every automatic run is recorded in the activity log as "Auto-anonymised customer data past retention", showing how many orders were affected but never any personal details.', 1, 3, NOW(), NOW())
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `body` = VALUES(`body`), `is_published` = VALUES(`is_published`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- Troubleshooting
INSERT INTO `help_articles` (`help_category_id`, `slug`, `title`, `body`, `is_published`, `sort_order`, `created_at`, `updated_at`) VALUES
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'troubleshooting'), 'i-was-signed-out-unexpectedly', 'I was signed out unexpectedly',
'For security, sessions time out after a period of inactivity. Simply sign back in. If you suspect a session was left open on another device, use "Sign out other sessions" on Your profile to end every other session while keeping your current one.', 1, 1, NOW(), NOW()),
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'troubleshooting'), 'my-event-or-order-is-not-showing-up', 'My event or order is not showing up',
'You only ever see data for your own company. If you expect to see an event on your storefront, check it is published — drafts stay private. If an order is missing, check the status filter on the Orders screen is not hiding it.', 1, 2, NOW(), NOW()),
  ((SELECT `id` FROM `help_categories` WHERE `slug` = 'troubleshooting'), 'still-stuck', 'Still stuck?',
'If the answer is not here, raise a support request and the CK Enterprises team will help. You can optionally let us access your account to investigate faster — you choose whether to grant that when you send the request.', 1, 3, NOW(), NOW())
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `body` = VALUES(`body`), `is_published` = VALUES(`is_published`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
