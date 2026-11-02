import SwiftUI

/// Shared, app-wide presentation for `APIError` so every feature screen renders
/// the same consistent messaging and takes the same navigation action for each
/// case (see the design's iOS error mapping, Requirements 9.1, 9.2, 3.2).
///
/// The mapping, in one place:
///
///   - `.offline`       → show the offline message; **never** report a
///                        successful check-in (Requirements 9.1, 9.2). This is
///                        presentation only — nothing here queues a scan.
///   - `.unauthorized`  → the 401 hook in `AuthStore`/`APIClient` has already
///                        cleared the Keychain token and routed back to sign-in
///                        (Requirement 3.2); the view just surfaces the message
///                        so the user understands why they were signed out.
///   - `.forbidden`     → the role lacks the action; the feature is hidden or a
///                        "not permitted" message is shown. No data changed.
///   - `.serverMessage` → surface the API's human-facing message verbatim.
///   - everything else  → surface the error's `userMessage` fallback.
///
/// Feature view-models already hold an optional user-facing string (e.g.
/// `SignInViewModel.errorMessage`) derived from `APIError.userMessage`; this
/// file adds the *shared view layer* on top of that single source of truth so
/// each screen does not reinvent the alert/banner.
@available(iOS 17.0, *)
enum UserFacingError {

    /// How an `APIError` should be presented to the user.
    enum Presentation: Equatable {
        /// A transient banner (offline). Non-blocking; the user keeps working
        /// and retries when connectivity returns. Never implies success.
        case banner(String)
        /// A blocking alert carrying a message (server/validation/decode/etc.).
        case alert(String)
        /// Handled by navigation (401 → sign-in; 403 → hide the feature); the
        /// view typically does not need to show a modal, only the message.
        case navigation(String)
    }

    /// Map an `APIError` to its presentation. Reuses `APIError.userMessage` as
    /// the single source of copy so wording stays consistent everywhere.
    static func presentation(for error: APIError) -> Presentation {
        switch error {
        case .offline:
            return .banner(error.userMessage)
        case .unauthorized, .forbidden:
            return .navigation(error.userMessage)
        case .serverMessage, .insecureBaseURL, .decoding, .invalidResponse:
            return .alert(error.userMessage)
        }
    }

    /// The user-facing message for any `Error`, mapping `APIError` through its
    /// `userMessage` and falling back to a generic line for anything else. A
    /// convenience for call sites that catch a bare `Error`.
    static func message(for error: Error) -> String {
        if let apiError = error as? APIError {
            return apiError.userMessage
        }
        return APIError.invalidResponse.userMessage
    }
}

/// A slim, reusable banner for the offline state. Features show this at the top
/// of their content while `Reachability.isOnline` is `false`, so the operator
/// understands why a scan will not complete (Requirement 9.1) without the app
/// ever queuing the scan (Requirement 9.2).
@available(iOS 17.0, *)
struct OfflineBanner: View {
    /// The message to show. Defaults to the shared offline copy.
    var message: String = APIError.offline.userMessage

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: "wifi.slash")
                .accessibilityHidden(true)
            Text(message)
                .font(.callout.weight(.medium))
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.orange.opacity(0.15))
        .foregroundStyle(.orange)
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("offline.banner")
    }
}

/// A reusable inline error line, matching the style `SignInView` already uses.
/// Features bind it to the user-facing string their view-model derives from
/// `APIError.userMessage`.
@available(iOS 17.0, *)
struct InlineErrorText: View {
    let message: String

    var body: some View {
        Text(message)
            .font(.callout)
            .foregroundStyle(.red)
            .accessibilityIdentifier("error.inline")
    }
}

// MARK: - errorAlert view modifier

/// Presents an alert whenever the bound `APIError` becomes non-nil, using the
/// shared `UserFacingError` message. Clearing the binding dismisses the alert.
///
/// This gives every feature screen a one-liner for consistent error alerts:
///
/// ```swift
/// SomeView()
///     .errorAlert($viewModel.error)
/// ```
///
/// `.offline` is shown here too (as an alert) for screens that prefer a modal
/// over the inline `OfflineBanner`; either way, presenting the message does not
/// — and must not — report a successful check-in (Requirements 9.1, 9.2).
@available(iOS 17.0, *)
private struct ErrorAlertModifier: ViewModifier {
    @Binding var error: APIError?

    /// Drives SwiftUI's `isPresented` from the optional error without losing the
    /// message while the alert dismisses.
    private var isPresented: Binding<Bool> {
        Binding(
            get: { error != nil },
            set: { presented in if !presented { error = nil } }
        )
    }

    func body(content: Content) -> some View {
        content.alert(
            "Something went wrong",
            isPresented: isPresented,
            presenting: error
        ) { _ in
            Button("OK", role: .cancel) { error = nil }
        } message: { apiError in
            Text(apiError.userMessage)
        }
    }
}

@available(iOS 17.0, *)
extension View {
    /// Attach a shared error alert bound to an optional `APIError`. Set the
    /// binding to show the alert; it clears itself on dismissal.
    func errorAlert(_ error: Binding<APIError?>) -> some View {
        modifier(ErrorAlertModifier(error: error))
    }
}
