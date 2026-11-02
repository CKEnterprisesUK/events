import SwiftUI

/// The Profile tab — the signed-in user's identity and the sign-out control.
/// Replaces the old Sessions tab: team-member session management moved out of
/// the app (it is a web-admin job), and the only account control the operator
/// needs on the door is a clear way to sign out.
@available(iOS 17.0, *)
struct ProfileView: View {
    @Environment(AppState.self) private var appState
    let auth: AuthStore

    /// True while the logout request is in flight, so the button shows progress
    /// and ignores repeat taps.
    @State private var isSigningOut = false

    var body: some View {
        NavigationStack {
            List {
                Section("Account") {
                    LabeledContent("Name", value: appState.userName ?? "—")
                    if let email = appState.userEmail, !email.isEmpty {
                        LabeledContent("Email", value: email)
                    }
                }

                if let company = appState.selectedCompany {
                    Section("Company") {
                        LabeledContent("Company", value: company.name)
                        LabeledContent("Role", value: roleLabel(company.role))
                    }
                }

                // Apple requires an accessible in-app link to the privacy
                // policy (App Review Guideline 5.1.1(i)). The URL is derived
                // from the build's own API host so a preprod build points at
                // preprod and a production build at production.
                if let privacyURL {
                    Section("Legal") {
                        Link(destination: privacyURL) {
                            HStack {
                                Text("Privacy Policy")
                                Spacer()
                                Image(systemName: "arrow.up.right.square")
                                    .foregroundStyle(.secondary)
                                    .accessibilityHidden(true)
                            }
                        }
                        .accessibilityIdentifier("profile.privacyPolicy")
                        .accessibilityHint("Opens the privacy policy in your browser")
                    }
                }

                Section {
                    Button(role: .destructive) {
                        signOut()
                    } label: {
                        HStack {
                            if isSigningOut {
                                ProgressView()
                                Text("Signing out…")
                            } else {
                                Text("Sign out")
                            }
                            Spacer()
                        }
                    }
                    .disabled(isSigningOut)
                    .accessibilityIdentifier("profile.signOut")
                }
            }
            .brandNavigationTitle()
            .navigationBarTitleDisplayMode(.inline)
        }
    }

    /// The privacy policy URL, built by appending `/privacy` to the build's
    /// configured `APIBaseURL` (the site root). This resolves to
    /// `https://events.ckent.uk/privacy` in production and the preprod host in
    /// a preprod build, matching the backend's public `/privacy` page.
    private var privacyURL: URL? {
        guard
            let raw = Bundle.main.object(forInfoDictionaryKey: "APIBaseURL") as? String,
            let base = URL(string: raw)
        else {
            return nil
        }
        return base.appendingPathComponent("privacy")
    }

    private func signOut() {
        guard !isSigningOut else { return }
        isSigningOut = true
        Task {
            await auth.logout()
            // AppState moves the app back to sign-in; this view is torn down, so
            // resetting the flag is just belt-and-braces.
            isSigningOut = false
        }
    }

    /// A human-friendly label for a raw role value (e.g. `box_office` →
    /// "Box office").
    private func roleLabel(_ role: String) -> String {
        role
            .split(separator: "_")
            .map { $0.prefix(1).uppercased() + $0.dropFirst() }
            .joined(separator: " ")
    }
}
