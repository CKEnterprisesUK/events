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

                // Policies & terms. Apple requires an accessible in-app link to
                // the privacy policy (App Review Guideline 5.1.1(i)); alongside
                // it we surface the Trust Centre and Terms so every published
                // policy is reachable from the app. Each URL is derived from the
                // build's own API host, so a preprod build points at preprod and
                // a production build at production.
                Section {
                    if let trustURL {
                        legalLink("Trust Centre", url: trustURL, id: "profile.trustCentre",
                                  hint: "Opens the Trust Centre in your browser")
                    }
                    if let privacyURL {
                        legalLink("Privacy Policy", url: privacyURL, id: "profile.privacyPolicy",
                                  hint: "Opens the privacy policy in your browser")
                    }
                    if let termsURL {
                        legalLink("Terms & Conditions", url: termsURL, id: "profile.terms",
                                  hint: "Opens the terms and conditions in your browser")
                    }
                } header: {
                    Text("Policies & terms")
                } footer: {
                    Text("Events is a product of CK Enterprises UK, who build digital services that help charities and community organisations do more.\n\n© \(copyrightYear) CK Enterprises UK. All rights reserved.")
                        .accessibilityIdentifier("profile.legalFooter")
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

    /// A single row linking out to a public legal page in the browser.
    private func legalLink(_ title: String, url: URL, id: String, hint: String) -> some View {
        Link(destination: url) {
            HStack {
                Text(title)
                Spacer()
                Image(systemName: "arrow.up.right.square")
                    .foregroundStyle(.secondary)
                    .accessibilityHidden(true)
            }
        }
        .accessibilityIdentifier(id)
        .accessibilityHint(hint)
    }

    /// The site root the public legal pages hang off, read from the build's
    /// configured `APIBaseURL`. Resolves to `https://events.ckent.uk` in
    /// production and the preprod host in a preprod build.
    private var siteBaseURL: URL? {
        guard
            let raw = Bundle.main.object(forInfoDictionaryKey: "APIBaseURL") as? String,
            let base = URL(string: raw)
        else {
            return nil
        }
        return base
    }

    /// The Trust Centre URL (`/trust`), matching the backend's `trust.index`
    /// route — the starting point for all published policies.
    private var trustURL: URL? {
        siteBaseURL?.appendingPathComponent("trust")
    }

    /// The privacy policy URL (`/privacy`), matching the backend's public
    /// `/privacy` page.
    private var privacyURL: URL? {
        siteBaseURL?.appendingPathComponent("privacy")
    }

    /// The Terms & Conditions URL (`/trust/terms`), matching the backend's
    /// `trust.show` route for the `terms` document slug.
    private var termsURL: URL? {
        siteBaseURL?.appendingPathComponent("trust").appendingPathComponent("terms")
    }

    /// The current year for the copyright line, in the device locale's calendar.
    private var copyrightYear: String {
        String(Calendar.current.component(.year, from: Date()))
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
