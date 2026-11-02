import SwiftUI

/// The application entry point.
///
/// Owns the single top-level `AppState` and injects it into the SwiftUI
/// environment so every feature (sign-in, two-factor, company picker, scan,
/// dashboard, customers, sessions) can read and drive shared session state.
///
/// Platform: minimum deployment target iOS 16.0 (Requirement 11.1).
///
/// NOTE ON `@Observable`: the task and design call for an `@Observable`
/// `AppState`. The Observation framework's `@Observable` macro requires iOS
/// 17.0 at runtime, which is in tension with the iOS 16.0 deployment target.
/// `AppState` is annotated with `@available(iOS 17.0, *)` and the root view is
/// branched so the project still compiles and runs against an iOS 16 target.
/// A human should reconcile this before shipping — see
/// `EventsScanner/README.md` ("iOS 16 vs @Observable").
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
///
/// The `@Observable` `AppState` requires iOS 17, so on iOS 16 we fall back to a
/// clear, non-crashing placeholder. Later tasks (sign-in, tabs) build their
/// views behind the iOS 17 branch, or the deployment target is raised — see the
/// README for the decision a human needs to make.
struct RootView: View {
    var body: some View {
        if #available(iOS 17.0, *) {
            ObservableRootView()
        } else {
            UnsupportedObservationView()
        }
    }
}

@available(iOS 17.0, *)
private struct ObservableRootView: View {
    @State private var appState = AppState()

    /// The app-wide auth stack (APIClient + Keychain + DeviceIdentity), built
    /// once around `appState`. Optional because construction can fail if the
    /// configured base URL is not HTTPS (Requirement 12.2) — in that case we
    /// render a clear configuration error rather than a broken flow.
    @State private var auth: AuthStore?
    @State private var setupError: String?

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
        .task { setUpIfNeeded() }
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
            auth = try AuthStore(appState: appState, baseURL: url)
            appState.phase = .signedOut
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

private struct UnsupportedObservationView: View {
    var body: some View {
        PlaceholderView(
            title: "Update Required",
            message: "This build uses the Observation framework (@Observable), which requires iOS 17. See README: iOS 16 vs @Observable."
        )
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
