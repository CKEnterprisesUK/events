<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventPageController;
use App\Http\Controllers\GdprController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\StripeConnectController;
use App\Http\Controllers\StripeReturnController;
use App\Http\Controllers\SuperAdmin\CompanyController as SuperAdminCompanyController;
use App\Http\Controllers\SuperAdmin\FeeController as SuperAdminFeeController;
use App\Http\Controllers\SuperAdmin\TransactionController as SuperAdminTransactionController;
use App\Http\Controllers\TicketTypeController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public root Landing_Page
|--------------------------------------------------------------------------
| `/` is a reserved prefix: it establishes no active Company (tenant
| resolution only runs on `/{company-slug}/...` paths). (Requirement 8.1)
*/
Route::get('/', [LandingController::class, 'index'])->name('landing');

/*
|--------------------------------------------------------------------------
| Public privacy policy page (reserved prefix, no tenant / auth)
|--------------------------------------------------------------------------
| `/privacy` is a reserved prefix declared before the `/{company-slug}/`
| catch-all so it renders the Platform privacy policy rather than being treated
| as a storefront slug. It establishes no active Company. (Requirement 22.3)
*/
Route::get('/privacy', [GdprController::class, 'privacy'])->name('privacy');

/*
|--------------------------------------------------------------------------
| Stripe webhook endpoint (reserved prefix, no tenant / auth / CSRF)
|--------------------------------------------------------------------------
| Stripe posts every account's webhooks to this one fixed URL — there is no
| company slug, so the endpoint sits OUTSIDE the `tenant` group and resolves no
| Company. The `stripe.webhook` middleware verifies the Stripe signature before
| the controller runs (invalid/absent signatures are rejected with an error and
| no state change); the endpoint is excluded from CSRF in bootstrap/app.php. The
| controller dedupes and enqueues heavy work on the DB queue, returning 2xx
| quickly. (Requirements 19.1–19.5)
*/
Route::post('/stripe/webhook', [WebhookController::class, 'handle'])
    ->middleware('stripe.webhook')
    ->name('stripe.webhook');

/*
|--------------------------------------------------------------------------
| Company_User authentication (login / logout / session)
|--------------------------------------------------------------------------
| Session-based `web` guard against the users table. Role/tenant scoping and
| the single-Owner invariant are layered on in later tasks.
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Invitation acceptance (public, reserved prefix)
|--------------------------------------------------------------------------
| An invited user is not yet a Company_User, so accept is public and keyed on
| the invitation's opaque token. `/invitations/...` is a reserved prefix
| (declared before the `/{company-slug}/` catch-all) so it is not treated as a
| storefront slug. Accepting creates a Company_User scoped to the inviting
| Company recorded on the invitation. (Requirement 4.2)
*/
Route::get('/invitations/{token}', [InvitationController::class, 'showAccept'])
    ->name('invitations.accept.show');
Route::post('/invitations/{token}', [InvitationController::class, 'accept'])
    ->name('invitations.accept');

/*
|--------------------------------------------------------------------------
| Company dashboard (reserved prefix, authenticated)
|--------------------------------------------------------------------------
| Minimal authenticated landing target for a successful login. The full
| dashboard surfaces are built in later tasks.
*/
Route::middleware(['auth', 'company.active', 'session.timeout', 'dashboard.tenant'])
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function () {
        Route::get('/', function () {
            return view('dashboard');
        })->withoutMiddleware('dashboard.tenant')->name('home');

        // Event management (Admin-gated in the controller). Create/update/
        // publish are scoped to the authenticated user's Company by the
        // `dashboard.tenant` group. (Requirements 5.1, 5.2, 5.3, 5.4, 5.5)
        Route::get('/events', [EventController::class, 'index'])->name('events.index');
        Route::post('/events', [EventController::class, 'store'])->name('events.store');
        Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
        Route::put('/events/{event}', [EventController::class, 'update'])->name('events.update');
        Route::patch('/events/{event}', [EventController::class, 'update']);
        Route::post('/events/{event}/publish', [EventController::class, 'publish'])->name('events.publish');
        Route::post('/events/{event}/unpublish', [EventController::class, 'unpublish'])->name('events.unpublish');

        // Ticket_Type management (Admin-gated in the controller), nested under
        // an Event. Create/update are scoped to the authenticated user's
        // Company by the `dashboard.tenant` group; validation enforces name
        // 1–100 chars, price 0.00–999,999.99 (stored as integer minor units;
        // 0 = free), capacity 1–1,000,000, 1–50 types per Event, and a sale
        // window whose end is strictly after its start. (Requirements 6.1, 6.2,
        // 6.3, 6.9)
        Route::get('/events/{event}/ticket-types', [TicketTypeController::class, 'index'])->name('events.ticket-types.index');
        Route::post('/events/{event}/ticket-types', [TicketTypeController::class, 'store'])->name('events.ticket-types.store');
        Route::put('/events/{event}/ticket-types/{ticketType}', [TicketTypeController::class, 'update'])->name('events.ticket-types.update');
        Route::patch('/events/{event}/ticket-types/{ticketType}', [TicketTypeController::class, 'update']);

        // Order cancel/refund (Admin-gated in the controller: ACTION_CANCEL_ORDER
        // / ACTION_REFUND_ORDER). Cancelling voids the Order's Tickets and
        // returns its held capacity; refunding a paid Order additionally issues
        // a Stripe refund on the Company's connected account. Both are scoped to
        // the authenticated user's Company by the `dashboard.tenant` group
        // (foreign Orders 404) and converge with the refund/dispute webhooks via
        // the shared OrderCancellationService. (Requirements 17.1, 17.2, 17.3, 17.4)
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        Route::post('/orders/{order}/refund', [OrderController::class, 'refund'])->name('orders.refund');

        // Complimentary ticket issuance (Admin-gated in the controller:
        // ACTION_ISSUE_COMP). Creates a confirmed zero-money Order for the
        // chosen Ticket_Types/quantities with no payment, issues the QR and
        // enqueues the ticket email, and consumes capacity through the same
        // reservation path as a paid sale so a comp counts against the
        // Ticket_Type and overall Event capacity and can never oversell. The
        // Event is scoped to the authenticated user's Company by the
        // `dashboard.tenant` group (foreign Events 404). (Requirements 18.1,
        // 18.2, 18.3)
        Route::post('/events/{event}/comp', [OrderController::class, 'issueComp'])->name('events.comp');

        // Branding & ticket customisation. Company-level branding (logo,
        // colour, T&Cs, custom ticket fields) is a Company setting, gated on
        // the Owner ACTION_MANAGE_SETTINGS in the controller; per-Event
        // branding overrides are gated on the Admin ACTION_MANAGE_EVENTS. All
        // scoped to the authenticated user's Company by `dashboard.tenant`.
        // (Requirements 7.1, 7.2, 7.3, 7.4, 7.5)
        Route::get('/branding', [BrandingController::class, 'edit'])->name('branding.edit');
        Route::put('/branding', [BrandingController::class, 'update'])->name('branding.update');
        Route::get('/events/{event}/branding', [BrandingController::class, 'editEvent'])->name('branding.event.edit');
        Route::put('/events/{event}/branding', [BrandingController::class, 'updateEvent'])->name('branding.event.update');

        // User invitation & management (Owner-gated in the controller). Invite
        // by email, change a user's role, and remove a user — all scoped to the
        // authenticated Owner's Company by the `dashboard.tenant` group. Role
        // changes/removals route through the RoleService so the single-Owner
        // invariant governs Owner demotion/removal. (Requirements 4.1, 4.3, 4.4, 4.5)
        Route::get('/users', [InvitationController::class, 'index'])->name('users.index');
        Route::post('/users/invitations', [InvitationController::class, 'invite'])->name('users.invite');
        Route::put('/users/{user}/role', [InvitationController::class, 'updateRole'])->name('users.role');
        Route::patch('/users/{user}/role', [InvitationController::class, 'updateRole']);
        Route::delete('/users/{user}', [InvitationController::class, 'destroy'])->name('users.destroy');

        // Stripe Connect onboarding (Owner-gated in the controller). The Owner
        // sees their connection status, starts Connect Standard onboarding, and
        // returns from Stripe so capabilities are read and persisted.
        // (Requirements 11.1, 11.2, 11.3, 11.5)
        Route::get('/stripe', [StripeConnectController::class, 'show'])->name('stripe.status');
        Route::post('/stripe/connect', [StripeConnectController::class, 'start'])->name('stripe.start');
        Route::get('/stripe/return', [StripeConnectController::class, 'return'])->name('stripe.return');

        // Accountant reports & payouts (Accountant-gated in the controller via
        // ACTION_VIEW_REPORTS). READ-ONLY: only a GET endpoint is exposed and no
        // mutation is possible here — the Accountant sees their own Company's
        // realised sales/revenue and net-to-company payout figures (in integer
        // minor units), scoped to their Company by the `dashboard.tenant` group.
        // Any attempt by an Accountant to modify Events/Ticket_Types/Orders hits
        // the Admin gates and is denied, leaving data unchanged. (Requirements
        // 21.1, 21.2, 21.3, 3.5, 3.7)
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

        // GDPR data-subject tools (Owner-gated in the controller via
        // ACTION_MANAGE_SETTINGS — GDPR handling is a data-controller
        // compliance responsibility). Export a Customer's stored personal data
        // as JSON, or delete/anonymise it while retaining transactional records.
        // Both are scoped to the authenticated user's own Company by the
        // `dashboard.tenant` group, so a Company can only ever export or
        // anonymise its own Customer data — never another Company's.
        // (Requirements 22.1, 22.2, 22.5, 3.3, 3.7)
        Route::get('/gdpr', [GdprController::class, 'index'])->name('gdpr.index');
        Route::post('/gdpr/export', [GdprController::class, 'export'])->name('gdpr.export');
        Route::post('/gdpr/anonymise', [GdprController::class, 'anonymise'])->name('gdpr.anonymise');

        // Web QR scanner (Scanner-gated in the controller via ACTION_CHECK_IN).
        // The scanner page opens the phone camera in the browser and POSTs the
        // decoded payload to the scan endpoint, which recomputes the HMAC over
        // the Order_Reference, loads the Order scoped to the scanning user's
        // Company (foreign/not-found rejected), rejects voided/unconfirmed and
        // reports already-scanned, and on a valid unscanned Order performs the
        // atomic single check-in and shows the full Order breakdown.
        // (Requirements 16.1–16.10, 3.6, 3.7)
        Route::get('/scan', [ScanController::class, 'index'])->name('scan.index');
        Route::post('/scan', [ScanController::class, 'scan'])->name('scan.submit');
    });

/*
|--------------------------------------------------------------------------
| Super-admin dashboard (reserved `/admin` prefix, separate guard)
|--------------------------------------------------------------------------
| The super-admin surface is deliberately separate from the Company dashboards
| in both routing and authorisation. `/admin` is a reserved prefix that
| establishes NO active Company (ResolveTenant skips it), so this group sits
| OUTSIDE the tenant group and is not tenant-scoped — a Super_Admin operates
| across every Company. Access is guarded by `super.admin`, which authorises
| purely on `is_super_admin` (NOT the Company role matrix): guests are sent to
| login by `auth`, and any authenticated non-super-admin (any Company_User) is
| denied with 403. `session.timeout` applies the same idle-timeout as the rest
| of the authenticated surface. (Requirements 20.1, 20.2, 20.3, 20.4, 20.5,
| 20.6, 20.7)
*/
Route::middleware(['auth', 'super.admin', 'session.timeout'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // All Companies' transactions + total Application_Fees earned across the
        // whole Platform (cross-Company, bypasses the tenant scope). (20.1, 20.2)
        Route::get('/', [SuperAdminTransactionController::class, 'index'])->name('home');
        Route::get('/transactions', [SuperAdminTransactionController::class, 'index'])->name('transactions.index');

        // Oversee all Companies; suspend / unsuspend a Company. Suspension is
        // enforced live elsewhere (ResolveTenant / EnsureCompanyActive) so it
        // takes effect immediately. (20.1, 20.3, 20.4)
        Route::get('/companies', [SuperAdminCompanyController::class, 'index'])->name('companies.index');
        Route::post('/companies/{company}/suspend', [SuperAdminCompanyController::class, 'suspend'])->name('companies.suspend');
        Route::post('/companies/{company}/unsuspend', [SuperAdminCompanyController::class, 'unsuspend'])->name('companies.unsuspend');

        // Set the Global_Fee_Percent and per-Company Company_Fee_Percent
        // override + Fee_Handling_Mode. (20.5, 20.6)
        Route::get('/fees', [SuperAdminFeeController::class, 'index'])->name('fees.index');
        Route::put('/fees/global', [SuperAdminFeeController::class, 'updateGlobal'])->name('fees.global.update');
        Route::put('/fees/companies/{company}', [SuperAdminFeeController::class, 'updateCompany'])->name('fees.company.update');
    });

/*
|--------------------------------------------------------------------------
| Public Company storefront (path-based tenancy)
|--------------------------------------------------------------------------
| Anything under `/{company-slug}/` is a Company storefront. The `tenant`
| middleware group resolves the active Company from the leading slug segment
| (404 on unmatched or suspended) and applies the global company_id scope for
| the request. Declared last so reserved prefixes (`/`, `/login`, `/logout`,
| `/dashboard`) match their own routes first. (Requirements 1.2-1.5, 2.1, 2.2)
*/
Route::middleware('tenant')->group(function () {
    Route::get('/{companySlug}', [StorefrontController::class, 'index'])
        ->where('companySlug', '[A-Za-z0-9-]+')
        ->name('storefront');

    // Public Event page at `/{company-slug}/{event-id}/`. The Event is resolved
    // within the active Company by the global tenant scope (foreign Event ids
    // 404); the controller further 404s unpublished Events so Customers can
    // neither view nor purchase them. (Requirements 8.3, 5.4, 5.5)
    Route::get('/{companySlug}/{event}', [EventPageController::class, 'show'])
        ->where('companySlug', '[A-Za-z0-9-]+')
        ->where('event', '[0-9]+')
        ->name('event.page');

    // Checkout order creation at `/{company-slug}/{event-id}/checkout`. Resolves
    // within the active Company (foreign/unpublished Events 404), validates the
    // cart + consents, reserves capacity for a 900s window, snapshots the money
    // + fee mode, and creates the Order in `reserved` status with its Tickets.
    // (Requirements 10.1–10.8, 10.13, 22.4)
    Route::post('/{companySlug}/{event}/checkout', [CheckoutController::class, 'store'])
        ->where('companySlug', '[A-Za-z0-9-]+')
        ->where('event', '[0-9]+')
        ->name('event.checkout');

    // Stripe Checkout return pages (display-only, never authoritative for paid
    // state). Success reflects the Order's current state — a paid Order awaits
    // the `checkout.session.completed` webhook, a free-only Order is already
    // confirmed; cancel releases the reservation and surfaces a
    // payment-not-completed message. Keyed on the Platform-unique
    // Order_Reference within the Event/Company. (Requirements 10.11, 10.12, 12.5)
    Route::get('/{companySlug}/{event}/checkout/{order}/success', [StripeReturnController::class, 'success'])
        ->where('companySlug', '[A-Za-z0-9-]+')
        ->where('event', '[0-9]+')
        ->name('checkout.success');

    Route::get('/{companySlug}/{event}/checkout/{order}/cancel', [StripeReturnController::class, 'cancel'])
        ->where('companySlug', '[A-Za-z0-9-]+')
        ->where('event', '[0-9]+')
        ->name('checkout.cancel');
});
