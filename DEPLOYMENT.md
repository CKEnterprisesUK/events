#  (shared cPanel, no SSH)

Production runs on shared cPanel hosting: **no SSH access, no persistent
process**. There is no long-running queue worker and no artisan-over-SSH deploy
step. All background work is drained by a single per-minute cron, and the
database schema is applied from the in-app build page (Laravel migrations run
in-process by a Super_Admin — see section 4). This document is the
operational checklist for standing the app up and keeping it healthy.

Referenced by task 26.1 (Design → *Hosting and Deployment Notes*).

---

## 1. Cron scheduler + queue draining

There is **one** cron entry. It runs Laravel's scheduler every minute; the
scheduler both fires scheduled jobs and drains the database queue. No daemon is
required.

Add this line in cPanel → *Cron Jobs* (replace the path with the app root):

```
* * * * * cd /home/USER/events.domain && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler (`routes/console.php`) registers two per-minute tasks, both
`withoutOverlapping()`:

| Scheduled task | Purpose |
| --- | --- |
| `queue:work --stop-when-empty --max-time=50` | Drains the DB queue in a short burst — processes every pending job (ticket emails, Stripe webhook processing, capacity release) then exits instead of daemonising. `--max-time=50` caps a burst so a flood never overruns the next tick. |
| `ReleaseExpiredReservationsJob` (`everyMinute`) | Sweeps reservations whose 900-second hold has elapsed and returns held capacity to each ticket type. |

> **Note:** where `proc_open` is disabled on the host (common on shared cPanel),
> `schedule:run` cannot spawn its child processes. In that case, point cron
> **directly** at the artisan commands instead, as documented in
> `routes/console.php` (`queue:work`, `reservations:release-expired`).

**Audit-log retention (daily).** The audit trail is pruned to a 24-month
retention window by a separate **daily** cron. It runs the job synchronously,
so it works whether or not `schedule:run` is usable:

```
0 3 * * * cd /home/USER/events.domain && php artisan audit:prune >> /dev/null 2>&1
```

Because `queue:work` is invoked by the scheduler rather than run as a daemon,
the cron line above is the only thing that must be configured on the host.

**Queue configuration.** `QUEUE_CONNECTION=database`. The `jobs`, `job_batches`,
and `failed_jobs` tables are created by the Laravel migrations applied via the
build page (see section 4). Failed jobs are inspected and retried later with
`queue:retry` — failures are retryable, not lost. (Requirements 15.1–15.4)

---

## 2. Storage symlink (logo exposure)

Uploaded company/event logos are stored on the `public` disk
(`storage/app/public`) and served under `/storage/...`. That path only resolves
if the public symlink exists:

```
php artisan storage:link
```

On cPanel where `storage:link` is restricted, create the symlink manually so
`public/storage` points at `storage/app/public`:

```
ln -s /home/USER/events.domain/storage/app/public /home/USER/events.domain/public/storage
```

The symlink itself is environment-specific and not committed. `logo_path`
values resolve to a public URL via the `public` disk, so once the symlink is in
place logos render on the storefront and on tickets. (Requirement 7.1)

---

## 3. Stripe webhook endpoint

Register a single webhook in the Stripe dashboard pointing at:

```
POST https://events.domain/stripe/webhook
```

This endpoint is deliberately outside tenant resolution and CSRF:

- **No tenant slug.** The route is registered at the reserved `/stripe/webhook`
  prefix in `routes/web.php`, outside the `tenant` middleware group, so it
  resolves no Company.
- **CSRF-exempt.** `bootstrap/app.php` lists `stripe/webhook` in
  `validateCsrfTokens(except: [...])`. Stripe posts with no session/CSRF token;
  authenticity is established by the Stripe **signature** instead (the
  `stripe.webhook` middleware verifies it before the controller runs).

The controller dedupes on the Stripe event id (`processed_webhooks` table) and
enqueues heavy work, returning 2xx quickly. (Requirements 19.1–19.5)

---

## 4. Database schema via the in-app build page (no SSH, no phpMyAdmin)

Laravel migrations are the single source of truth in **every** environment,
production included. Because the host has no terminal and disables
`proc_open`/`shell_exec`, migrations are applied **in-process from the browser**
by a Super_Admin — the same PHP process that serves the app runs
`migrate --force`. There is no raw-SQL paste step and no `database/sql/` file to
keep in sync.

### Deploying a change to production

1. Merge the tested branch into `main` and push.
2. cPanel → **Git Version Control** → **Update from Remote** (pulls code, runs
   no deploy tasks — see `.cpanel.yml`).
3. Log in as a **Super_Admin**. When the pulled code contains an un-run
   migration you are **routed automatically to the build page** (`/admin/ops`),
   which shows the pending migrations and full `migrate:status`.
4. **Take a database backup first** — cPanel → *Backup* (or phpMyAdmin →
   *Export*). Migrations run against the live DB and have no automatic undo.
5. Tick the confirmation checkbox and click **Run migrations**, then click
   **Clear caches** so newly pulled routes/config/views are picked up.

### Safety model

- **`migrate --force`** and **Clear caches** are available in every environment.
  The production migrate button is gated behind a typed confirmation + backup
  reminder in the UI.
- **Rebuild sample data** (`migrate:fresh --seed`, destructive) is **permanently
  refused in production** — its route isn't registered there and `OpsController`
  re-checks the `APP_ENV` allow-list. It exists only for pre-prod (`PREPROD.md`).
- A Super_Admin is only redirected to the build page when migrations are
  genuinely pending; the check fails safe (never traps you) and is cached
  briefly. See `App\Services\PendingMigrations` and the
  `migrations.pending` middleware.

### Fresh production database

On a brand-new prod DB, the same **Run migrations** action builds the entire
schema (including the Laravel-managed `migrations`, `jobs`, `job_batches`,
`failed_jobs` tables and `processed_webhooks`). Seed the one required
`platform_settings` row via the normal `PlatformSettingSeeder` (run locally
against the prod DB during setup, or insert the single row by hand once).

---

## 5. Required environment (`.env`, outside the web root)

Keep `.env` above/outside the public web root. See `.env.example` for the full
template. The values that matter for a correct, secure production deploy:

| Variable | Notes |
| --- | --- |
| `APP_KEY` | Generated once (`php artisan key:generate`). Keep stable. |
| `APP_ENV=production`, `APP_DEBUG=false` | Never expose debug in prod. |
| `QUEUE_CONNECTION=database` | Cron-drained DB queue (section 1). |
| `DB_*` | cPanel MySQL host / database / user / password. |
| `QR_HMAC_SECRET` | **MUST be fixed and stable across deploys.** See below. |
| `STRIPE_SECRET`, `STRIPE_KEY`, `STRIPE_CONNECT_CLIENT_ID` | Stripe Connect + Checkout. |
| `STRIPE_WEBHOOK_SECRET` | Signing secret for `/stripe/webhook` verification. |
| `MAIL_MAILER=smtp` + `MAIL_*` | cPanel SMTP credentials for ticket email. |

### HMAC secret stability (critical)

Every order's QR token is an HMAC of its order reference computed with
`QR_HMAC_SECRET` (`config/qr.php`). If this secret changes between deploys,
**every previously issued QR code stops verifying at scan time.**

- Set `QR_HMAC_SECRET` to a fixed, high-entropy value in production and never
  rotate it once tickets have been issued.
- `config/qr.php` reads `env('QR_HMAC_SECRET')` and falls back to `APP_KEY`
  only for local/testing convenience. In production set `QR_HMAC_SECRET`
  explicitly so it is not coupled to `APP_KEY` regeneration.

Likewise `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET` are env-driven
(`config/stripe.php`) — set them once from the Stripe dashboard and keep them
stable. (Requirements 14.2, 16.4, 19.1; Property 23)

---

## Deploy checklist

- [ ] `.env` present outside web root with all vars from section 5.
- [ ] `QR_HMAC_SECRET` set to a fixed value (not regenerated).
- [ ] Database backup taken, then schema applied via `/admin/ops` → Run migrations.
- [ ] Storage symlink created (`public/storage` → `storage/app/public`).
- [ ] Cron entry added (`* * * * * ... php artisan schedule:run`).
- [ ] Stripe webhook registered at `POST /stripe/webhook` with signing secret.
- [ ] `config:cache` / `route:cache` refreshed after any `.env` change.
