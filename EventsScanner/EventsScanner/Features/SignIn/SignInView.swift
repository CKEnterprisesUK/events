import SwiftUI

/// Drives the sign-in screen: collects email + password, posts them (with the
/// device id, inside `AuthStore`) to `POST /api/auth/login`, and lets the
/// `AuthStore` advance `AppState` on the outcome:
///
/// - token issued → token stored, `me` fetched, phase → `.selectingCompany`
///   (Requirement 1.1);
/// - `{status:"2fa_required"}` → phase → `.twoFactorRequired` (Requirement 1.3);
/// - invalid credentials / any error → `errorMessage` shows the `APIError`
///   user message and the user stays on this screen (Requirement 1.2).
///
/// The password lives only in `@Published password` for the lifetime of the
/// screen and is cleared on a successful post; it is never persisted
/// (Requirement 12.4).
///
/// Requires iOS 17 because it drives the `@Observable` `AppState` via
/// `AuthStore` — consistent with the rest of the app (see README).
@available(iOS 17.0, *)
@MainActor
final class SignInViewModel: ObservableObject {
    @Published var email: String = ""
    @Published var password: String = ""
    @Published private(set) var isSubmitting: Bool = false
    @Published var errorMessage: String?

    private let auth: AuthStore

    init(auth: AuthStore) {
        self.auth = auth
    }

    /// Whether the form may be submitted: both fields non-empty and no request
    /// already in flight.
    var canSubmit: Bool {
        !email.trimmingCharacters(in: .whitespaces).isEmpty
            && !password.isEmpty
            && !isSubmitting
    }

    /// Post the credentials. On success the password is dropped from memory and
    /// `AuthStore` has already moved `AppState` on; on failure the error is
    /// surfaced and the user stays put.
    func submit() async {
        guard canSubmit else { return }
        isSubmitting = true
        errorMessage = nil

        do {
            try await auth.signIn(email: email.trimmingCharacters(in: .whitespaces), password: password)
            // Succeeded (token stored, or routed to 2FA). Never keep the
            // password around.
            password = ""
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = APIError.invalidResponse.userMessage
        }

        isSubmitting = false
    }
}

/// The sign-in screen. Branded navy backdrop with the logo lockup, a glassy
/// credentials card, a prominent emerald sign-in button, and a route to the
/// "create an account / start your events" page — so the first screen carries
/// the product's identity rather than a bland system form.
@available(iOS 17.0, *)
struct SignInView: View {
    @StateObject private var model: SignInViewModel
    @FocusState private var focusedField: Field?

    private enum Field { case email, password }

    init(auth: AuthStore) {
        _model = StateObject(wrappedValue: SignInViewModel(auth: auth))
    }

    var body: some View {
        NavigationStack {
            ZStack {
                // Branded backdrop that mirrors the post-login flourish: the
                // same navy base the TicketBurst uses, warmed with a subtle
                // emerald wash toward the bottom so the welcome animation's
                // colours flow straight into the sign-in screen.
                ZStack {
                    Brand.navy.ignoresSafeArea()
                    LinearGradient(
                        colors: [
                            Brand.emerald.opacity(0.0),
                            Brand.emerald.opacity(0.18)
                        ],
                        startPoint: .top,
                        endPoint: .bottom
                    )
                    .ignoresSafeArea()
                }

                ScrollView {
                    VStack(spacing: 24) {
                        branding
                        card
                        createAccountLink
                    }
                    .padding(24)
                    .frame(maxWidth: 520)
                    .frame(maxWidth: .infinity)
                }
                .scrollDismissesKeyboard(.interactively)
            }
            .toolbar(.hidden, for: .navigationBar)
        }
    }

    // MARK: - Branding lockup

    private var branding: some View {
        VStack(spacing: 12) {
            Image("BrandLogo")
                .resizable()
                .scaledToFit()
                .frame(width: 96, height: 96)
                .clipShape(RoundedRectangle(cornerRadius: 22))
                .shadow(color: .black.opacity(0.25), radius: 12, y: 8)
                .accessibilityHidden(true)
            Text("Events by CK Enterprises")
                .font(.title2.bold())
                .foregroundStyle(.white)
                .multilineTextAlignment(.center)
        }
        .padding(.top, 24)
    }

    // MARK: - Credentials card

    private var card: some View {
        VStack(spacing: 16) {
            field(icon: "envelope") {
                TextField("Email", text: $model.email)
                    .textContentType(.username)
                    .keyboardType(.emailAddress)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .focused($focusedField, equals: .email)
                    .submitLabel(.next)
                    .onSubmit { focusedField = .password }
            }

            Divider().background(.white.opacity(0.1))

            field(icon: "lock") {
                SecureField("Password", text: $model.password)
                    .textContentType(.password)
                    .focused($focusedField, equals: .password)
                    .submitLabel(.go)
                    .onSubmit { Task { await model.submit() } }
            }

            if let errorMessage = model.errorMessage {
                HStack(spacing: 8) {
                    Image(systemName: "exclamationmark.circle.fill")
                        .foregroundStyle(Brand.danger)
                    Text(errorMessage)
                        .font(.callout)
                        .foregroundStyle(.white)
                    Spacer(minLength: 0)
                }
                .padding(.vertical, 4)
                .accessibilityIdentifier("signIn.error")
            }

            Button {
                focusedField = nil
                Task { await model.submit() }
            } label: {
                Group {
                    if model.isSubmitting {
                        ProgressView().tint(Brand.navy)
                    } else {
                        Text("Sign In").fontWeight(.semibold)
                    }
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 14)
            }
            .background(model.canSubmit ? Brand.emerald : Brand.emerald.opacity(0.4))
            .foregroundStyle(Brand.navy)
            .clipShape(RoundedRectangle(cornerRadius: 14))
            .disabled(!model.canSubmit)
            .accessibilityIdentifier("signIn.submit")
        }
        .padding(20)
        .background(.white.opacity(0.08), in: RoundedRectangle(cornerRadius: 22))
        .overlay(
            RoundedRectangle(cornerRadius: 22)
                .stroke(.white.opacity(0.12), lineWidth: 1)
        )
    }

    /// A single themed input row: an icon plus the supplied field.
    private func field<Content: View>(icon: String, @ViewBuilder content: () -> Content) -> some View {
        HStack(spacing: 12) {
            Image(systemName: icon)
                .foregroundStyle(.white.opacity(0.6))
                .frame(width: 20)
                .accessibilityHidden(true)
            content()
                .foregroundStyle(.white)
                .tint(Brand.emerald)
        }
    }

    // MARK: - Create account

    private var createAccountLink: some View {
        VStack(spacing: 6) {
            Text("New here?")
                .font(.footnote)
                .foregroundStyle(.white.opacity(0.6))
            NavigationLink {
                CreateEventView(webDashboardURL: nil)
            } label: {
                Text("Create an account & start your events")
                    .font(.callout.weight(.semibold))
                    .foregroundStyle(Brand.emerald)
            }
            .accessibilityIdentifier("signIn.createAccount")
        }
        .padding(.top, 4)
    }
}
