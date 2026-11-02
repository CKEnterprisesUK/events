import Foundation

/// The single error type every networking call surfaces. The `APIClient` maps
/// transport failures, non-2xx responses, and decode failures onto these cases
/// so feature view-models can switch on a small, meaningful set rather than on
/// raw `URLError`/`HTTPURLResponse` details.
///
/// The mapping (see `APIClient`) is:
///
///   - `.offline`            — a transport failure that means the device could
///                             not reach the API (`URLError` such as
///                             `.notConnectedToInternet`, `.timedOut`,
///                             `.cannotConnectToHost`, …). The UI shows an
///                             offline message and never reports a successful
///                             check-in (Requirements 9.1, 9.2).
///   - `.unauthorized`       — HTTP 401. The token is missing, revoked, expired,
///                             or bound to a different device (Requirement 13.2,
///                             13.4). The client clears the Keychain token and
///                             routes back to sign-in (Requirement 3.2, 3.3).
///   - `.forbidden`          — HTTP 403. The role lacks the action. The feature
///                             is hidden / "not permitted" is shown; no data
///                             changed server-side.
///   - `.serverMessage`      — any other non-2xx. Carries the human-facing
///                             message parsed from the API error body (Laravel's
///                             `{ "message": "…" }`), or a fallback.
///   - `.insecureBaseURL`    — the configured base URL is not HTTPS. The client
///                             refuses to send cleartext (Requirement 12.2).
///   - `.decoding`           — a 2xx response whose body did not match the
///                             expected `Codable` shape.
///   - `.invalidResponse`    — the response was not an `HTTPURLResponse` (should
///                             not happen for HTTP(S) requests, guarded for
///                             completeness).
enum APIError: Error, Equatable {

    /// No network path to the API. The caller must not report success.
    case offline

    /// HTTP 401 — authentication failed (missing/revoked/expired/wrong-device
    /// token). Triggers Keychain clear + return to sign-in.
    case unauthorized

    /// HTTP 403 — the authenticated user's role does not permit the action.
    case forbidden

    /// Any other non-2xx, carrying the API's human-facing message.
    case serverMessage(String)

    /// The configured base URL was not `https://`. Nothing is sent.
    case insecureBaseURL

    /// A 2xx body could not be decoded into the expected model.
    case decoding(String)

    /// The response was not an HTTP response.
    case invalidResponse

    /// A user-facing message for each case. Feature screens may show these
    /// directly or remap them; `.offline`/`.unauthorized`/`.forbidden` usually
    /// drive navigation rather than a raw string (see the design's iOS error
    /// mapping).
    var userMessage: String {
        switch self {
        case .offline:
            return "You're offline. Reconnect to scan."
        case .unauthorized:
            return "Your session has expired. Please sign in again."
        case .forbidden:
            return "You don't have permission to do that."
        case let .serverMessage(message):
            return message
        case .insecureBaseURL:
            return "The server address must use HTTPS."
        case .decoding:
            return "The server sent an unexpected response."
        case .invalidResponse:
            return "The server sent an unexpected response."
        }
    }
}
