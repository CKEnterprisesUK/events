<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmbedController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventPageController;
use App\Http\Controllers\EventQuestionController;
use App\Http\Controllers\EventReportController;
use App\Http\Controllers\EventSponsorController;
use App\Http\Controllers\EventWizardController;
use App\Http\Controllers\GdprController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SharingController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\StripeConnectController;
use App\Http\Controllers\StripeReturnController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\SuperAdmin\AuditController as SuperAdminAuditController;
use App\Http\Controllers\SuperAdmin\ClientController as SuperAdminClientController;
use App\Http\Controllers\SuperAdmin\CompanyController as SuperAdminCompanyController;
use App\Http\Controllers\SuperAdmin\CompanyUserController as SuperAdminCompanyUserController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\FeeController as SuperAdminFeeController;
use App\Http\Controllers\SuperAdmin\ImpersonationController as SuperAdminImpersonationController;
use App\Http\Controllers\SuperAdmin\LegalDocumentController as SuperAdminLegalDocumentController;
use App\Http\Controllers\SuperAdmin\ReservedSlugController as SuperAdminReservedSlugController;
use App\Http\Controllers\SuperAdmin\SettingsController as SuperAdminSettingsController;
use App\Http\Controllers\SuperAdmin\StripeAccountController as SuperAdminStripeAccountController;
use App\Http\Controllers\SuperAdmin\SupportRequestController as SuperAdminSupportRequestController;
use App\Http\Controllers\SuperAdmin\SystemHealthController as SuperAdminSystemHealthController;
use App\Http\Controllers\SuperAdmin\TransactionController as SuperAdminTransactionController;
use App\Http\Controllers\TicketTypeController;
use App\Http\Controllers\TrustController;
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
| Public Trust & Legal Centre (reserved prefix, no tenant / auth)
|--------------------------------------------------------------------------
| `/trust` is the Platform-level legal hub of Events by CK Enterprises UK: the
| Terms & Conditions, Privacy Notice, PCI DSS statement, cookie policy and any
| other policies a Super_Admin publishes. Declared before the `/{company-slug}/`
| catch-all so `/trust` and `/trust/{slug}` render the Platform centre rather
| than being treated as storefront slugs. Establishes no active Company; only
| published documents are exposed (unknown/draft slugs 404).
*/
Route::get('/trust', [TrustController::class, 'index'])->name('trust.index');
Route::get('/trust/{slug}', [TrustController::class, 'show'])
    ->where('slug', '[A-Za-z0-9-]+')
    ->name('trust.show');

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
    // Rate-limited to blunt password brute-force / credential-stuffing: at most
    // 5 attempts per minute per IP+email before a 429. (Security hardening)
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:login');

    // Public self-signup: creates a new Company (tenant) and its single Owner
    // user, then logs the Owner in. All other Company_Users join by invitation.
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    // Throttle signup to limit automated account/tenant creation abuse.
    Route::post('/register', [RegisterController::class, 'register'])
        ->middleware('throttle:auth');

    // Self-service password reset ("forgot password"), built on Laravel's
    // password broker. Request a link, receive a signed token by email, then
    // set a new password. The route names match the framework defaults
    // (password.request/email/reset/update) so the reset notification's URL and
    // any framework helpers resolve correctly. Throttled to prevent reset-link
    // spam / email bombing and token-guessing.
    Route::get('/forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:auth')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:auth')->name('password.update');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Email verification (reserved prefix, authenticated)
|--------------------------------------------------------------------------
| A self-signed-up Owner is created at registration but must verify their email
| before reaching the dashboard (the dashboard group is behind `verified`). The
| route names match Laravel's framework defaults so the VerifyEmail
| notification URL and the `verified` middleware resolve. Invited users are
| auto-verified on accept and never pass through here.
*/
Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');

    // The signed link from the email. `signed` validates the URL signature;
    // throttled to blunt verification-link guessing/abuse.
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    // Resend the verification email. Throttled to prevent email bombing.
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:auth')
        ->name('verification.send');
});

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
Route::middleware(['auth', 'verified', 'company.active', 'session.timeout', 'dashboard.tenant'])
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])
            ->withoutMiddleware('dashboard.tenant')->name('home');

        // Self-service account profile. Open to every authenticated
        // Company_User (any role) since it only ever acts on the acting user's
        // own record; no tenant binding is needed. Update changes name/email;
        // the password endpoint requires the current password and confirms the
        // new one. (Self-service account management)
        Route::get('/profile', [ProfileController::class, 'edit'])
            ->withoutMiddleware('dashboard.tenant')->name('profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])
            ->withoutMiddleware('dashboard.tenant')->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])
            ->withoutMiddleware('dashboard.tenant')->name('profile.password');
        Route::post('/profile/logout-other-sessions', [ProfileController::class, 'logoutOtherSessions'])
            ->withoutMiddleware('dashboard.tenant')->name('profile.logout-other-sessions');
        Route::get('/profile/data', [ProfileController::class, 'downloadData'])
            ->withoutMiddleware('dashboard.tenant')->name('profile.data');

        // Help & Knowledge portal + Contact support. Open to every authenticated
        // Company_User (any role) and to an impersonating Super_Admin: the Help
        // content is generic product guidance and a support request only ever
        // acts on the raiser's own account/Company, so — like the profile
        // routes above — no Company role gate applies and no tenant binding is
        // needed (the SupportController resolves the acting Company explicitly).
        // `store` records the "allow CK Enterprises to access my account"
        // consent on the ticket. (Self-service help & support)
        Route::get('/help', [HelpController::class, 'index'])
            ->withoutMiddleware('dashboard.tenant')->name('help.index');
        Route::get('/support', [SupportController::class, 'create'])
            ->withoutMiddleware('dashboard.tenant')->name('support.create');
        Route::post('/support', [SupportController::class, 'store'])
            ->withoutMiddleware('dashboard.tenant')->name('support.store');

        // Event management (Admin-gated in the controller). Create/update/
        // publish are scoped to the authenticated user's Company by the
        // `dashboard.tenant` group. (Requirements 5.1, 5.2, 5.3, 5.4, 5.5)
        Route::get('/events', [EventController::class, 'index'])->name('events.index');
        // Multi-step create wizard. `events.create` keeps its name so the "New
        // event" links (events index + main sidebar) keep working — it now
        // renders the wizard's first step ("basics") instead of the old slim
        // form. The optional `{step?}` segment mirrors the design's route table;
        // start() always renders step 1. Declared before `/events/{event}` so
        // the literal `create` segment is not resolved as an Event id.
        // (Requirements 4.1, 4.2, 4.13, 4.15)
        Route::get('/events/create/{step?}', [EventWizardController::class, 'start'])->name('events.create');
        // Step 1 submit: creates the tenant-scoped draft Event and advances to
        // the "when" step.
        Route::post('/events/wizard', [EventWizardController::class, 'store'])->name('events.wizard.store');
        // Per-step render (GET) and persist-and-advance (POST/PATCH) against the
        // draft Event. Declared before `/events/{event}` so the tenant-scoped
        // binding resolves and foreign events 404.
        Route::get('/events/{event}/setup/{step}', [EventWizardController::class, 'step'])->name('events.wizard.step');
        Route::match(['post', 'patch'], '/events/{event}/setup/{step}', [EventWizardController::class, 'save'])->name('events.wizard.save');

        // Legacy slim-form create action. Superseded by `events.wizard.store`
        // but left registered (harmless) to avoid breaking any programmatic
        // callers; can be removed in a later cleanup. (Design: "New / changed routes")
        Route::post('/events', [EventController::class, 'store'])->name('events.store');
        Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
        Route::put('/events/{event}', [EventController::class, 'update'])->name('events.update');
        Route::patch('/events/{event}', [EventController::class, 'update']);
        Route::post('/events/{event}/publish', [EventController::class, 'publish'])->name('events.publish');
        Route::post('/events/{event}/unpublish', [EventController::class, 'unpublish'])->name('events.unpublish');
        // Cancel vs. delete (both Admin-gated in the controller via
        // ACTION_MANAGE_EVENTS). An Event with no confirmed bookings can be
        // deleted outright (destroy); once it has taken bookings it can only be
        // cancelled (cancel) — its Orders are retained so customers can be
        // contacted and refunds arranged via support. Each action refuses the
        // other's precondition, so they never overlap. Scoped to the user's
        // Company by the `dashboard.tenant` group (foreign Events 404).
        Route::post('/events/{event}/cancel', [EventController::class, 'cancel'])->name('events.cancel');
        Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');

        // Dedicated per-section manage screens. Each is its own URL (no JS-only
        // tabs) so a section can be linked to and bookmarked directly. The
        // Overview screen is `events.show` above; these are its siblings.
        // (Requirements 5.1, 5.3, 4.1, 4.3)
        Route::get('/events/{event}/location', [EventController::class, 'location'])->name('events.location');
        // Location-only update: validates just the "Where" fields (mode, venue,
        // address, pin) so the section form no longer piggy-backs on the full
        // event-details update via a hidden name input. (Requirements 4.1–4.5)
        Route::patch('/events/{event}/location', [EventController::class, 'updateLocation'])->name('events.location.update');
        // Live address→coordinates lookup for the "Where" screen. Resolves an
        // address to a pin on demand (no save) so the manager can confirm the
        // location while editing. Reuses GeocodingService. (Requirements 4.2, 4.5)
        Route::post('/events/{event}/geocode', [EventController::class, 'geocode'])->name('events.geocode');
        Route::get('/events/{event}/tickets', [EventController::class, 'tickets'])->name('events.tickets');
        // Overall event capacity (the shared-pool ceiling) is edited on the
        // Tickets screen, next to the ticket types it governs. Its own tiny
        // route so it saves independently of the Overview details form.
        Route::patch('/events/{event}/capacity', [EventController::class, 'updateCapacity'])->name('events.capacity.update');
        Route::get('/events/{event}/share', [EventController::class, 'share'])->name('events.share');
        Route::get('/events/{event}/orders', [EventController::class, 'orders'])->name('events.orders');

        // Sharing hub (Admin-gated in the controller via ACTION_MANAGE_EVENTS).
        // A single promotion surface: a downloadable QR pointing at the public
        // storefront, plus copy-and-paste iframe embed snippets (a booking feed
        // of all published Events, and a per-event tickets widget). `qr` streams
        // the storefront QR PNG. Scoped to the authenticated user's Company by
        // the `dashboard.tenant` group.
        Route::get('/sharing', [SharingController::class, 'index'])->name('sharing.index');
        Route::get('/sharing/qr', [SharingController::class, 'storefrontQr'])->name('sharing.qr');

        // Per-event activity trail + the "reset check-ins" control. `history`
        // (Admin/Box_Office-gated via ACTION_MANAGE_EVENTS, like the other
        // manage screens) shows this Event's own audit rows; `reset-scans` (a
        // mutating POST, gated more tightly on ACTION_RESET_SCANS — Owner/Admin
        // only) clears every check-in for the Event and audits the count. Both
        // are scoped to the authenticated user's Company by the
        // `dashboard.tenant` group (foreign Events 404).
        Route::get('/events/{event}/history', [EventController::class, 'history'])->name('events.history');
        Route::post('/events/{event}/reset-scans', [EventController::class, 'resetScans'])->name('events.reset-scans');

        // Event QR code + per-event report (Admin-gated / Accountant-gated in
        // their controllers). `qr` streams a PNG of the public Event page URL
        // for sharing; `report` renders the read-only per-event sales/revenue
        // breakdown from the shared EventReportService. Both are scoped to the
        // authenticated user's Company by the `dashboard.tenant` group (foreign
        // Events 404). (Requirements 4.3, 6.1, 6.9)
        Route::get('/events/{event}/qr', [EventController::class, 'qr'])->name('events.qr');
        Route::get('/events/{event}/report', [EventReportController::class, 'show'])->name('events.report');

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
        // Delete a Ticket_Type. Refuses (redirect back with an error) to drop
        // the last remaining type of a published Event so the "≥1 ticket type"
        // publish blocker can never be violated from the accordion. (Req 6.2)
        Route::delete('/events/{event}/ticket-types/{ticketType}', [TicketTypeController::class, 'destroy'])->name('events.ticket-types.destroy');

        // Attendee questions (Admin-gated in the controller), nested under an
        // Event. Up to three custom questions (free text / single choice /
        // number) asked of the Customer at checkout. `save` replaces the whole
        // set atomically. A per-event answers report + CSV export live on the
        // same controller, gated on ACTION_VIEW_REPORTS.
        Route::get('/events/{event}/questions', [EventQuestionController::class, 'index'])->name('events.questions');
        Route::put('/events/{event}/questions', [EventQuestionController::class, 'save'])->name('events.questions.save');
        Route::get('/events/{event}/questions/report', [EventQuestionController::class, 'report'])->name('events.questions.report');
        Route::get('/events/{event}/questions/export', [EventQuestionController::class, 'export'])->name('events.questions.export');

        // Event sponsors (Admin-gated in the controller), nested under an Event.
        // A repeatable list of sponsor logos with optional store-page details
        // and a per-sponsor "show on ticket" flag (capped in the controller).
        // `sponsors` is a manage screen; store/update/destroy are the per-row
        // CRUD; `reorder` persists a new display order; `copy` appends every
        // sponsor from another of the Company's Events (duplicating logo files).
        // All scoped to the authenticated user's Company by `dashboard.tenant`.
        Route::get('/events/{event}/sponsors', [EventSponsorController::class, 'index'])->name('events.sponsors');
        Route::post('/events/{event}/sponsors', [EventSponsorController::class, 'store'])->name('events.sponsors.store');
        Route::post('/events/{event}/sponsors/reorder', [EventSponsorController::class, 'reorder'])->name('events.sponsors.reorder');
        Route::post('/events/{event}/sponsors/copy', [EventSponsorController::class, 'copy'])->name('events.sponsors.copy');
        Route::put('/events/{event}/sponsors/{sponsor}', [EventSponsorController::class, 'update'])->name('events.sponsors.update');
        Route::delete('/events/{event}/sponsors/{sponsor}', [EventSponsorController::class, 'destroy'])->name('events.sponsors.destroy');

        // Order cancel/refund (Admin-gated in the controller: ACTION_CANCEL_ORDER
        // / ACTION_REFUND_ORDER). Cancelling voids the Order's Tickets and
        // returns its held capacity; refunding a paid Order additionally issues
        // a Stripe refund on the Company's connected account. Both are scoped to
        // the authenticated user's Company by the `dashboard.tenant` group
        // (foreign Orders 404) and converge with the refund/dispute webhooks via
        // the shared OrderCancellationService. (Requirements 17.1, 17.2, 17.3, 17.4)
        // Cross-event Orders list + detail (Admin-gated in the controller via
        // ACTION_MANAGE_ORDERS). The index paginates and supports search
        // (reference / customer name / email) and a status filter; show renders
        // one Order with its Tickets, consent records and check-in status. Both
        // are scoped to the authenticated user's Company by the
        // `dashboard.tenant` group (foreign Orders 404). (Requirements 10.1, 10.5, 17.1)
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        Route::post('/orders/{order}/refund', [OrderController::class, 'refund'])->name('orders.refund');
        Route::post('/orders/{order}/partial-refund', [OrderController::class, 'partialRefund'])->name('orders.partial-refund');
        // Manual ticket handling on a confirmed Order (Admin/Box_Office via
        // ACTION_MANAGE_ORDERS): download the A4 e-ticket PDF, or re-send the
        // branded ticket email to the customer. Both reuse the same QR the
        // original email/scanner use (a pure function of the order reference).
        Route::get('/orders/{order}/ticket.pdf', [OrderController::class, 'downloadTicket'])->name('orders.ticket-pdf');
        Route::post('/orders/{order}/resend', [OrderController::class, 'resend'])->name('orders.resend');

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
        Route::post('/users/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('users.invitations.resend');
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

        // Owner-gated fee-handling mode toggle (Absorb vs Pass_On). Changing it
        // affects only FUTURE orders — existing orders snapshot the mode at
        // creation. (Requirements 13.1, 13.3, 13.8)
        Route::put('/stripe/fee-mode', [StripeConnectController::class, 'updateFeeMode'])->name('stripe.fee-mode');

        // Accountant reports & payouts (Accountant-gated in the controller via
        // ACTION_VIEW_REPORTS). READ-ONLY: only a GET endpoint is exposed and no
        // mutation is possible here — the Accountant sees their own Company's
        // realised sales/revenue and net-to-company payout figures (in integer
        // minor units), scoped to their Company by the `dashboard.tenant` group.
        // Any attempt by an Accountant to modify Events/Ticket_Types/Orders hits
        // the Admin gates and is denied, leaving data unchanged. (Requirements
        // 21.1, 21.2, 21.3, 3.5, 3.7)
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

        // Company activity trail (Owner/Admin-gated in the controller via
        // ACTION_VIEW_AUDIT_LOG). READ-ONLY: a single GET renders the Company's
        // own audit log — money, access, event, privacy and sign-in events —
        // scoped EXPLICITLY to the acting Company (the audit_logs table carries
        // no global tenant scope). Actions performed by an impersonating
        // Super_Admin are flagged. (Security — accountability / audit trail)
        Route::get('/activity', [AuditLogController::class, 'index'])->name('activity.index');

        // Customers roster + per-Customer detail (Admin-gated in the controller
        // via ACTION_MANAGE_ORDERS). A Customer is identified by email (the
        // Platform holds no separate Customer entity); the roster aggregates the
        // Company's Orders and detail lists one Customer's orders/tickets/
        // consents. The GDPR data-subject tools live here: export a Customer's
        // stored personal data as JSON, or delete/anonymise it while retaining
        // transactional records — both Owner-gated (ACTION_MANAGE_SETTINGS,
        // data-controller responsibility) inside the controller. All scoped to
        // the acting Company by `dashboard.tenant`. The `{customer}` segment is
        // a URL-safe base64 token of the email. (Requirements 10.1, 22.1, 22.2, 22.5)
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/export', [CustomerController::class, 'exportAll'])->name('customers.export-all');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::post('/customers/{customer}/export', [CustomerController::class, 'export'])->name('customers.export');
        Route::post('/customers/{customer}/anonymise', [CustomerController::class, 'anonymise'])->name('customers.anonymise');

        // Web QR scanner (Scanner-gated in the controller via ACTION_CHECK_IN).
        // The scanner page opens the phone camera in the browser and POSTs the
        // decoded payload to the scan endpoint, which recomputes the HMAC over
        // the Order_Reference, loads the Order scoped to the scanning user's
        // Company (foreign/not-found rejected), rejects voided/unconfirmed and
        // reports already-scanned, and on a valid unscanned Order performs the
        // atomic single check-in and shows the full Order breakdown.
        // (Requirements 16.1–16.10, 3.6, 3.7)
        Route::get('/scan', [ScanController::class, 'index'])->name('scan.index');
        Route::get('/scan/live', [ScanController::class, 'live'])->name('scan.live');
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
        // Platform dashboard: at-a-glance totals across every Company (companies,
        // users, events, confirmed orders, gross sales, platform fees earned).
        // This is the super-admin landing page. (20.1, 20.2)
        Route::get('/', [SuperAdminDashboardController::class, 'index'])->name('home');

        // All Companies' transactions + total Application_Fees earned across the
        // whole Platform (cross-Company, bypasses the tenant scope). (20.1, 20.2)
        Route::get('/transactions', [SuperAdminTransactionController::class, 'index'])->name('transactions.index');

        // Platform-wide, cross-tenant audit trail (forensics/compliance). Adds a
        // Company filter and an impersonated-only toggle over the shared filters
        // so staff can review exactly what was done while jumped into tenants.
        // Read-only. (Security — accountability / audit trail)
        Route::get('/audit', [SuperAdminAuditController::class, 'index'])->name('audit.index');

        // Clients (Companies) with per-Company stats, and a per-Company
        // drill-down. A business view of each tenant's activity. (20.1)
        Route::get('/clients', [SuperAdminClientController::class, 'index'])->name('clients.index');
        Route::get('/clients/{company}', [SuperAdminClientController::class, 'show'])->name('clients.show');

        // Connected accounts: which Companies have completed Stripe Connect
        // onboarding (charges enabled), which are stuck part-way, and which have
        // never started. The operational answer to "a client isn't getting paid".
        Route::get('/payments', [SuperAdminStripeAccountController::class, 'index'])->name('payments.index');

        // Platform settings, including a mail-troubleshooting tool that sends a
        // diagnostic test email through the configured mailer. (20.7)
        Route::get('/settings', [SuperAdminSettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings/test-mail', [SuperAdminSettingsController::class, 'sendTest'])->name('settings.test-mail');
        // Persist the Platform-wide outbound-mail transport (SMTP vs Microsoft Graph).
        Route::post('/settings/mail-transport', [SuperAdminSettingsController::class, 'updateMailTransport'])->name('settings.mail-transport');
        // Live, read-only Microsoft Graph connectivity probe (auth check; sends no mail).
        Route::post('/settings/graph-diagnostics', [SuperAdminSettingsController::class, 'runGraphDiagnostics'])->name('settings.graph-diagnostics');
        // Flush the cached Graph application token (e.g. after granting admin consent).
        Route::post('/settings/graph-clear-token', [SuperAdminSettingsController::class, 'clearGraphToken'])->name('settings.graph-clear-token');
        // Live, read-only Stripe credential probe: verifies STRIPE_SECRET against
        // the Stripe API (retrieves the Platform account). Makes no charge.
        Route::post('/settings/stripe-diagnostics', [SuperAdminSettingsController::class, 'runStripeDiagnostics'])->name('settings.stripe-diagnostics');

        // System health: read-only status of the database, queue backlog, failed
        // jobs and cache — so a stalled worker or broken dependency is visible
        // rather than silently backing up ticket emails / webhook processing.
        Route::get('/system', [SuperAdminSystemHealthController::class, 'index'])->name('system.index');

        // Support ticket queue: the operator view of every in-dashboard
        // "Contact support" request across the whole Platform. Deliberately
        // minimal — read a ticket and open/close it (no in-app reply thread;
        // conversations happen over email seeded by the new-ticket
        // notification). Cross-tenant: the controller reads with
        // `withoutGlobalScopes()`. The `{supportRequest}` segment is a plain id
        // (the model's tenant scope makes implicit route-model binding hide
        // rows on the Company-less admin surface, so the controller resolves it
        // explicitly). (Platform support oversight)
        Route::get('/support', [SuperAdminSupportRequestController::class, 'index'])->name('support.index');
        Route::get('/support/{supportRequest}', [SuperAdminSupportRequestController::class, 'show'])
            ->where('supportRequest', '[0-9]+')->name('support.show');
        Route::put('/support/{supportRequest}/status', [SuperAdminSupportRequestController::class, 'updateStatus'])
            ->where('supportRequest', '[0-9]+')->name('support.status');

        // Trust & Legal Centre management: author the Platform-level policies
        // (Terms & Conditions, Privacy Notice, PCI DSS statement, cookie policy,
        // and any others) that are published at the public `/trust` surface.
        // Defaults are ensured on the index; the slug is stable (not editable)
        // so public URLs never silently break.
        Route::get('/legal', [SuperAdminLegalDocumentController::class, 'index'])->name('legal.index');
        Route::get('/legal/create', [SuperAdminLegalDocumentController::class, 'create'])->name('legal.create');
        Route::post('/legal', [SuperAdminLegalDocumentController::class, 'store'])->name('legal.store');
        Route::get('/legal/{legalDocument}/edit', [SuperAdminLegalDocumentController::class, 'edit'])->name('legal.edit');
        Route::put('/legal/{legalDocument}', [SuperAdminLegalDocumentController::class, 'update'])->name('legal.update');

        // Company_Slug blocklist: the reserved slugs a Company may never claim
        // at self-signup or on a slug change (reserved platform routes / infra
        // paths that path-based tenancy would otherwise shadow, plus brand/abuse
        // words). Enforced by App\Rules\CompanySlug. Seeded system rows are
        // undeletable; operator additions are removable.
        Route::get('/reserved-slugs', [SuperAdminReservedSlugController::class, 'index'])->name('reserved-slugs.index');
        Route::post('/reserved-slugs', [SuperAdminReservedSlugController::class, 'store'])->name('reserved-slugs.store');
        Route::delete('/reserved-slugs/{reservedSlug}', [SuperAdminReservedSlugController::class, 'destroy'])->name('reserved-slugs.destroy');

        // Owner / user recovery for a specific Company (from the client detail
        // page): email the Owner a password reset, re-send their verification, or
        // transfer the single Owner role to another user in the Company.
        Route::post('/companies/{company}/owner/password-reset', [SuperAdminCompanyUserController::class, 'sendPasswordReset'])->name('companies.owner.password-reset');
        Route::post('/companies/{company}/owner/resend-verification', [SuperAdminCompanyUserController::class, 'resendVerification'])->name('companies.owner.resend-verification');
        Route::post('/companies/{company}/owner/transfer', [SuperAdminCompanyUserController::class, 'transferOwnership'])->name('companies.owner.transfer');

        // Suspend / unsuspend a Company. The company list + detail live on the
        // Clients surface; these actions redirect back to that client page.
        // Suspension is enforced live elsewhere (ResolveTenant /
        // EnsureCompanyActive) so it takes effect immediately. (20.3, 20.4)
        Route::post('/companies/{company}/suspend', [SuperAdminCompanyController::class, 'suspend'])->name('companies.suspend');
        Route::post('/companies/{company}/unsuspend', [SuperAdminCompanyController::class, 'unsuspend'])->name('companies.unsuspend');

        // Set the Global_Fee_Percent and per-Company Company_Fee_Percent
        // override + Fee_Handling_Mode. (20.5, 20.6)
        Route::get('/fees', [SuperAdminFeeController::class, 'index'])->name('fees.index');
        Route::put('/fees/global', [SuperAdminFeeController::class, 'updateGlobal'])->name('fees.global.update');
        Route::put('/fees/companies/{company}', [SuperAdminFeeController::class, 'updateCompany'])->name('fees.company.update');

        // Jump into a Company's dashboard as the acting Super_Admin (and step
        // back out). Impersonation is a session flag honoured by
        // `dashboard.tenant`; a Super_Admin already holds every Company ability
        // via the Gate::before hook, so this only binds the acting tenant.
        Route::post('/impersonate/stop', [SuperAdminImpersonationController::class, 'stop'])->name('impersonate.stop');
        Route::post('/impersonate/{company}', [SuperAdminImpersonationController::class, 'start'])->name('impersonate.start');
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

    // Public embeddable widgets at `/{company-slug}/embed/...`. These render
    // minimal, iframe-friendly pages a Company drops onto its own website: a
    // booking feed of all its published Events, or a per-event tickets widget.
    // Both resolve within the active Company (foreign/unpublished Events 404)
    // and link out to the storefront / event / checkout pages in a new tab.
    // Declared before `/{companySlug}/{event}` so the literal `embed` segment
    // is not resolved as an Event id.
    Route::get('/{companySlug}/embed/booking', [EmbedController::class, 'booking'])
        ->where('companySlug', '[A-Za-z0-9-]+')
        ->name('embed.booking');

    Route::get('/{companySlug}/embed/{event}/tickets', [EmbedController::class, 'tickets'])
        ->where('companySlug', '[A-Za-z0-9-]+')
        ->where('event', '[0-9]+')
        ->name('embed.tickets');

    // Public Event page at `/{company-slug}/{event-id}/`. The Event is resolved
    // within the active Company by the global tenant scope (foreign Event ids
    // 404); the controller further 404s unpublished Events so Customers can
    // neither view nor purchase them. (Requirements 8.3, 5.4, 5.5)
    Route::get('/{companySlug}/{event}', [EventPageController::class, 'show'])
        ->where('companySlug', '[A-Za-z0-9-]+')
        ->where('event', '[0-9]+')
        ->name('event.page');

    // Self-service "lost my tickets" resend from the public Event page. Takes an
    // email and re-queues the ticket email for every confirmed Order that email
    // holds for this Event. The response is deliberately neutral and never
    // reveals whether the email has a booking. Resolved within the active
    // Company (foreign/unpublished Events 404). (Requirements 8.3, 14.3, 22.x)
    Route::post('/{companySlug}/{event}/resend', [EventPageController::class, 'resendTickets'])
        ->where('companySlug', '[A-Za-z0-9-]+')
        ->where('event', '[0-9]+')
        ->middleware('throttle:public')
        ->name('event.tickets.resend');

    // Dedicated checkout step at `/{company-slug}/{event-id}/checkout/review`.
    // The public Event page handles discovery + ticket selection only, then
    // POSTs the chosen quantities here; this renders the checkout page where the
    // Customer enters their details, accepts consents and starts payment.
    // Resolves within the active Company (foreign/unpublished Events 404); an
    // empty/invalid selection redirects back to the Event page.
    Route::post('/{companySlug}/{event}/checkout/review', [CheckoutController::class, 'review'])
        ->where('companySlug', '[A-Za-z0-9-]+')
        ->where('event', '[0-9]+')
        ->middleware('throttle:public')
        ->name('event.checkout.show');

    // Checkout order creation at `/{company-slug}/{event-id}/checkout`. Resolves
    // within the active Company (foreign/unpublished Events 404), validates the
    // cart + consents, reserves capacity for a 900s window, snapshots the money
    // + fee mode, and creates the Order in `reserved` status with its Tickets.
    // (Requirements 10.1–10.8, 10.13, 22.4)
    Route::post('/{companySlug}/{event}/checkout', [CheckoutController::class, 'store'])
        ->where('companySlug', '[A-Za-z0-9-]+')
        ->where('event', '[0-9]+')
        ->middleware('throttle:public')
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
