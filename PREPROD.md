# Pre-production environment (cPanel Git, auto-migrate on deploy)

A staging/testing copy of the app on a **subdomain** with its **own fresh
database**. You deploy by pushing to git, pulling in cPanel ("Update from
Remote"), then applying migrations from the in-app build page (`/admin/ops`) —
no phpMyAdmin, no manual SQL, no terminal.

Pre-prod and production now follow the **same** deploy flow. The differences:

| | Production | Pre-prod (this doc) |
| --- | --- | --- |
| Schema source of truth | **Laravel migrations** | **Laravel migrations** |
| Apply schema | `/admin/ops` → Run migrations (backup + confirm first) | `/admin/ops` → Run migrations |
| Database | Live prod DB | Separate, disposable DB — safe to wipe and re-migrate |
| Rebuild sample data | ❌ blocked | ✅ available |

Pre-prod is where you prove a migration is correct **before** running the same
migration on production. Both environments apply schema the same way — from the
`/admin/ops` build page — so there is no separate raw-SQL path to keep in sync.

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

### 1d. `.cpanel.yml` runs no deploy tasks (by design)
`.cpanel.yml` is intentionally a no-op (`/bin/true`). It does **not** copy files
or run migrations on deploy. Earlier versions ran `migrate`/`cache` on
"Deploy HEAD Commit", but those tasks wrote into the checkout and the shared
host disables `proc_open`/`shell_exec`, so the tree ended up "dirty" and blocked
the next deploy. Migrations now run **in-process from the browser** via the
build page — see section 4.

You only need to point the cPanel subdomain's document root at the checkout's
`public/` directory; there is nothing to configure inside `.cpanel.yml`.

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
APP_ENV=staging             # MUST be 'staging' (NOT 'production'). This is what
                            # distinguishes pre-prod from prod: the /admin/ops
                            # migrate + reseed tools are enabled ONLY when
                            # APP_ENV is staging/preprod/local/development, and
                            # refuse when it is 'production'. Production keeps
                            # APP_ENV=production, so it never gets those tools.
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

This is the **same** flow as production (`DEPLOYMENT.md` section 4) — the only
difference is that pre-prod also lets you rebuild sample data (section 7).

1. Do work locally, add a **Laravel migration** for any schema change (the
   normal `php artisan make:migration ...`). Migrations are the source of truth
   on **both** environments now — there is no hand-written `database/sql/`.
2. Commit and push. A dedicated branch keeps staging separate from prod:
   ```
   git push origin preprod
   ```
3. In cPanel → **Git Version Control** → **Manage** → click **Update from
   Remote**. This pulls the latest commit into the checkout and runs **no**
   deploy tasks (so it can't dirty the tree).
4. Log in as a **Super_Admin**. If the pulled code added a migration you are
   **routed automatically to the build page** (`/admin/ops`) with a banner
   "There are pending database migrations." Click **Run migrations**, then
   **Clear caches**. (You can also open **`/admin/ops`** manually any time.)
5. Verify: load the subdomain, and check `storage/logs/laravel.log` if anything
   looks off.

### After every "Update from Remote" — clear the caches
`.cpanel.yml` is intentionally a no-op (`/bin/true`) — pulling code runs NO
deploy tasks, so it does **not** rebuild any caches. If the app is running with
a compiled **route** cache, a route added in the pulled code will not resolve
and you'll get `Route [...] not defined` (and likewise stale config/views).

So after each **Update from Remote**, clear the caches:

- Log in as Super_Admin → open **`/admin/ops`** → click **"Clear caches"**.
  This runs `config:clear`, `route:clear`, `view:clear`, `event:clear`
  in-process (no terminal needed). It is non-destructive — it never touches the
  database or schema — so it is always safe to run.

If you change `.env` by hand on the host, click "Clear caches" too (or, where a
terminal is available, run `php artisan config:clear`).

> Migrations are separate: run them from the same `/admin/ops` page ("Run
> migrations"). Clearing caches does not apply schema changes.

---

## 5. If deployment tasks don't run on your host (fallback)

Migrations run in-process from the browser (`/admin/ops` → "Run migrations"), so
they do **not** depend on cPanel's deploy queue at all — the build page uses the
normal web request. If that page is ever unreachable and cPanel **Terminal** is
available, the manual fallback is the same command it runs:

```
php artisan migrate --force
```

---

## 6. Promoting a change to production

Pre-prod proves the migration works. Production now uses the **same** migrations
and the **same** build page — there is no separate raw-SQL step. To ship a
tested change:

1. Merge `preprod` → `main` and push.
2. cPanel Git (prod repo) → **Update from Remote**.
3. Log in as Super_Admin on production. If migrations are pending you are routed
   to `/admin/ops`. **Take a database backup first** (cPanel → Backup, or
   phpMyAdmin → Export), tick the confirmation, then **Run migrations** and
   **Clear caches**.

See `DEPLOYMENT.md` section 4 for the production specifics (backup, confirmation).

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
