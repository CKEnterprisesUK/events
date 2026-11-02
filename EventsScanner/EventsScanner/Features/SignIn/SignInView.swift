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

/// The sign-in form. Minimal by design: an email field, a secure password
/// field, a submit button, and an inline error line.
@available(iOS 17.0, *)
struct SignInView: View {
    @StateObject private var model: SignInViewModel

    init(auth: AuthStore) {
        _model = StateObject(wrappedValue: SignInViewModel(auth: auth))
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    VStack(spacing: 10) {
                        Image("BrandLogo")
                            .resizable()
                            .scaledToFit()
                            .frame(width: 94, height: 94)
                            .clipShape(RoundedRectangle(cornerRadius: 20))
                            .accessibilityHidden(true)
                        Text("Events by CK Enterprises")
                            .font(.title2.bold())
                            .multilineTextAlignment(.center)
                        Text("Secure event check-in")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 18)
                }
                Section {
                    TextField("Email", text: $model.email)
                        .textContentType(.username)
                        .keyboardType(.emailAddress)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()

                    SecureField("Password", text: $model.password)
                        .textContentType(.password)
                }

                if let errorMessage = model.errorMessage {
                    Section {
                        Text(errorMessage)
                            .font(.callout)
                            .foregroundStyle(.red)
                            .accessibilityIdentifier("signIn.error")
                    }
                }

                Section {
                    Button {
                        Task { await model.submit() }
                    } label: {
                        if model.isSubmitting {
                            ProgressView()
                                .frame(maxWidth: .infinity)
                        } else {
                            Text("Sign In")
                                .frame(maxWidth: .infinity)
                        }
                    }
                    .disabled(!model.canSubmit)
                }
            }
            .navigationTitle("Sign In")
        }
    }
}
