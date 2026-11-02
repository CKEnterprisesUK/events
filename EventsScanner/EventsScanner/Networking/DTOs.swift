import Foundation

// MARK: - Scan outcome
//
// The Scan_Outcome contract from `POST /api/scan` (see
// `ScanApiController::serialise()` and the design's "Scan_Outcome contract").
// Every resolved scan is HTTP 200 — the outcome IS the payload — so one DTO
// models all six statuses with the richer fields optional, present only for the
// statuses that define them:
//
//   checked_in       reference, customer, scanned_at, breakdown[]
//   already_scanned  reference, customer, scanned_at, scanned_by
//   rejected/failed/invalid/unreadable   status + message only

/// The six Scan_Outcome statuses, mirroring `ScanResult`'s constants on the
/// server. Decoded from the raw `status` string; an unknown value maps to
/// `.unreadable` so the door operator always gets a safe, non-success result.
enum ScanStatus: String, Codable, Equatable {
    case checkedIn = "checked_in"
    case alreadyScanned = "already_scanned"
    case rejected
    case failed
    case invalid
    case unreadable

    init(from decoder: Decoder) throws {
        let raw = try decoder.singleValueContainer().decode(String.self)
        self = ScanStatus(rawValue: raw) ?? .unreadable
    }
}

/// One row of a successful check-in's ticket breakdown: a Ticket_Type name and
/// the quantity on the order (`{ "name": "General", "quantity": 2 }`).
struct ScanBreakdownRow: Codable, Equatable {
    let name: String
    let quantity: Int
}

/// A decoded Scan_Outcome. `status` and `message` are always present; the rest
/// are populated per status (see the contract above). `customer` is a plain
/// display name string in this contract, not a nested object.
struct ScanOutcomeDTO: Codable, Equatable {
    let status: ScanStatus
    let message: String

    /// The Order_Reference — present for `checked_in` and `already_scanned`.
    let reference: String?

    /// The customer's display name — present for `checked_in`/`already_scanned`.
    let customer: String?

    /// ISO-8601 check-in instant — present for `checked_in`/`already_scanned`.
    let scannedAt: String?

    /// The operator who first checked the order in — present for
    /// `already_scanned` only.
    let scannedBy: String?

    /// Ticket_Type + quantity rows — present for `checked_in` only.
    let breakdown: [ScanBreakdownRow]?

    enum CodingKeys: String, CodingKey {
        case status
        case message
        case reference
        case customer
        case scannedAt = "scanned_at"
        case scannedBy = "scanned_by"
        case breakdown
    }
}

// MARK: - Live dashboard snapshot
//
// `GET /api/dashboard/live` (see `DashboardApiController::live()`). Attendance
// only — no sales/revenue figures (Requirement 7.3). `event` is null when the
// company has no eligible Active_Event.

/// The Active_Event descriptor in a live snapshot.
struct LiveEventDTO: Codable, Equatable {
    let id: Int
    let name: String
    let startsAt: String?

    enum CodingKeys: String, CodingKey {
        case id
        case name
        case startsAt = "starts_at"
    }
}

/// Attendance counters for the Active_Event. `rate` is the scanned-of-sold
/// percentage and is null when nothing is sold. `remaining` is `sold − scanned`.
struct LiveStatsDTO: Codable, Equatable {
    let scanned: Int
    let sold: Int
    let remaining: Int
    let rate: Double?
}

/// One entry in the recent-scan feed, reduced to the attendance fields the API
/// exposes. `outcome` is the ScanEvent outcome string
/// (`success|duplicate|rejected|failed|unreadable|invalid`).
struct LiveRecentScanDTO: Codable, Equatable {
    let outcome: String
    let operatorName: String
    let reference: String?
    let customer: String?
    let tickets: Int
    let at: String

    enum CodingKeys: String, CodingKey {
        case outcome
        case operatorName = "operator"
        case reference
        case customer
        case tickets
        case at
    }
}

/// The full live snapshot. `event` is null when the company has no eligible
/// Active_Event, in which case `stats` is zeroed and `recent` is empty.
struct LiveSnapshotDTO: Codable, Equatable {
    let event: LiveEventDTO?
    let stats: LiveStatsDTO
    let recent: [LiveRecentScanDTO]
    let generatedAt: String

    enum CodingKeys: String, CodingKey {
        case event
        case stats
        case recent
        case generatedAt
    }
}

// MARK: - Authentication responses
//
// `POST /api/auth/login` / `/two-factor` and `GET /api/auth/me`
// (see `AuthController`).

/// The response to `login` / `two-factor`. `login` may answer in two shapes:
///
///   - a token:  `{ "token": "…", "permitted_actions": ["check_in", …] }`
///   - a 2FA demand: `{ "status": "2fa_required" }`
///
/// Both fields are optional so one DTO decodes either shape; the client reads
/// `status == "2fa_required"` to branch, else expects `token`.
/// `two-factor` always answers with a token on success.
struct AuthResponseDTO: Codable, Equatable {
    /// The plaintext bearer token to store in the Keychain. Present when auth
    /// fully succeeded; absent on a `2fa_required` response.
    let token: String?

    /// `"2fa_required"` when login needs the second factor; otherwise absent.
    let status: String?

    /// The role's permitted actions, present alongside a freshly issued token.
    let permittedActions: [String]?

    /// Whether the server asked for a two-factor challenge.
    var requiresTwoFactor: Bool { status == "2fa_required" }

    enum CodingKeys: String, CodingKey {
        case token
        case status
        case permittedActions = "permitted_actions"
    }
}

/// The user object inside `GET /api/auth/me`.
struct MeUserDTO: Codable, Equatable {
    let id: Int
    let name: String
    let email: String
}

/// The `me` payload: the user, their permitted actions, and their memberships.
/// Drives role-based UI (tab visibility) and the company picker.
struct MeResponseDTO: Codable, Equatable {
    let user: MeUserDTO
    let permittedActions: [String]
    let companies: [MembershipDTO]

    enum CodingKeys: String, CodingKey {
        case user
        case permittedActions = "permitted_actions"
        case companies
    }
}

// MARK: - Memberships & company selection
//
// `GET /api/companies` and `POST /api/companies/{id}/select`
// (see `CompanyController`).

/// A company the user belongs to, with the role held there
/// (`{ "id": 1, "name": "Acme", "role": "scanner" }`). Shared by
/// `GET /api/companies`, the `me` payload, and the `select` response.
struct MembershipDTO: Codable, Equatable {
    let id: Int
    let name: String
    let role: String
}

/// `GET /api/companies` → `{ "companies": [ {id, name, role}, … ] }`.
struct CompaniesResponseDTO: Codable, Equatable {
    let companies: [MembershipDTO]
}

/// `POST /api/companies/{id}/select` → `{ company: {id,name,role},
/// permitted_actions: [...] }`.
struct SelectCompanyResponseDTO: Codable, Equatable {
    let company: MembershipDTO
    let permittedActions: [String]

    enum CodingKeys: String, CodingKey {
        case company
        case permittedActions = "permitted_actions"
    }
}

// MARK: - Sessions
//
// `GET /api/sessions` and `GET /api/users/{user}/sessions`
// (see `SessionController`). One session is one personal access token row.

/// A single active session (one Sanctum token). `deviceLabel` is the token
/// `name`, `current` flags the token the request itself authenticated with.
struct SessionDTO: Codable, Equatable, Identifiable {
    let id: Int
    let deviceLabel: String?
    let lastUsedAt: String?
    let createdAt: String?
    let current: Bool

    enum CodingKeys: String, CodingKey {
        case id
        case deviceLabel = "device_label"
        case lastUsedAt = "last_used_at"
        case createdAt = "created_at"
        case current
    }
}

/// `GET /api/sessions` (and the team variant) → `{ "sessions": [ … ] }`.
struct SessionsResponseDTO: Codable, Equatable {
    let sessions: [SessionDTO]
}

// MARK: - Customer search
//
// `GET /api/customers?q=term` (see `CustomerApiController::index()`). One row
// per distinct customer email under the Active_Company, carrying the display
// name, the order count, and the confirmed spend in minor units (pence/cents).
// The endpoint is gated on `ACTION_MANAGE_ORDERS`; the iOS client only reaches
// it from the Customer Search tab, which is hidden unless the role holds that
// action (Requirements 8.4, 8.5), so a 403 here is a defensive case, not the
// norm.

/// A single customer row from the search. `confirmedSpendMinor` is in minor
/// currency units (e.g. pence); the view formats it for display. `token` is the
/// base64url email id used to open the customer's detail (orders) screen.
struct CustomerDTO: Codable, Equatable, Identifiable {
    let name: String?
    let email: String
    let token: String
    let ordersCount: Int
    let confirmedSpendMinor: Int

    /// A customer is identified by their email in this contract (the Platform
    /// holds no separate Customer entity), so the email is a stable row id.
    var id: String { email }

    enum CodingKeys: String, CodingKey {
        case name
        case email
        case token
        case ordersCount = "orders_count"
        case confirmedSpendMinor = "confirmed_spend_minor"
    }
}

/// Pagination metadata echoed by the customer search
/// (`{current_page, last_page, per_page, total}`). Decoded so the client can
/// grow into paging later; the current screen only needs `total`.
struct CustomerSearchMetaDTO: Codable, Equatable {
    let currentPage: Int
    let lastPage: Int
    let perPage: Int
    let total: Int

    enum CodingKeys: String, CodingKey {
        case currentPage = "current_page"
        case lastPage = "last_page"
        case perPage = "per_page"
        case total
    }
}

/// `GET /api/customers` → `{ customers: [ {name,email,orders_count,
/// confirmed_spend_minor}, … ], meta: {…} }`, matching
/// `CustomerApiController::index()`.
struct CustomersResponseDTO: Codable, Equatable {
    let customers: [CustomerDTO]
    let meta: CustomerSearchMetaDTO
}

// MARK: - Events
//
// `GET /api/events` (see `EventApiController::index()`). The app's landing tab:
// the Active_Company's events with a tickets-sold count and the public web URL
// a staff member opens to buy tickets. The chosen event's id scopes scanning
// and the live dashboard.

/// One event row for the Events tab.
struct EventDTO: Codable, Equatable, Identifiable {
    let id: Int
    let name: String
    let venue: String?
    let startsAt: String?
    let isPublished: Bool
    let isCancelled: Bool
    let capacity: Int?
    let ticketsSold: Int

    /// The public storefront URL for the event (buy tickets / share). Null only
    /// when the company somehow has no slug.
    let webURL: String?

    enum CodingKeys: String, CodingKey {
        case id
        case name
        case venue
        case startsAt = "starts_at"
        case isPublished = "is_published"
        case isCancelled = "is_cancelled"
        case capacity
        case ticketsSold = "tickets_sold"
        case webURL = "web_url"
    }
}

/// `GET /api/events` → `{ events: [...], web_dashboard_url: "…" }`. The
/// dashboard URL is where an operator with no events is sent to create one (the
/// "click here to start your event" empty state).
struct EventsResponseDTO: Codable, Equatable {
    let events: [EventDTO]
    let webDashboardURL: String

    enum CodingKeys: String, CodingKey {
        case events
        case webDashboardURL = "web_dashboard_url"
    }
}

// MARK: - Customer detail (orders)
//
// `GET /api/customers/{token}` (see `CustomerApiController::show()`). One
// customer's orders, newest first, with the fields the door team needs to
// decide a manual check-in.

/// One order in a customer's order list.
struct CustomerOrderDTO: Codable, Equatable, Identifiable {
    let id: Int
    let reference: String?
    let eventId: Int?
    let eventName: String?
    let status: String
    let isConfirmed: Bool
    let ticketsCount: Int
    let scannedAt: String?
    let orderTotalMinor: Int

    /// Whether this order has already been checked in.
    var isScanned: Bool { scannedAt != nil }

    enum CodingKeys: String, CodingKey {
        case id
        case reference
        case eventId = "event_id"
        case eventName = "event_name"
        case status
        case isConfirmed = "is_confirmed"
        case ticketsCount = "tickets_count"
        case scannedAt = "scanned_at"
        case orderTotalMinor = "order_total_minor"
    }
}

/// The customer identity inside a detail payload.
struct CustomerIdentityDTO: Codable, Equatable {
    let name: String?
    let email: String
    let token: String
}

/// `GET /api/customers/{token}` → `{ customer: {...}, orders: [...] }`.
struct CustomerDetailDTO: Codable, Equatable {
    let customer: CustomerIdentityDTO
    let orders: [CustomerOrderDTO]
}

// MARK: - Order detail
//
// `GET /api/orders/{order}` (see `OrderApiController::show()`). One order's
// full detail, including the ticket-type breakdown and current check-in state.
// `POST /api/orders/{order}/check-in` returns the shared `ScanOutcomeDTO`.

/// One order's full detail.
struct OrderDetailDTO: Codable, Equatable, Identifiable {
    let id: Int
    let reference: String?
    let customerName: String?
    let customerEmail: String?
    let eventId: Int?
    let eventName: String?
    let status: String
    let isConfirmed: Bool
    let orderTotalMinor: Int
    let scannedAt: String?
    let scannedBy: String?
    let breakdown: [ScanBreakdownRow]

    /// Whether this order has already been checked in.
    var isScanned: Bool { scannedAt != nil }

    enum CodingKeys: String, CodingKey {
        case id
        case reference
        case customerName = "customer_name"
        case customerEmail = "customer_email"
        case eventId = "event_id"
        case eventName = "event_name"
        case status
        case isConfirmed = "is_confirmed"
        case orderTotalMinor = "order_total_minor"
        case scannedAt = "scanned_at"
        case scannedBy = "scanned_by"
        case breakdown
    }
}
