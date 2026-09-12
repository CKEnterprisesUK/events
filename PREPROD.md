# Pre-production environment (cPanel Git, auto-migrate on deploy)

A staging/testing copy of the app on a **subdomain** with its **own fresh
database**, deployed straight from git. Pushing to git and triggering a cPanel
deployment runs Laravel migrations automatically — no phpMyAdmin, no manual SQL.

This is the key difference from production:

| | Production | Pre-prod (this doc) |
| --- | --- | --- |
| Schema source of truth | Hand-pasted `database/sql/*.sql` via phpMyAdmin | **Laravel migrations** (`php artisan migrate`) run on deploy |
| Deploy | Manual pull, schema by hand | `git push` → cPanel deploy → `.cpanel.yml` runs migrate |
| Database | Live prod DB | Separate, disposable DB — safe to wipe and re-migrate |

Because pre-prod uses migrations directly, it is also the place that proves the
migrations are correct **before** you generate the prod `database/sql/` file.

---

## 1. One-time cPanel setup

### 1a. Create the subdomain + document root
cPanel → **Domains / Subdomains** → create e.g. `preprod.yourdomain.tld`.
Note the document root cPanel assigns (e.g. `/home/CPANELUSER/preprod.yourdomain.tld`).
The Laravel `public/` contents are served from here; the app itself sits one
level up, exactly like your production layout.

### 1b. Create a fresh database + user
cPanel → **MySQL Databases**:
- Create a new database, e.g. `CPANELUSER_events_preprod`.
- Create a new DB user with a strong password.
- Add the user to the database with **All Privileges** (migrations need DDL:
  CREATE/ALTER/DROP).

Keep these completely separate from production. Never point pre-prod at the
prod database.

### 1c. Set up Git Version Control
cPanel → **Git™ Version Control** → **Create**:
- **Clone URL**: your repo (or create empty and push to it).
- **Repository Path**: e.g. `/home/CPANELUSER/repositories/events-preprod`
  (this is the checkout — `DEPLOYPATH` in `.cpanel.yml`).
- Check out the branch you want pre-prod to track (see section 4 — a dedicated
  `preprod` branch is recommended).

### 1d. Point the two paths in `.cpanel.yml`
Edit `.cpanel.yml` (committed at the repo root) and set:
- `DEPLOYPATH` = the Repository Path from 1c.
- `APPPATH`    = the app root for the subdomain (the parent of its `public/`,
  i.e. the directory that holds `.env`, `artisan`, `vendor/`).

The deploy copies the checkout into `APPPATH`, then runs `migrate --force` and
refreshes caches there. It never touches `.env`, the `public/storage` symlink,
or the writable `storage/` runtime dirs (rsync excludes them).

> **PHP binary:** `.cpanel.yml` calls `/opt/cpanel/ea-php85/root/usr/bin/php`.
> If the subdomain runs a different PHP version (cPanel → **MultiPHP Manager**),
> update that path in `.cpanel.yml` to match.

### 1e. Create `.env` on the host (once)
`.env` is never committed. Create it by hand in cPanel **File Manager** inside
`APPPATH`. Start from `.env.example` and set the pre-prod values in section 3.

### 1f. Storage symlink (once)
The `public/storage` symlink is not committed and must exist for uploaded
logos/posters to resolve. If cPanel's Terminal is available:
```
ln -s "$APPPATH/storage/app/public" "$APPPATH/public/storage"
```
If not, create it via a one-off `php artisan storage:link` deploy task, or use
File Manager's symlink support.

---

## 2. Cron on the pre-prod subdomain

Pre-prod needs the same background-work cron as prod, because the app relies on
a cron-drained database queue (there is no persistent worker). Add these in
cPanel → **Cron Jobs** for the subdomain, using the app path and PHP binary.

`proc_open` is disabled on shared cPanel, so cron points **directly** at the
artisan commands (not `schedule:run`, which would try to spawn child processes
and fail):

```
# Drain the DB queue (ticket emails, Stripe webhook processing) in short bursts
* * * * * cd /home/CPANELUSER/preprod.yourdomain.tld && /opt/cpanel/ea-php85/root/usr/bin/php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1

# Release capacity held by reservations whose 900s window has elapsed
* * * * * cd /home/CPANELUSER/preprod.yourdomain.tld && /opt/cpanel/ea-php85/root/usr/bin/php artisan reservations:release-expired >> /dev/null 2>&1

# Backfill actual Stripe processing fees onto paid orders missing them
*/10 * * * * cd /home/CPANELUSER/preprod.yourdomain.tld && /opt/cpanel/ea-php85/root/usr/bin/php artisan stripe:backfill-fees >> /dev/null 2>&1

# Prune audit logs past the 24-month retention window (daily)
0 3 * * * cd /home/CPANELUSER/preprod.yourdomain.tld && /opt/cpanel/ea-php85/root/usr/bin/php artisan audit:prune >> /dev/null 2>&1
```

(See `DEPLOYMENT.md` section 1 and `routes/console.php` for the rationale.)

---

## 3. Pre-prod `.env`

Copy `.env.example` and override the following. Notable differences from local
and from prod are called out.

```dotenv
APP_NAME="Event Ticketing (Pre-prod)"
APP_ENV=production          # so migrate --force runs non-interactively and debug is off
APP_DEBUG=false             # keep false; use logs, not on-screen stack traces
APP_URL=https://preprod.yourdomain.tld
APP_KEY=                    # generate once: php artisan key:generate (or paste a base64 key)

# Fresh, isolated pre-prod database from section 1b
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=CPANELUSER_events_preprod
DB_USERNAME=CPANELUSER_preprod
DB_PASSWORD=your-strong-password
DB_SOCKET=                  # set only if the host requires a unix socket

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

# Stable across deploys so issued QR tokens keep verifying. Set a fixed random
# value; do NOT reuse the production secret (keep environments isolated).
QR_HMAC_SECRET=base64:generate-a-unique-32-byte-value

# Stripe: use TEST keys for pre-prod, and a SEPARATE webhook endpoint/secret
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_CONNECT_CLIENT_ID=ca_...
STRIPE_WEBHOOK_SECRET=whsec_...   # from a webhook registered at https://preprod.yourdomain.tld/stripe/webhook

# Mail: safest is to send to a catch-all / mailtrap-style inbox so pre-prod
# never emails real customers. At minimum use non-production SMTP credentials.
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=...
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="hello@preprod.yourdomain.tld"

NOMINATIM_USER_AGENT="EventTicketingPreprod/1.0 (you@example.com)"
```

Cautions specific to pre-prod:
- **Use Stripe test keys and a separate webhook.** Never share prod Stripe
  credentials or webhook secret with staging.
- **Isolate `QR_HMAC_SECRET` and `APP_KEY`** from prod so the two environments
  can't decrypt each other's tokens/sessions.
- **Point mail somewhere safe.** A staging env that can email real customers is
  a foot-gun.

---

## 4. The workflow (day to day)

1. Do work locally, add a **Laravel migration** for any schema change (the
   normal `php artisan make:migration ...`). Migrations are the source of truth
   here — you do NOT hand-write `database/sql/` for pre-prod.
2. Commit and push. A dedicated branch keeps staging separate from prod:
   ```
   git push origin preprod
   ```
3. In cPanel → **Git Version Control** → **Manage** → **Pull or Deploy**:
   - **Update from Remote** pulls the latest commit into the checkout.
   - **Deploy HEAD Commit** runs `.cpanel.yml` (this is the step that applies
     migrations). Some hosts can auto-deploy on push; if yours does, this button
     press isn't needed.
4. Verify: load the subdomain, and check `storage/logs/laravel.log` if anything
   looks off. Migration output goes to the cPanel deployment log.

### After a deploy runs `migrate`
`config:cache`, `route:cache`, and `view:cache` are refreshed automatically by
`.cpanel.yml`. If you change `.env` by hand on the host, re-run
`php artisan config:cache` (or trigger a fresh deploy).

---

## 5. If deployment tasks don't run on your host (fallback)

cPanel Git runs `.cpanel.yml` tasks via its deployment queue. On the rare host
where that queue is disabled, migrations won't apply on deploy. Two fallbacks:

- **Cron marker:** have your deploy (or a manual touch) write
  `storage/deploy.flag`, and add a cron that applies migrations when it sees it:
  ```
  * * * * * cd /home/CPANELUSER/preprod.yourdomain.tld && [ -f storage/deploy.flag ] && (/opt/cpanel/ea-php85/root/usr/bin/php artisan migrate --force && /opt/cpanel/ea-php85/root/usr/bin/php artisan config:cache && rm storage/deploy.flag) >> storage/logs/deploy.log 2>&1
  ```
- **Manual once per deploy:** if cPanel Terminal is available, run
  `php artisan migrate --force` yourself after each pull.

---

## 6. Promoting a change to production

Pre-prod proves the migration works. To ship the same schema change to
production (which is phpMyAdmin-only), generate the matching numbered raw SQL
file under `database/sql/` from the migrated schema and follow
`database/sql/CHANGELOG.md` + `DEPLOYMENT.md` section 4. Keep the two in sync so
they never drift.

---

## 7. Rebuilding the database with sample data

Pre-prod ships with a realistic, **synthetic** dataset so you can test against
believable content — companies, events, ticket types, and orders spanning the
whole lifecycle (paid, refunded, free, reserved, scanned). It is generated by
`Database\Seeders\PreprodSeeder` from the model factories: **no real customer
PII, no live Stripe objects, no GDPR exposure.**

### Rebuild command

```
php artisan migrate:fresh --seed --force
```

This **drops every table**, re-runs all migrations, and re-seeds. Use it
whenever you want a clean, known starting point — no more pasting SQL.

- `migrate:fresh` = wipe + re-migrate. `--seed` = run `DatabaseSeeder`, which
  calls `PreprodSeeder` (plus the always-on `PlatformSettingSeeder` and
  `ReservedSlugSeeder`).
- Seeding is intentionally **lightweight** (~a few hundred ms) so it finishes
  well inside the shared-host web `max_execution_time` and can also be triggered
  from a web request without timing out.

### Known logins (all password: `password`)

| Email | Role |
| --- | --- |
| `super@preprod.test` | CK Enterprises Super_Admin (reaches `/admin`) |
| `owner@preprod.test` | Owner of the primary demo company |

Other seeded users (admins, box office, accountants, scanners) have random
faker emails; query the `users` table to find them if needed.

### Safety

- `PreprodSeeder` is guarded in `DatabaseSeeder` behind
  `! app()->environment('production')`, so **it never runs on production** even
  if `db:seed` is invoked there by accident.
- `migrate:fresh` is destructive by design — it wipes the database. Only ever
  point it at the pre-prod database. It has no place in the production process,
  which applies schema by hand via phpMyAdmin (`DEPLOYMENT.md`).

### Running it on shared hosting (no SSH)

The command is a plain in-process artisan call (no `proc_open`), so the host
*can* run it — the only question is how you trigger it without a terminal:

- If cPanel **Terminal** is available on the pre-prod account, run the command
  directly from the app path.
- Otherwise trigger it from a guarded Super_Admin control in the app (an
  `APP_ENV !== 'production'` "Rebuild sample data" action). Keep the dataset
  small (as shipped) so the web request completes inside `max_execution_time`.
