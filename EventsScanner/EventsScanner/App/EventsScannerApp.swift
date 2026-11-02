import SwiftUI

/// The application entry point.
///
/// Owns the single top-level `AppState` and injects it into the SwiftUI
/// environment so every feature (sign-in, two-factor, company picker, scan,
/// dashboard, customers, sessions) can read and drive shared session state.
///
/// Platform: minimum deployment target iOS 17.0. The app uses the Observation
/// framework's `@Observable` macro (via `AppState`), which requires iOS 17, so
/// the whole app targets iOS 17 unconditionally.
@main
struct EventsScannerApp: App {
    var body: some Scene {
        WindowGroup {
            RootView()
                .tint(Brand.accent)
        }
    }
}

/// Root view that owns the app-wide state and routes on the session phase.
struct RootView: View {
    @State private var appState = AppState()

    /// The app-wide auth stack (APIClient + Keychain + DeviceIdentity), built
    /// once around `appState`. Optional because construction can fail if the
    /// configured base URL is not HTTPS (Requirement 12.2) — in that case we
    /// render a clear configuration error rather than a broken flow.
    @State private var auth: AuthStore?
    @State private var setupError: String?

    // Presentation state only for the post-login flourish. Authentication and
    // routing remain controlled entirely by `AppState.phase`; this overlay only
    // decorates the transition into `.ready`.
    @State private var showTicketBurst = false
    @State private var ticketBurstTask: Task<Void, Never>?

    var body: some View {
        VStack(spacing: 0) {
            // Compile-time PREPROD badge (task 15.4). Present only in the
            // non-production (Debug/preprod) build via AppEnvironment.badgeLabel,
            // which is driven by the `PREPROD` compilation condition — never by
            // server data. A production build has `badgeLabel == nil`, so this
            // banner is absent entirely.
            if let badge = AppEnvironment.badgeLabel {
                EnvironmentBadge(label: badge)
            }
            Group {
                if let auth {
                    content(auth: auth)
                        .environment(appState)
                } else if let setupError {
                    PlaceholderView(title: "Configuration Error", message: setupError)
                } else {
                    ProgressView("Loading…")
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .overlay {
            if showTicketBurst {
                TicketBurstView()
                    .transition(.opacity)
                    .zIndex(1)
            }
        }
        .task { setUpIfNeeded() }
        .onChange(of: appState.phase) { oldPhase, newPhase in
            // Only play the flourish when the authenticated, company-scoped
            // session becomes ready — never for a sign-out, a 2FA prompt, or the
            // company picker. Cancel any in-flight dismissal first so rapid
            // phase changes can't leave the overlay stuck on.
            ticketBurstTask?.cancel()
            ticketBurstTask = nil

            guard newPhase == .ready, oldPhase != .ready else {
                showTicketBurst = false
                return
            }

            // Overlay the already-routed main tabs; routing is not delayed.
            // Show it instantly (no fade-in) so the ticket/shockwave animation
            // begins the moment the session is ready, instead of lagging behind
            // a 150ms opacity ramp.
            showTicketBurst = true

            ticketBurstTask = Task { @MainActor in
                // Longer dwell so the welcome ticket reads first, then launches
                // and the brand shockwave opens, before we fade the overlay out.
                try? await Task.sleep(for: .milliseconds(1500))
                guard !Task.isCancelled else { return }
                withAnimation(.easeOut(duration: 0.25)) {
                    showTicketBurst = false
                }
            }
        }
        .onDisappear {
            ticketBurstTask?.cancel()
            ticketBurstTask = nil
        }
    }

    /// Route on the session phase to the real sign-in, two-factor, company
    /// picker, and (later tasks) the main tabs.
    @ViewBuilder
    private func content(auth: AuthStore) -> some View {
        switch appState.phase {
        case .loading:
            ProgressView("Loading…")
        case .signedOut:
            SignInView(auth: auth)
        case .twoFactorRequired:
            TwoFactorView(auth: auth)
        case .selectingCompany:
            CompanyPickerView(auth: auth)
        case .ready:
            // Server-driven tabs: visibility comes from AppState.permittedActions
            // (populated from /api/auth/me). Customer Search appears only when the
            // role holds `orders` (Requirements 8.4, 8.5).
            MainTabView(auth: auth)
        }
    }

    /// Build the `AuthStore` once and move the app from `.loading` to its first
    /// real screen (sign-in). The base URL comes from `APIBaseURL` in the
    /// Info.plist; `AuthStore.init` rejects a non-HTTPS URL.
    private func setUpIfNeeded() {
        guard auth == nil, setupError == nil else { return }

        guard
            let raw = Bundle.main.object(forInfoDictionaryKey: "APIBaseURL") as? String,
            let url = URL(string: raw)
        else {
            setupError = "The API base URL is missing. Set APIBaseURL (an https:// URL) in Info.plist."
            return
        }

        do {
            let store = try AuthStore(appState: appState, baseURL: url)
            auth = store
            // Keep the phase at `.loading` and attempt to restore a persisted
            // session. If a valid token is in the Keychain, this routes into the
            // company picker / main tabs; otherwise it falls back to sign-in.
            Task { await store.restoreSession() }
        } catch APIError.insecureBaseURL {
            setupError = APIError.insecureBaseURL.userMessage
        } catch {
            setupError = "Could not start networking: \(error)"
        }
    }
}

/// A small, unmissable banner shown across the top of non-production builds so
/// a tester always knows which backend they are on (task 15.4). It is only
/// rendered when `AppEnvironment.badgeLabel` is non-nil, i.e. for the preprod
/// build; the label text comes from the compile-time environment, not the API.
private struct EnvironmentBadge: View {
    let label: String

    var body: some View {
        Text(label)
            .font(.caption.bold())
            .tracking(1)
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 4)
            .background(Color.orange)
            .accessibilityLabel("\(label) environment")
    }
}

private struct PlaceholderView: View {
    let title: String
    let message: String

    var body: some View {
        VStack(spacing: 12) {
            Text(title).font(.title2.bold())
            Text(message)
                .font(.callout)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
        }
        .padding()
    }
}
