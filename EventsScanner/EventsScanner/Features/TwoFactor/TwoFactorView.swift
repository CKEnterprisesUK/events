import SwiftUI

/// Drives the two-factor challenge: collects a TOTP code (or a recovery code)
/// and submits it, with the credentials the `AuthStore` is already holding from
/// the `2fa_required` login response, to `POST /api/auth/two-factor`.
///
/// On a token response the `AuthStore` stores the token, fetches `me`, and
/// advances the flow exactly as a direct sign-in would (Requirement 2.2). On a
/// failure the error is surfaced and no token is stored (Requirement 2.3).
///
/// The screen never carries the password — the `AuthStore` re-sends the
/// credentials it held in memory — so this view model holds only the
/// challenge code the user types.
@available(iOS 17.0, *)
@MainActor
final class TwoFactorViewModel: ObservableObject {

    /// Whether the user is entering a TOTP `code` or a `recovery_code`.
    enum Mode: Equatable {
        case totp
        case recovery
    }

    @Published var mode: Mode = .totp
    @Published var code: String = ""
    @Published private(set) var isSubmitting: Bool = false
    @Published var errorMessage: String?

    private let auth: AuthStore

    init(auth: AuthStore) {
        self.auth = auth
    }

    /// Whether the entered value may be submitted: non-empty and no request in
    /// flight.
    var canSubmit: Bool {
        !code.trimmingCharacters(in: .whitespaces).isEmpty && !isSubmitting
    }

    /// Verify the entered factor. Exactly one of `code`/`recovery_code` is sent
    /// depending on `mode`.
    func submit() async {
        guard canSubmit else { return }
        isSubmitting = true
        errorMessage = nil

        let trimmed = code.trimmingCharacters(in: .whitespaces)
        do {
            switch mode {
            case .totp:
                try await auth.verifyTwoFactor(code: trimmed, recoveryCode: nil)
            case .recovery:
                try await auth.verifyTwoFactor(code: nil, recoveryCode: trimmed)
            }
            code = ""
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = APIError.invalidResponse.userMessage
        }

        isSubmitting = false
    }

    /// Back out of the challenge and return to sign-in, clearing the held
    /// credentials.
    func cancel() {
        auth.cancelTwoFactor()
    }
}

/// The two-factor challenge form: a segmented picker to switch between an
/// authenticator code and a recovery code, the entry field, a verify button,
/// and an inline error line.
@available(iOS 17.0, *)
struct TwoFactorView: View {
    @StateObject private var model: TwoFactorViewModel

    init(auth: AuthStore) {
        _model = StateObject(wrappedValue: TwoFactorViewModel(auth: auth))
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    Picker("Method", selection: $model.mode) {
                        Text("Authenticator code").tag(TwoFactorViewModel.Mode.totp)
                        Text("Recovery code").tag(TwoFactorViewModel.Mode.recovery)
                    }
                    .pickerStyle(.segmented)

                    TextField(
                        model.mode == .totp ? "6-digit code" : "Recovery code",
                        text: $model.code
                    )
                    .textContentType(.oneTimeCode)
                    .keyboardType(model.mode == .totp ? .numberPad : .default)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                } footer: {
                    Text("Enter the code from your authenticator app, or use a recovery code.")
                }

                if let errorMessage = model.errorMessage {
                    Section {
                        Text(errorMessage)
                            .font(.callout)
                            .foregroundStyle(.red)
                            .accessibilityIdentifier("twoFactor.error")
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
                            Text("Verify")
                                .frame(maxWidth: .infinity)
                        }
                    }
                    .disabled(!model.canSubmit)
                }
            }
            .navigationTitle("Two-Factor")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { model.cancel() }
                }
            }
        }
    }
}
