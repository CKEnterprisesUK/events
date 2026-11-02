import SwiftUI

/// The main tab container shown once the user is authenticated and scoped to a
/// company (`AppState.phase == .ready`). Tab visibility is entirely
/// server-driven: it reads `AppState.permittedActions`, which is filled from
/// `/api/auth/me` `permitted_actions` (and refreshed on company select), so the
/// app never has to encode the role→action matrix itself.
///
/// Visibility rules (Requirements 8.4, 8.5):
///   - Scan + Dashboard   shown when `check_in` is present (`canCheckIn`).
///   - Customer Search     shown ONLY when `orders` is present
///                         (`canSearchCustomers`); hidden otherwise.
///   - Sessions (team)     the team-management portion is shown only when
///                         `users` is present (`canManageTeamSessions`); the
///                         self-sessions screen itself is always available.
///
/// A role with `check_in` but not `orders` therefore sees Scan + Dashboard and
/// no Customer Search tab (Requirement 8.5) — the gating is purely additive and
/// driven by the server's permitted actions.
///
/// NOTE ON SIBLING TABS: the Scan (task 18.1), Dashboard (task 19.1), and
/// Sessions (task 21.1) screens are built in their own tasks. Until each lands,
/// its tab renders a small `ComingSoonTab` placeholder so this container
/// compiles and the visibility logic is exercisable now. Replace each
/// placeholder with the real view when that task completes — the surrounding
/// `if` conditions and tab ordering stay the same.
///
/// Requires iOS 17 because it reads the `@Observable` `AppState` from the
/// environment — consistent with the rest of the app (see README).
@available(iOS 17.0, *)
struct MainTabView: View {
    @Environment(AppState.self) private var appState

    /// The shared auth stack; the feature screens call through it for their
    /// API requests (the whole app keeps a single `APIClient`).
    let auth: AuthStore

    var body: some View {
        TabView {
            // Scan + Dashboard — shown when the role may check in (Req 8.5).
            if appState.canCheckIn {
                ScanView(auth: auth)
                    .tabItem { Label("Scan", systemImage: "qrcode.viewfinder") }

                DashboardView(auth: auth)
                    .tabItem { Label("Dashboard", systemImage: "chart.bar") }
            }

            // Customer Search — shown ONLY when the role may manage orders;
            // hidden otherwise (Requirements 8.4, 8.5). This is the tab this
            // task wires end-to-end.
            if appState.canSearchCustomers {
                CustomerSearchView(auth: auth)
                    .tabItem { Label("Customers", systemImage: "person.2") }
            }

            // Sessions — the self-sessions screen is always available; the
            // team-management portion is gated inside the screen on the
            // server-driven `users` ability (`canManageTeamSessions`),
            // consistent with the customer-search gating above.
            SessionsView(auth: auth, canManageTeamSessions: appState.canManageTeamSessions)
                .tabItem { Label("Sessions", systemImage: "key") }
        }
    }
}
