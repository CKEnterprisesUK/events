import SwiftUI

/// The main tab container shown once the user is authenticated and scoped to a
/// company (`AppState.phase == .ready`).
///
/// Tab layout (left → right), with Scan deliberately in the CENTRE:
///
///   Events · Customers · Scan · Dashboard · Profile
///
/// - Events    the app's landing tab (always shown). Lists the company's
///             events with tickets-sold counts and a buy-tickets web link, and
///             is where the operator chooses which event to scan for. When the
///             company has no events it shows a "start your event" link to the
///             web app.
/// - Customers shown only when `orders` is present (`canSearchCustomers`).
///             Search by name / email / order reference, drill into a customer's
///             orders, and (for check-in roles) mark an order as entered.
/// - Scan      the centre tab, shown when `check_in` is present (`canCheckIn`).
///             Scans are scoped to the event chosen on the Events tab.
/// - Dashboard shown when `check_in` is present (`canCheckIn`). Live attendance
///             for the selected event.
/// - Profile   the signed-in user's identity and the sign-out control (always
///             shown). Replaces the old Sessions tab — team-member session
///             management moved to the web admin.
///
/// Visibility is server-driven from `AppState.permittedActions` (filled from
/// `/api/auth/me` and refreshed on company select), so the app never encodes
/// the role→action matrix itself.
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
            // Events — the landing tab, always available. Also the place the
            // operator chooses which event to scan for.
            EventsView(auth: auth)
                .tabItem { Label("Events", systemImage: "calendar") }

            // Customers — shown ONLY when the role may manage orders.
            if appState.canSearchCustomers {
                CustomerSearchView(auth: auth)
                    .tabItem { Label("Customers", systemImage: "person.2") }
            }

            // Scan — the CENTRE tab, shown when the role may check in. Scoped to
            // the event chosen on the Events tab.
            if appState.canCheckIn {
                ScanView(auth: auth)
                    .tabItem { Label("Scan", systemImage: "qrcode.viewfinder") }

                // Dashboard — live attendance for the selected event.
                DashboardView(auth: auth)
                    .tabItem { Label("Dashboard", systemImage: "chart.bar") }
            }

            // Profile — identity + sign out, always available.
            ProfileView(auth: auth)
                .tabItem { Label("Profile", systemImage: "person.crop.circle") }
        }
    }
}
