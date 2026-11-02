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
            .navigationTitle("My Profile")
        }
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
