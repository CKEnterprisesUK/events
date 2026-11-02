// xcode: set sdk=iOS

import Foundation
import Observation

/// Top-level, app-wide observable state for the EventsScanner client.
///
/// This is the single source of truth that drives navigation and feature
/// visibility across the whole app. Later tasks populate and mutate it:
///
/// - Sign-in / two-factor (task 17.1) move `phase` through
///   `.signedOut → .twoFactorRequired → .selectingCompany → .ready` and set
///   `hasToken` once a token is stored in the Keychain.
/// - Company selection (task 17.1) fills `memberships` and sets
///   `selectedCompany` — a single membership auto-selects and skips the picker
///   (Requirements 4.2, 4.6).
/// - `/api/auth/me` (tasks 15.2 / 20.1) fills `permittedActions`, which drives
///   role-based tab visibility: Scan + Dashboard when `.checkIn` is present,
///   Customer Search only when `.manageOrders` is present, team session
///   management only when `.manageUsers` is present (Requirements 8.4, 8.5).
///
/// `AppState` holds no secrets. The Access_Token itself lives in the Keychain
/// (task 16.1); `hasToken` only reflects its presence, never its value
/// (Requirements 12.1, 12.4).
///
/// IMPORTANT: `@Observable` (the Observation framework macro) requires iOS 17.
/// The app's deployment target is iOS 16.0 (Requirement 11.1), so this type is
/// gated with `@available(iOS 17.0, *)`. See `EventsScanner/README.md`
/// ("iOS 16 vs @Observable") for the reconciliation a human must make
/// (raise the min target to 17, or switch to `ObservableObject`).
@available(iOS 17.0, *)
@Observable
final class AppState {

    /// Where the user is in the authentication / selection flow. Views switch
    /// on this to decide what to show.
    enum Phase: Equatable {
        /// Deciding the initial destination (e.g. restoring a stored token).
        case loading
        /// No usable session; show sign-in.
        case signedOut
        /// Credentials accepted but a TOTP challenge is still required.
        case twoFactorRequired
        /// Authenticated with more than one membership; show the company picker.
        case selectingCompany
        /// Fully authenticated and scoped to a company; show the main tabs.
        case ready
    }

    /// A company the signed-in user belongs to, with the role they hold there.
    /// Mirrors an entry from `GET /api/companies` (`{id, name, role}`).
    struct Membership: Identifiable, Equatable {
        let id: Int
        let name: String
        let role: String
    }

    /// Server-granted permissions from `/api/auth/me` `permitted_actions`.
    /// Raw values match the RoleAuthorization action keys the API returns.
    enum PermittedAction: String, CaseIterable, Equatable {
        case checkIn = "check_in"
        case manageOrders = "orders"
        case manageUsers = "users"
    }

    // MARK: - Session

    /// Current position in the auth / selection flow.
    var phase: Phase = .loading

    /// Whether a usable Access_Token is currently held in the Keychain.
    /// Never holds the token value itself.
    var hasToken: Bool = false

    /// The signed-in user's display name, once known (from `/api/auth/me`).
    var userName: String?

    // MARK: - Company scope

    /// All companies the user belongs to.
    var memberships: [Membership] = []

    /// The company the app is currently scoped to (the Active_Company).
    var selectedCompany: Membership?

    // MARK: - Authorization

    /// Server-driven set of actions the user may perform in the Active_Company.
    var permittedActions: Set<PermittedAction> = []

    // MARK: - Flow transitions
    //
    // Sign-in / two-factor / company selection (task 17.1) drive the phase
    // through these helpers rather than poking `phase` directly, so the
    // single source of truth also owns the (small) rules between phases.

    /// A token has just been stored in the Keychain. Record that a token is
    /// held and move to company selection; the picker is only *shown* when
    /// `memberships.count > 1` — a single membership auto-selects and skips it
    /// (Requirements 4.2, 4.6). `applyMe(...)` populates memberships first.
    func didAuthenticate() {
        hasToken = true
        phase = .selectingCompany
    }

    /// The server asked for a second factor (`{status:"2fa_required"}`). No
    /// token has been issued; route to the TOTP challenge (Requirement 1.3).
    func requireTwoFactor() {
        hasToken = false
        phase = .twoFactorRequired
    }

    /// Apply a `GET /api/auth/me` payload: the display name, the permitted
    /// actions, and the memberships that drive the company picker.
    func applyMe(name: String, permittedActions actions: [String], memberships rows: [Membership]) {
        userName = name
        permittedActions = Set(actions.compactMap(PermittedAction.init(rawValue:)))
        memberships = rows
    }

    /// A company has been selected on the server (single-membership auto-select
    /// or an explicit pick). Record the Active_Company, apply its freshly
    /// scoped permitted actions, and advance to the main tabs (Requirement 4.2).
    func didSelectCompany(_ company: Membership, permittedActions actions: [String]) {
        selectedCompany = company
        permittedActions = Set(actions.compactMap(PermittedAction.init(rawValue:)))
        phase = .ready
    }

    /// The token is gone (a 401 cleared it, or the user signed out). Drop all
    /// session-scoped state and return to sign-in (Requirements 3.2, 3.3).
    func didSignOut() {
        hasToken = false
        userName = nil
        memberships = []
        selectedCompany = nil
        permittedActions = []
        phase = .signedOut
    }

    // MARK: - Derived visibility

    /// Scan and live dashboard are visible when the user may check in.
    var canCheckIn: Bool { permittedActions.contains(.checkIn) }

    /// Customer search is visible only when the user may manage orders
    /// (Requirements 8.4, 8.5).
    var canSearchCustomers: Bool { permittedActions.contains(.manageOrders) }

    /// Team session management is visible only when the user may manage users.
    var canManageTeamSessions: Bool { permittedActions.contains(.manageUsers) }

    init() {}
}
