import Foundation

/// The HTTP method for a request. A small enum keeps call sites readable and
/// the `APIClient` free of stringly-typed verbs.
enum HTTPMethod: String {
    case get = "GET"
    case post = "POST"
    case delete = "DELETE"
}

/// A single, fully described API call: its method and its path relative to the
/// base URL (always starting with `/api/…`). `Endpoint` only names *where* and
/// *how*; the request body (if any) is passed separately to `APIClient` so the
/// endpoint list stays a flat, testable catalogue of the server's surface.
///
/// The catalogue mirrors `routes/api.php` one-to-one (see the design's "API
/// Endpoints" table):
///
///   POST   /api/auth/login
///   POST   /api/auth/two-factor
///   POST   /api/auth/logout
///   GET    /api/auth/me
///   GET    /api/companies
///   POST   /api/companies/{id}/select
///   POST   /api/scan
///   GET    /api/dashboard/live
///   GET    /api/events
///   GET    /api/customers?q=…
///   GET    /api/customers/{token}
///   GET    /api/orders/{order}
///   POST   /api/orders/{order}/check-in
///   GET    /api/sessions
///   DELETE /api/sessions/{id}
struct Endpoint: Equatable {
    let method: HTTPMethod

    /// Path relative to the base URL, e.g. `/api/auth/login`. Always begins
    /// with a slash and is percent-encoded by the caller where it interpolates
    /// dynamic values (ids are integers here, so no encoding is required).
    let path: String

    private init(_ method: HTTPMethod, _ path: String) {
        self.method = method
        self.path = path
    }

    // MARK: - Authentication

    /// Step one of sign-in: submit `email`, `password`, `device_id`.
    /// Responds with `{token, permitted_actions}` or `{status:"2fa_required"}`.
    static let login = Endpoint(.post, "/api/auth/login")

    /// Step two of sign-in: submit credentials + `device_id` + `code` or
    /// `recovery_code`. Responds with `{token, permitted_actions}`.
    static let twoFactor = Endpoint(.post, "/api/auth/two-factor")

    /// Revoke the current token. Responds `204`.
    static let logout = Endpoint(.post, "/api/auth/logout")

    /// The authenticated user, permitted actions, and memberships.
    static let me = Endpoint(.get, "/api/auth/me")

    // MARK: - Company selection

    /// List the user's company memberships.
    static let companies = Endpoint(.get, "/api/companies")

    /// Select (or switch to) the Active_Company for the current token.
    static func selectCompany(id: Int) -> Endpoint {
        Endpoint(.post, "/api/companies/\(id)/select")
    }

    // MARK: - Scanning & dashboard

    /// Submit a scanned `payload` or a manual `reference` for check-in.
    static let scan = Endpoint(.post, "/api/scan")

    /// The live door snapshot. With `eventId` the snapshot is pinned to that
    /// chosen Event (`?event=`); without it the server resolves its own
    /// Active_Event (the prior behaviour).
    static func dashboardLive(eventId: Int?) -> Endpoint {
        guard let eventId else {
            return Endpoint(.get, "/api/dashboard/live")
        }
        return Endpoint(.get, "/api/dashboard/live?event=\(eventId)")
    }

    // MARK: - Events

    /// List the Active_Company's events (the app's landing tab). Carries
    /// tickets-sold counts and the public web URL per event.
    static let events = Endpoint(.get, "/api/events")

    // MARK: - Customer search & detail

    /// Search the Active_Company's customers. `query` is appended as `?q=…`
    /// (percent-encoded); an empty/`nil` term returns the whole roster. The
    /// server also matches an order reference, so a reference finds its owner.
    static func customers(query: String?) -> Endpoint {
        guard let query, !query.isEmpty else {
            return Endpoint(.get, "/api/customers")
        }

        let encoded = query.addingPercentEncoding(
            withAllowedCharacters: .urlQueryValueAllowed
        ) ?? query

        return Endpoint(.get, "/api/customers?q=\(encoded)")
    }

    /// One customer's orders, addressed by the base64url email `token` the
    /// search returns.
    static func customerDetail(token: String) -> Endpoint {
        let encoded = token.addingPercentEncoding(
            withAllowedCharacters: .urlPathAllowed
        ) ?? token
        return Endpoint(.get, "/api/customers/\(encoded)")
    }

    // MARK: - Orders & manual check-in

    /// One order's detail.
    static func order(id: Int) -> Endpoint {
        Endpoint(.get, "/api/orders/\(id)")
    }

    /// Manually mark one order as entered (staffed check-in). Returns the same
    /// Scan_Outcome contract as `/api/scan`.
    static func orderCheckIn(id: Int) -> Endpoint {
        Endpoint(.post, "/api/orders/\(id)/check-in")
    }

    // MARK: - Sessions (self)
    //
    // Team-member session management was removed from the app — managing other
    // members is a web-admin job. Only the signed-in user's own sessions remain
    // addressable (the Profile screen's "sign out" uses `logout`, not these).

    /// List the signed-in user's own active sessions.
    static let sessions = Endpoint(.get, "/api/sessions")

    /// Revoke one of the signed-in user's own sessions.
    static func revokeSession(id: Int) -> Endpoint {
        Endpoint(.delete, "/api/sessions/\(id)")
    }
}

private extension CharacterSet {
    /// Query-value-safe character set: the standard query set minus the
    /// sub-delimiters (`&`, `=`, `+`, …) that would otherwise be read as query
    /// structure, so a search term containing them survives round-trip.
    static let urlQueryValueAllowed: CharacterSet = {
        var allowed = CharacterSet.urlQueryAllowed
        allowed.remove(charactersIn: "&=+?#")
        return allowed
    }()
}
