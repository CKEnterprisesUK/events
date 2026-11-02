import XCTest
@testable import EventsScanner

/// Tests for role-driven tab visibility (Requirements 8.4, 8.5).
///
/// SwiftUI view bodies (`MainTabView`) cannot be rendered and inspected
/// headlessly, so this suite tests the visibility logic *at its source*:
/// `AppState`'s server-driven `permittedActions` and the three derived flags
/// that `MainTabView` reads to decide which tabs appear —
///
///   - `canCheckIn`           drives the Scan + Dashboard tabs
///   - `canSearchCustomers`   drives the Customer Search tab (the 8.4 / 8.5 core)
///   - `canManageTeamSessions` drives the team portion of the Sessions tab
///
/// `MainTabView` contains no gating logic of its own — each `if` reads one of
/// these flags verbatim — so asserting the flags for representative role
/// action-sets exercises the true tab-gating behaviour.
///
/// The action-sets mirror the RoleAuthorization matrix the API returns via
/// `/api/auth/me` `permitted_actions` (see requirements.md glossary):
///   - Scanner:     [check_in]
///   - Box_Office:  [check_in, orders, users?]  (orders present)
///   - Accountant:  [orders]                     (no check_in)
///   - Owner/Admin: [check_in, orders, users]
///
/// State is driven through `AppState`'s real API (`applyMe` and
/// `didSelectCompany`), the same entry points the live app uses to fill
/// `permittedActions`, so the test covers the actual population path rather
/// than poking the set directly.
@available(iOS 17.0, *)
final class TabVisibilityTests: XCTestCase {

    // MARK: - Helpers

    /// A throwaway membership for `didSelectCompany`.
    private func membership(role: String) -> AppState.Membership {
        AppState.Membership(id: 1, name: "Acme Events", role: role)
    }

    /// Build an `AppState` and populate `permittedActions` the way the live
    /// flow does: `applyMe(...)` (from `/api/auth/me`) followed by
    /// `didSelectCompany(...)` (the company's freshly scoped actions).
    private func readyState(actions: [String], role: String) -> AppState {
        let state = AppState()
        state.applyMe(
            name: "Ada Lovelace",
            permittedActions: actions,
            memberships: [membership(role: role)]
        )
        state.didSelectCompany(membership(role: role), permittedActions: actions)
        return state
    }

    // MARK: - Scanner: [check_in] only (Req 8.5)

    /// A Scanner holds `check_in` without `orders`: Scan + Dashboard are shown,
    /// Customer Search is hidden. This is the exact Requirement 8.5 case.
    func testScannerShowsScanAndDashboardHidesCustomerSearch() {
        let state = readyState(actions: ["check_in"], role: "scanner")

        XCTAssertTrue(state.canCheckIn, "check_in present → Scan + Dashboard shown")
        XCTAssertFalse(state.canSearchCustomers,
                       "orders absent → Customer Search hidden (Req 8.5)")
        XCTAssertFalse(state.canManageTeamSessions, "users absent → no team sessions")
    }

    // MARK: - Accountant: [orders] without check_in (Req 8.4)

    /// An Accountant holds `orders` without `check_in`: Customer Search is
    /// shown, while Scan + Dashboard are hidden.
    func testAccountantShowsCustomerSearchHidesScanAndDashboard() {
        let state = readyState(actions: ["orders"], role: "accountant")

        XCTAssertTrue(state.canSearchCustomers,
                      "orders present → Customer Search shown (Req 8.4)")
        XCTAssertFalse(state.canCheckIn, "check_in absent → Scan + Dashboard hidden")
        XCTAssertFalse(state.canManageTeamSessions, "users absent → no team sessions")
    }

    // MARK: - Box_Office: [check_in, orders] (Req 8.4)

    /// A Box_Office operator holds both `check_in` and `orders`: Scan,
    /// Dashboard, and Customer Search are all shown; no team management.
    func testBoxOfficeShowsScanDashboardAndCustomerSearch() {
        let state = readyState(actions: ["check_in", "orders"], role: "box_office")

        XCTAssertTrue(state.canCheckIn, "check_in present → Scan + Dashboard shown")
        XCTAssertTrue(state.canSearchCustomers,
                      "orders present → Customer Search shown (Req 8.4)")
        XCTAssertFalse(state.canManageTeamSessions, "users absent → no team sessions")
    }

    // MARK: - Owner / Admin: [check_in, orders, users]

    /// An Owner/Admin holds all three actions: every tab is shown, including
    /// the team session-management portion.
    func testOwnerShowsAllTabsIncludingTeamSessions() {
        let state = readyState(actions: ["check_in", "orders", "users"], role: "owner")

        XCTAssertTrue(state.canCheckIn, "check_in present → Scan + Dashboard shown")
        XCTAssertTrue(state.canSearchCustomers,
                      "orders present → Customer Search shown (Req 8.4)")
        XCTAssertTrue(state.canManageTeamSessions,
                      "users present → team session management shown")
    }

    // MARK: - Core 8.4 / 8.5 assertion: Customer Search tracks `orders`

    /// The decisive Requirement 8.4 / 8.5 assertion in isolation: Customer
    /// Search visibility is on exactly when `orders` is present and off when it
    /// is absent, independent of the other actions. Covers every subset of the
    /// three actions so presence/absence of `orders` is the sole determinant.
    func testCustomerSearchVisibleIfAndOnlyIfOrdersPresent() {
        let allActions = ["check_in", "orders", "users"]

        for subsetMask in 0..<(1 << allActions.count) {
            let actions = allActions.enumerated()
                .filter { subsetMask & (1 << $0.offset) != 0 }
                .map { $0.element }

            let state = readyState(actions: actions, role: "scanner")
            let ordersPresent = actions.contains("orders")

            XCTAssertEqual(
                state.canSearchCustomers, ordersPresent,
                "Customer Search must be visible iff `orders` is present — actions: \(actions)"
            )
            // And the sibling flags track their own action, unaffected by orders.
            XCTAssertEqual(state.canCheckIn, actions.contains("check_in"),
                           "Scan + Dashboard track `check_in` — actions: \(actions)")
            XCTAssertEqual(state.canManageTeamSessions, actions.contains("users"),
                           "Team sessions track `users` — actions: \(actions)")
        }
    }

    // MARK: - Empty permitted actions hide every gated tab

    /// With no permitted actions, every gated flag is false.
    func testNoPermittedActionsHidesAllGatedTabs() {
        let state = readyState(actions: [], role: "scanner")

        XCTAssertFalse(state.canCheckIn)
        XCTAssertFalse(state.canSearchCustomers)
        XCTAssertFalse(state.canManageTeamSessions)
    }

    // MARK: - Unknown action keys are ignored

    /// An unrecognised action key from the server is dropped (it maps to no
    /// `PermittedAction`) and does not switch any tab on.
    func testUnknownActionKeysAreIgnored() {
        let state = readyState(actions: ["check_in", "totally_unknown"], role: "scanner")

        XCTAssertTrue(state.canCheckIn, "known action still applies")
        XCTAssertFalse(state.canSearchCustomers, "unknown key grants no customer search")
        XCTAssertFalse(state.canManageTeamSessions, "unknown key grants no team sessions")
    }
}
