# Design Document

## Overview

This feature adds a JSON API to the existing Laravel platform and a native Swift/SwiftUI iOS client that together let event staff sign in, scan tickets, watch a live check-in dashboard for the active event, and search customers. The platform today serves only session-based web auth: there is no `routes/api.php`, no Sanctum, and `User` carries a single `company_id`.

The design makes one architectural promise above all others: **the scan decision tree is written once and shared.** The web scanner's `ScanController` already encodes the full Scan_Outcome decision tree (`resolve` → `checkIn` → `alreadyScanned`/`breakdown`). That logic is extracted into a `TicketCheckInService` that both the existing web controller and the new API call, so the two surfaces can never diverge on what "already scanned" or "rejected" means. Everything else — Sanctum auth, per-device TOTP trust, token-based tenant scoping, role-gated endpoints — is new plumbing wrapped around that shared core and around the services that already exist (`QrService`, `RoleAuthorization`, `EventLiveController`'s snapshot, `CustomerController`'s roster query).

The iOS app owns only what must live on the device: the camera, Vision QR decoding, Keychain token storage, a stable device identifier, and a manual-reference fallback. No verification happens on the device; every scan is a round trip to the API (Requirement 9).

**Satisfies:** the Introduction and the cross-cutting intent of Requirements 1–12.

### Key decisions grounded in the codebase

- **Reuse, don't reimplement.** `ScanController::resolve/checkIn/alreadyScanned/breakdown` is already pure of HTTP concerns and returns a structured array. We lift it verbatim into a service rather than re-deriving the decision tree. `QrService` already does HMAC verify with constant-time comparison; the API never touches the secret directly.
- **Membership is a new concept.** `User` has one `company_id`, and `ResolveDashboardTenant` binds exactly that company for the web. Requirement 4 ("one or more companies", a company picker, switching) needs a many-to-many. We add a `company_user` membership pivot and a `ResolveApiTenant` middleware that binds the Active_Company from the token — mirroring `ResolveDashboardTenant` but driven by the token's selected company rather than `user.company_id`. The web path is untouched.
- **Tenant scoping is already centralised.** `TenantContext` + the `BelongsToCompany` global scope confine every Company-owned query. The API reuses them unchanged: the new middleware just calls `TenantContext::setCompany()` with the token's company, so `Order`, `ScanEvent`, etc. scope automatically, exactly as the web dashboard does.
- **2FA is already a service.** `TwoFactorAuthenticationService::verifyTotp()` + `useRecoveryCode()` and `User::hasTwoFactorEnabled()` are reused as-is. The API adds only device-trust persistence and token issuance around them.

## Architecture

### Component overview

```mermaid
graph TD
    subgraph iOS["iOS App (SwiftUI, iOS 16+)"]
        UI[Views: SignIn, TwoFactor, CompanyPicker, Scan, Dashboard, CustomerSearch]
        VM[ViewModels / @Observable state]
        NET[APIClient networking layer]
        KC[KeychainStore - token]
        DEV[DeviceIdentity - stable device id]
        CAM[CameraScanner - AVCapture + Vision QR]
        UI --> VM --> NET
        NET --> KC
        NET --> DEV
        VM --> CAM
    end

    subgraph API["Laravel API (routes/api.php, api middleware group)"]
        AUTH[AuthController: login, 2FA challenge, logout, me]
        CO[CompanyController: memberships, select/switch]
        SCAN[ScanApiController: check-in]
        DASH[DashboardApiController: live snapshot]
        CUST[CustomerApiController: search]
    end

    subgraph MW["Middleware"]
        SANCTUM[auth:sanctum]
        TENANT[ResolveApiTenant + EnforceTenantScope]
        ABILITY[token ability + role Gate]
    end

    subgraph SVC["Shared services (existing + new)"]
        CHECKIN[TicketCheckInService - extracted decision tree]
        QR[QrService - HMAC verify]
        ROLE[RoleAuthorization - permission matrix]
        TOTP[TwoFactorAuthenticationService]
        TRUST[DeviceTrustService - new]
        SNAP[EventSnapshotService - extracted from EventLiveController]
        TC[TenantContext + BelongsToCompany scope]
    end

    NET -- HTTPS/TLS + Bearer token --> SANCTUM
    SANCTUM --> TENANT --> ABILITY
    ABILITY --> AUTH & CO & SCAN & DASH & CUST
    AUTH --> TOTP & TRUST
    SCAN --> CHECKIN
    CHECKIN --> QR
    CHECKIN --> TC
    DASH --> SNAP
    CUST --> ROLE
    SCAN --> ROLE & DASH --> ROLE
```

**Satisfies:** Requirements 1–12 (component responsibilities), 9.3 (no HMAC on device — `QrService` stays server-side), 12.3 (tenant scope on every data request).

### Request lifecycle for an authenticated API call

1. TLS terminates at the edge; `EnforceHttps` (new, API group) rejects any non-TLS request (Requirement 12.2).
2. `auth:sanctum` resolves the token → the `User`; a revoked/missing token yields 401 (Requirements 3.3, 12.5).
3. `ResolveApiTenant` reads the token's selected `company_id`, verifies the user is a member, binds it via `TenantContext::setCompany()`, and `EnforceTenantScope` clears it after the response. Any Company-owned query is now scoped (Requirements 4.1, 4.3, 12.3).
4. The controller authorises the action against `RoleAuthorization` (via the existing `Gate`) before touching data (Requirements 5.2, 7.5, 8.3).

### Middleware registration

`bootstrap/app.php` gains an `api` routing file and group. Because the existing file already configures middleware in the `withMiddleware` closure, we add:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',   // NEW
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

```php
// inside withMiddleware(...)
$middleware->api(prepend: [
    \App\Http\Middleware\EnforceHttps::class,   // Requirement 12.2
]);

$middleware->group('api.tenant', [
    \App\Http\Middleware\EnforceTenantScope::class,   // reused unchanged
    \App\Http\Middleware\ResolveApiTenant::class,     // new, token-driven; also enforces device binding
]);
```

`ResolveApiTenant` runs after `auth:sanctum` and, in addition to binding the Active_Company, enforces the token's device binding (Requirements 13.3, 13.4): it compares the request's `X-Device-Id` header against the `device_id` stored on the resolving `personal_access_tokens` row and aborts 401 on mismatch before any Company query runs. Keeping the check here means every authenticated API route inherits device binding without per-controller code.

Sanctum is installed and configured first (it is not present today):

```
composer require laravel/sanctum
php artisan vendor:publish --tag=sanctum-config
php artisan migrate   # creates personal_access_tokens
```

`User` gains the `Laravel\Sanctum\HasApiTokens` trait.

**Token expiry (Requirement 13.1, 13.2).** Sanctum rejects expired tokens inside `auth:sanctum` exactly as it rejects missing/revoked ones, so expiry needs no new code — only configuration. We set the Token_Lifetime in `config/sanctum.php`:

```php
// config/sanctum.php — the configurable Token_Lifetime (minutes); null = never
'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 60 * 24 * 7), // e.g. 7 days
```

`'expiration'` is global; where a shorter per-token life is wanted (e.g. a password-only token issued before 2FA), the issuing call passes an explicit `expiresAt` to `createToken(...)`, which Sanctum stores in the `expires_at` column and honours over the global value. Either way an elapsed token is treated identically to a revoked one at `auth:sanctum` (Requirement 13.2).

**Named rate limiters (Requirement 15).** `RateLimiter::for(...)` declarations live in a service provider (`AppServiceProvider::boot` / the API bootstrap), mirroring how Laravel's default limiters are registered, and are attached as `throttle:{name}` middleware per route:

```php
RateLimiter::for('login', fn (Request $r) => [            // Requirement 15.3 (kept)
    Limit::perMinute((int) config('api.throttle.login'))->by($r->input('email').'|'.$r->ip()),
]);
RateLimiter::for('2fa', fn (Request $r) =>                // Requirement 15.1
    Limit::perMinute((int) config('api.throttle.2fa'))->by($r->input('email').'|'.$r->ip()));
RateLimiter::for('scan', fn (Request $r) =>              // Requirement 15.2 (per operator)
    Limit::perMinute((int) config('api.throttle.scan'))->by((string) $r->user()?->getKey()));
```

All three limits are configurable via `config/api.php` (env-backed), so door throughput and anti-guessing windows can be tuned per deployment without code changes.

**Satisfies:** Requirements 3.3, 4.1, 12.2, 12.5, 13.1–13.4, 15.1–15.3, and the Introduction's "add Sanctum + routes/api.php".

## Components and Interfaces

### Backend

#### `TicketCheckInService` (new — the shared decision tree)

The heart of the DRY refactor. `ScanController`'s private `resolve`, `checkIn`, `alreadyScanned`, and `breakdown` methods move here **unchanged in behaviour**; the operator identity (`scanned_by`, `operator_id`, `operator_name`) is passed in rather than read from `Auth` so both web and API callers supply their own authenticated user. `ScanEvent` recording also moves here so every caller records identically (Requirement 5.10, 7's feed source).

```php
final class TicketCheckInService
{
    public function __construct(private QrService $qr) {}

    /**
     * Resolve a scanned QR_Payload OR a manually typed Order_Reference to a
     * Scan_Outcome, performing the atomic single check-in when valid, and
     * recording a ScanEvent. Runs entirely under the active TenantContext,
     * so Orders of other Companies never match.
     */
    public function resolve(string $payload, Operator $operator): ScanResult;
}

/** The structured outcome — identical shape to the web controller's array. */
final class ScanResult
{
    public string $status;            // checked_in|already_scanned|rejected|failed|invalid|unreadable
    public string $message;
    public ?Order $order;
    public ?Carbon $scannedAt;
    public ?string $scannedByName;
    /** @var list<array{name:string, quantity:int}> */
    public array $breakdown;
}
```

`ScanController::scan` becomes a thin adapter: build an `Operator` from `Auth::user()`, call the service, keep its session-recap/HTML-fragment rendering. The API controller builds an `Operator` from the token's user and serialises `ScanResult` to JSON. The decision tree, the `UPDATE ... WHERE scanned_at IS NULL` atomic guard (Requirement 5.9), the tenant-scoped load (5.5), the confirmed check (5.6), and the manual-vs-QR separator split (6.1) all live in one place.

**Satisfies:** Requirements 5.3–5.10, 6.1, 6.2, and the Introduction's single-source-of-truth refactor goal.

#### `DeviceTrustService` (new)

Records and queries per-user device trust. A device id is a client-supplied opaque string (see iOS `DeviceIdentity`) sent as the `X-Device-Id` header and in the login/challenge bodies.

```php
final class DeviceTrustService
{
    public function isTrusted(User $user, string $deviceId): bool;
    public function trust(User $user, string $deviceId, ?string $name): TrustedDevice;
    public function forget(User $user, string $deviceId): void;
}
```

Trust is stored in a new `trusted_devices` table (see Data Models). A device is trusted only after a successful TOTP challenge (Requirement 2.1); subsequent logins from that `(user, device)` pair skip the challenge (Requirements 1.4, 2.4).

#### `AuthController`

```php
POST /api/auth/login          // email, password, device_id -> token | 2fa_required | 422
POST /api/auth/two-factor     // email, password, device_id, code|recovery_code -> token | 422
POST /api/auth/logout         // (auth) revokes current token
GET  /api/auth/me             // (auth) current user + permitted actions + memberships
```

`login` validates credentials with `Auth::validate()` (no session is created — the API is stateless/token-based). On success:
- If `!user->hasTwoFactorEnabled()` OR `DeviceTrustService::isTrusted(user, deviceId)` → issue a token immediately (Requirements 1.1, 1.4).
- Else respond `{ "status": "2fa_required" }` with **no token** (Requirement 1.3).

`two-factor` re-validates credentials, then `TwoFactorAuthenticationService::verifyTotp()` (or `useRecoveryCode()`). On success it trusts the device and issues a long-lived token (Requirements 2.1, 2.2); on failure it returns 422 and issues nothing (Requirement 2.3). Invalid credentials anywhere return a generic auth error and no token (Requirement 1.2). Failed login/2FA are audited via the existing `AuditLogger` actions already used by the web controllers.

Token abilities encode the user's permitted actions from `RoleAuthorization::permittedActions($user->role)` (e.g. `check_in`, `orders`) plus a `company:{id}` ability naming the selected Active_Company. `logout` calls `$request->user()->currentAccessToken()->delete()` (Requirements 3.1, 3.3, 12.5).

**Satisfies:** Requirements 1.1–1.4, 2.1–2.4, 3.1, 3.3.

#### `CompanyController`

```php
GET  /api/companies            // (auth) list the user's memberships
POST /api/companies/{id}/select  // (auth) set Active_Company; rebuild token ability -> company:{id}
```

Memberships come from the `company_user` pivot. Selecting a company the user is **not** a member of returns 403 and leaves the Active_Company unchanged (Requirement 4.5). Selecting/switching re-mints the token's `company:{id}` ability (or updates a `current_company_id` column on the token's owning row — see Data Models) so every later request re-scopes (Requirements 4.3, 4.4). A single-membership user is handled client-side by auto-selecting without a picker (Requirement 4.6); the server still enforces membership.

**Satisfies:** Requirements 4.2–4.6.

#### `ScanApiController`

```php
POST /api/scan   // (auth, api.tenant, ability:check_in) { payload? , reference? } -> ScanResult JSON
```

Gated with `Gate::authorize(RoleAuthorization::ACTION_CHECK_IN)` exactly as the web scanner (Requirements 5.1, 5.2). Accepts either a scanned `payload` (QR_Payload) or a manual `reference`; both funnel through `TicketCheckInService::resolve`. The JSON mirrors the Scan_Outcome contract below.

**Satisfies:** Requirements 5.1–5.10, 6.1–6.2.

#### `DashboardApiController`

```php
GET /api/dashboard/live   // (auth, api.tenant, ability:check_in) -> live snapshot for Active_Event
```

The snapshot logic is extracted from `EventLiveController::snapshot()` into an `EventSnapshotService` so web and API share it; the API variant **omits** `sold`/revenue framing beyond counts and returns only attendance figures and recent `ScanEvent`s (Requirements 7.2, 7.3, 7.4). It is gated on `ACTION_CHECK_IN` (the API's dashboard is a door tool), not `ACTION_VIEW_REPORTS` as the web one is — this is the one deliberate authorisation difference, noted because the requirement names `ACTION_CHECK_IN` (Requirements 7.1, 7.5). The Active_Event is the Active_Company's most imminent/in-progress published, non-cancelled event (`starts_at` nearest now), resolved tenant-scoped.

**Satisfies:** Requirements 7.1–7.5.

#### `CustomerApiController`

```php
GET /api/customers?q=term  // (auth, api.tenant, ability:orders) -> paginated customer rows
```

Reuses `CustomerController::index`'s aggregate query (name/email search, order count, confirmed spend), gated on `ACTION_MANAGE_ORDERS` (Requirements 8.1, 8.2, 8.3). The query already runs under the tenant scope, so no cross-company rows leak (Requirement 12.3).

**Satisfies:** Requirements 8.1–8.3.

### iOS client

```
EventsScanner/
  App/            EventsScannerApp.swift, AppState (@Observable)
  Networking/     APIClient, APIError, Endpoints, DTOs (Codable)
  Auth/           AuthStore, KeychainStore, DeviceIdentity
  Features/
    SignIn/       SignInView + ViewModel
    TwoFactor/    TwoFactorView + ViewModel
    Company/      CompanyPickerView + ViewModel
    Scan/         ScanView, CameraScanner (AVCapture+Vision), ManualEntryView
    Dashboard/    DashboardView + ViewModel (polls /dashboard/live)
    Customers/    CustomerSearchView + ViewModel (role-gated)
  Common/         Reachability, Views
```

- **`APIClient`** sets `Authorization: Bearer <token>` and `X-Device-Id`, enforces HTTPS base URL, decodes DTOs, and maps non-2xx into `APIError` (offline, unauthorized, forbidden, serverMessage). On 401 it clears the Keychain token and routes to sign-in (Requirements 3.2, 3.3).
- **`KeychainStore`** persists only the Access_Token (`kSecClassGenericPassword`, device-only accessibility). The password is never stored (Requirements 12.1, 12.4).
- **`DeviceIdentity`** generates a UUID once, stores it in the Keychain, and returns it stably thereafter — the device id the API trusts (Requirements 2.1, 2.4). It is not `identifierForVendor` (which can reset on reinstall); a Keychain-backed UUID survives reinstalls so trust persists per the requirement's intent.
- **`CameraScanner`** requests camera permission (Requirement 10.1); on denial it shows "camera access is required" and does not scan (Requirement 10.2). While granted it runs an `AVCaptureSession` feeding frames to `VNDetectBarcodesRequest` (symbology `.qr`); a decoded payload is submitted to `/api/scan` (Requirements 10.3, 10.4). No HMAC/verification runs on device (Requirement 9.3).
- **`ManualEntryView`** is the always-available fallback that posts `reference` instead of `payload` (Requirements 6.3, 6.2).
- **Role visibility** is driven entirely by the server: `/api/auth/me` returns `permitted_actions`. The app shows Scan + Dashboard when `check_in` is present and shows Customer Search only when `orders` is present, hiding it otherwise (Requirements 8.4, 8.5).
- **Offline handling**: `Reachability` + a failed request surface an offline message and never report a successful check-in; scans are not queued (Requirements 9.1, 9.2).
- **Flow**: SignIn → (optional TwoFactor) → (CompanyPicker only when memberships > 1) → TabView(Scan, Dashboard, [Customer Search if permitted]) (Requirements 4.2, 4.6, 8.4, 8.5).
- **Platform/distribution**: deployment target iOS 16.0; distributed via App Store / TestFlight (Requirements 11.1, 11.2).

**Satisfies:** Requirements 3.2, 6.3, 8.4, 8.5, 9.1–9.3, 10.1–10.4, 11.1, 11.2, 12.1, 12.4.

## Sequence Diagrams

### Login + 2FA + token issuance

```mermaid
sequenceDiagram
    participant App as iOS App
    participant API as AuthController
    participant Trust as DeviceTrustService
    participant TOTP as TwoFactorAuthenticationService
    participant KC as Keychain

    App->>API: POST /auth/login {email, password, device_id}
    alt invalid credentials
        API-->>App: 422 auth error (no token)
    else 2FA disabled OR device trusted
        API->>Trust: isTrusted(user, device_id)
        Trust-->>API: true / n/a
        API-->>App: 200 {token}
        App->>KC: store token
    else 2FA enabled AND device not trusted
        API-->>App: 200 {status:"2fa_required"} (no token)
        App->>API: POST /auth/two-factor {email, password, device_id, code}
        API->>TOTP: verifyTotp(user, code)
        alt code invalid
            TOTP-->>API: false
            API-->>App: 422 (no token)
        else code valid
            TOTP-->>API: true
            API->>Trust: trust(user, device_id)
            API-->>App: 200 {token} (long-lived)
            App->>KC: store token
        end
    end
```

### Scan to check-in

```mermaid
sequenceDiagram
    participant Cam as CameraScanner (Vision)
    participant App as iOS App
    participant MW as auth:sanctum + ResolveApiTenant + Gate
    participant Svc as TicketCheckInService
    participant QR as QrService
    participant DB as Orders / ScanEvents

    Cam->>App: decoded QR_Payload (or manual reference)
    App->>MW: POST /api/scan (Bearer token, X-Device-Id)
    MW->>MW: resolve user, bind Active_Company, authorize check_in
    alt not permitted
        MW-->>App: 403 (data unchanged)
    else permitted
        MW->>Svc: resolve(payload, operator)
        Svc->>QR: verifyPayload(payload)  %% skipped for manual reference
        alt undecodable
            Svc-->>App: {status:"unreadable"}
        else HMAC mismatch
            Svc-->>App: {status:"invalid"}
        else verified reference
            Svc->>DB: load Order (tenant-scoped)
            alt not found / foreign company
                Svc-->>App: {status:"rejected"}
            else not confirmed
                Svc-->>App: {status:"failed"}
            else already scanned
                Svc-->>App: {status:"already_scanned", scanned_at, operator}
            else atomic UPDATE ... WHERE scanned_at IS NULL
                Svc->>DB: 1 row -> checked_in; 0 rows -> already_scanned (winner)
                Svc->>DB: record ScanEvent
                Svc-->>App: {status:"checked_in", breakdown[]}
            end
        end
    end
```

**Satisfies:** Requirements 1.1–1.4, 2.1–2.4 (first diagram); 5.3–5.10, 6.1, 6.2 (second diagram).

## Data Models

### New: `trusted_devices`

Records a `(user, device)` pair that has passed a TOTP challenge, so later sign-ins from that device skip it (Requirements 2.1, 2.4). Not Company-owned (trust is per user, independent of Active_Company).

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| user_id | bigint fk → users, cascade on delete | |
| device_id | string (indexed) | client-supplied opaque id |
| name | string null | optional device label |
| trusted_at | timestamp | |
| last_used_at | timestamp null | |
| timestamps | | |

Unique index on `(user_id, device_id)`.

### New: `company_user` membership pivot

Requirement 4 needs a user to belong to one or more companies. `User.company_id` stays (the web dashboard still uses it as the user's home company and `ResolveDashboardTenant` is untouched). The pivot adds additional memberships and the role held per company.

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| company_id | bigint fk → companies, cascade | |
| user_id | bigint fk → users, cascade | |
| role | string | one of `User::ROLES`, per-company role |
| timestamps | | |

Unique index on `(company_id, user_id)`. `User` gains `companies(): BelongsToMany`. A data backfill seeds one membership row from each existing `(user.company_id, user.role)` so current users keep working. For the API, the token's Active_Company resolves the role from this pivot, which `RoleAuthorization` then evaluates.

### Reused: `personal_access_tokens` (Sanctum)

Created by Sanctum's migration. We add a nullable `current_company_id` column (or encode it as a token ability) so a token carries its Active_Company; `CompanyController::select` updates it and re-mints abilities (Requirements 4.3, 4.4).

### Reused unchanged

- **`users`** — `two_factor_secret`, `two_factor_confirmed_at`, `password` (hashed), `role`. `HasApiTokens` trait added.
- **`orders`** — `order_reference`, `status`, `scanned_at`, `scanned_by`, `company_id`. The atomic check-in writes `scanned_at`/`scanned_by`.
- **`scan_events`** — the breadcrumb the dashboard feed reads; written by `TicketCheckInService` (Requirement 5.10).
- **`companies`** — tenant root.

**Satisfies:** Requirements 2.1, 2.4, 4.2–4.6, 5.10.

## API Endpoints

| Method | Path | Auth / Gate | Request | Success | Requirements |
|---|---|---|---|---|---|
| POST | /api/auth/login | public, throttle:login | `email, password, device_id` | `{token}` or `{status:"2fa_required"}` | 1.1–1.4 |
| POST | /api/auth/two-factor | public, throttle:2fa | `email, password, device_id, code` or `recovery_code` | `{token}` | 2.1–2.4 |
| POST | /api/auth/logout | auth:sanctum | — | `204` | 3.1, 3.3 |
| GET | /api/auth/me | auth:sanctum | — | `{user, permitted_actions[], companies[]}` | 8.4, 8.5 |
| GET | /api/companies | auth:sanctum | — | `[{id, name, role}]` | 4.2 |
| POST | /api/companies/{id}/select | auth:sanctum | — | `{active_company}` or `403` | 4.3–4.6 |
| POST | /api/scan | auth:sanctum, api.tenant, ability:check_in | `payload` or `reference` | `ScanResult` | 5.1–5.10, 6.1, 6.2 |
| GET | /api/dashboard/live | auth:sanctum, api.tenant, ability:check_in | — | snapshot | 7.1–7.5 |
| GET | /api/customers | auth:sanctum, api.tenant, ability:orders | `q` | paginated rows | 8.1–8.3 |

### Scan_Outcome contract

`POST /api/scan` returns one of six statuses with status-specific fields. HTTP status is `200` for every resolved outcome (the outcome is the payload), `403` only when the role lacks `check_in`, `401` for a bad token.

```jsonc
// checked_in
{ "status": "checked_in", "message": "Checked in.",
  "reference": "ABC123DEF456", "customer": "Sam Lee",
  "scanned_at": "2025-01-01T19:30:00+00:00",
  "breakdown": [ { "name": "General", "quantity": 2 } ] }

// already_scanned  (carries original scanned_at + operator, incl. concurrent-loser case)
{ "status": "already_scanned", "message": "Already scanned.",
  "reference": "ABC123DEF456", "customer": "Sam Lee",
  "scanned_at": "2025-01-01T19:10:00+00:00", "scanned_by": "Alex Door" }

// rejected  (no Order within tenant scope — foreign company or no match)
{ "status": "rejected", "message": "Ticket not found." }

// failed  (resolved Order not confirmed: voided/refunded/reserved/...)
{ "status": "failed", "message": "Failed. This ticket is no longer valid." }

// invalid  (decoded but HMAC mismatch)
{ "status": "invalid", "message": "Invalid code. This ticket could not be verified." }

// unreadable  (undecodable payload, or empty manual reference)
{ "status": "unreadable", "message": "Unreadable code. Please try scanning again." }
```

These map one-to-one onto `TicketCheckInService`'s statuses and onto `ScanEvent` outcomes (`success`/`duplicate`/`rejected`/`failed`/`unreadable`/`invalid`), so the web and API record identically (Requirement 5.10).

**Satisfies:** Requirements 5.3–5.10, 6.1, 6.2.

### Live snapshot shape

```jsonc
{
  "event": { "id": 12, "name": "Summer Fest", "starts_at": "..." },
  "stats": { "scanned": 240, "sold": 500, "remaining": 260, "rate": 48.0 },
  "recent": [ { "outcome": "success", "operator": "Alex", "reference": "...",
               "customer": "Sam Lee", "tickets": 2, "at": "..." } ]
}
```

No sales/revenue figures; Active_Event only (Requirements 7.2, 7.3, 7.4).

## Error Handling

- **Authentication (401):** missing/revoked/invalid token → `401 {message}`. Sanctum handles this; `shouldRenderJsonWhen($request->is('api/*'))` already in `bootstrap/app.php` guarantees a JSON body (Requirements 3.3, 12.5).
- **Authorization (403):** role lacks `check_in`/`orders` → `403`, **no data changed** (Gate runs before any mutation) (Requirements 5.2, 7.5, 8.3).
- **Membership (403):** selecting a non-member company → `403`, Active_Company unchanged (Requirement 4.5).
- **Validation (422):** bad login/2FA/missing fields → `422` with a generic auth message (never distinguishing "no such email" from "wrong password") (Requirements 1.2, 2.3).
- **Non-TLS (426/403):** `EnforceHttps` rejects before any handler runs (Requirement 12.2).
- **Rate limiting (429):** `throttle:login`/`throttle:2fa` return `429` with `Retry-After` (Security).
- **Scan outcomes are not errors:** `unreadable`/`invalid`/`rejected`/`failed`/`already_scanned` are `200` payloads, not HTTP errors — the door operator always gets a structured result (Requirements 5.3–5.9).
- **iOS:** `APIError.offline` → "You're offline. Reconnect to scan." and no success reported (Requirements 9.1, 9.2); `.unauthorized` → clear Keychain, return to sign-in (Requirement 3.2); `.forbidden` → hide the feature / show "not permitted"; `.serverMessage` → surface the API message.

## Security

| Control | Mechanism | Requirements |
|---|---|---|
| TLS required | `EnforceHttps` middleware on the API group rejects non-TLS | 12.2 |
| Token in transit only | Bearer token over HTTPS; stored only in the iOS Keychain | 12.1 |
| No password persistence | iOS keeps the password in memory for the request only | 12.4 |
| No HMAC on device | `QrService` verify runs server-side; the device only decodes the QR image | 9.3 |
| Token abilities / scopes | Sanctum abilities carry `permitted_actions` + `company:{id}`; endpoints check both | 5.1, 5.2, 8.1 |
| Tenant isolation | `ResolveApiTenant` + `TenantContext` + `BelongsToCompany` scope every query | 4.1, 12.3 |
| Revocation | `logout` deletes the current token; `auth:sanctum` rejects it thereafter | 3.1, 3.3, 12.5 |
| Rate limiting | Named `login`/`2fa` limiters (per email + per IP) on the auth endpoints | 1.2, 2.3 |
| Device trust gated on TOTP | A device is trusted only after a verified challenge | 2.1 |

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — a formal statement about what the system should do. Properties bridge human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: QR payload resolves to the correct Scan_Outcome

For any string submitted to the check-in service under a resolved Active_Company, the returned Scan_Outcome is exactly: `unreadable` when the payload is undecodable or the manual reference is empty; `invalid` when it decodes but the recomputed HMAC does not match; `rejected` when the resolved reference matches no Order in the tenant scope; `failed` when the Order is not confirmed; `already_scanned` when the Order already has a `scanned_at`; and `checked_in` otherwise.

**Validates: Requirements 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 6.2**

### Property 2: Check-in is atomic under concurrency

For any confirmed, unscanned Order, when two or more check-in attempts target it, exactly one returns `checked_in` and every other returns `already_scanned` carrying the winner's `scanned_at`.

**Validates: Requirements 5.8, 5.9**

### Property 3: Manual reference and scanned payload are equivalent

For any confirmed, unscanned Order, submitting its Order_Reference manually (in any letter case) and submitting its QR_Payload both produce a `checked_in` outcome with the same ticket-type breakdown.

**Validates: Requirements 6.1, 5.8**

### Property 4: Every resolved check-in records exactly one Scan_Event

For any check-in request that the service resolves, exactly one `scan_events` row is written under the Active_Company capturing the mapped outcome, the operator, and the Order_Reference.

**Validates: Requirements 5.10**

### Property 5: Tenant scope confines every API response

For any authenticated API request with Active_Company C, no scan, dashboard, or customer-search response includes a record belonging to a company other than C.

**Validates: Requirements 4.1, 12.3, 7.4**

### Property 6: Role gating is total and side-effect-free on denial

For any authenticated user and any gated endpoint, the request is accepted if and only if the user's role (for the Active_Company) holds the required action in `RoleAuthorization`; a denied request changes no data.

**Validates: Requirements 5.1, 5.2, 7.1, 7.5, 8.1, 8.3**

### Property 7: A token is issued only when authentication fully succeeds

For any login attempt, an Access_Token is issued if and only if the credentials are valid AND (two-factor is disabled OR the device is already trusted OR a valid TOTP/recovery code is supplied in the challenge); in every other case no token is issued.

**Validates: Requirements 1.1, 1.2, 1.3, 1.4, 2.2, 2.3**

### Property 8: Device trust is established only by a passed challenge and then persists

For any user with two-factor enabled and any device, the device becomes trusted only after a successful TOTP challenge, and once trusted, subsequent logins from that device for that user skip the challenge.

**Validates: Requirements 2.1, 2.4, 1.4**

### Property 9: Company selection respects membership

For any user and any company id, selecting that company sets it as the Active_Company if the user is a member and otherwise is rejected with the Active_Company left unchanged.

**Validates: Requirements 4.3, 4.4, 4.5**

### Property 10: Revoked tokens are universally rejected

For any Access_Token that has been revoked, every subsequent request presenting it is rejected with an authentication error.

**Validates: Requirements 3.1, 3.3, 12.5**

## Testing Strategy

### Backend (PHPUnit feature tests, `tests/Feature`)

Mirror the existing `ScanCheckInTest` conventions (`RefreshDatabase`, factories, real HTTP through the router).

- **Every Scan_Outcome** via `POST /api/scan`: `checked_in` (+ breakdown), `already_scanned` (+ scanned_at/operator), `rejected` (foreign-company and no-match), `failed` (voided/unconfirmed), `invalid` (HMAC mismatch), `unreadable` (garbage payload and empty manual reference). One test per outcome (Property 1).
- **Atomic concurrency**: two check-ins on one Order → exactly one `checked_in`, one `already_scanned` (Property 2) — a PBT-style test driving many Orders.
- **Manual ↔ scan equivalence** across generated references/cases (Property 3).
- **ScanEvent recording**: one row per resolved request, correct outcome mapping (Property 4).
- **Tenant isolation**: a token scoped to company A cannot scan/search/see company B's records (Property 5).
- **Role gating**: a Scanner is denied `/api/customers` (403); an Accountant (no `check_in`) is denied `/api/scan` and `/api/dashboard/live`; denial changes no data (Property 6).
- **Auth paths**: valid login no-2FA → token; invalid creds → 422 no token; 2FA-enabled untrusted device → `2fa_required` no token; valid TOTP → token + device trusted; invalid TOTP → 422 no token; trusted device skips challenge (Properties 7, 8).
- **Company select/switch**: member → sets Active_Company and re-scopes; non-member → 403 unchanged (Property 9).
- **Revocation**: logout then re-use token → 401 (Property 10).
- **TLS**: non-TLS request rejected (Requirement 12.2).

Property tests use ≥100 iterations and are tagged `Feature: ios-scanner-app, Property {n}: {text}`.

### iOS (XCTest, where practical)

- **DTO decoding**: round-trip each Scan_Outcome JSON → model, asserting status mapping (supports Property 1 on the client).
- **KeychainStore**: store/read/delete token; token absent after logout (Requirements 3.2, 12.1).
- **DeviceIdentity**: stable across calls/reinstall simulation (Requirements 2.1, 2.4).
- **Role visibility**: given `permitted_actions`, the tab set shows/hides Customer Search correctly (Requirements 8.4, 8.5) — pure view-model logic.
- **Offline mapping**: a network failure maps to `.offline` and reports no success (Requirements 9.1, 9.2).
- **APIClient 401 handling**: clears token and routes to sign-in (Requirement 3.2).

Camera/Vision decoding and live camera permission are verified by manual/device testing (not unit-testable without hardware); the networking and state layers above are the practical XCTest surface.
