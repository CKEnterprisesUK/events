import Foundation

/// Adapts a `TokenStore` (the Keychain) to the networking layer's
/// `TokenProviding` protocol, so `APIClient` reads the current Access_Token
/// through behaviour rather than depending on `KeychainStore` directly.
///
/// The token value never leaves the Keychain except to be placed on the
/// `Authorization` header by `APIClient` — it is never copied into `AppState`
/// (Requirements 12.1, 12.4).
struct TokenStoreProvider: TokenProviding {
    let store: TokenStore
    func currentToken() -> String? { store.read() }
}

/// Owns the authentication flow and the one piece of wiring the whole app
/// shares: the `APIClient`, the Keychain `TokenStore`, the stable
/// `DeviceIdentifying`, and the `AppState` they all drive.
///
/// The view models (sign-in, two-factor, company picker) call the small, async
/// methods here rather than touching `APIClient`, the Keychain, or `AppState`
/// transitions themselves — so the rules "store the token, then fetch `me`,
/// then advance" and "a single membership auto-selects" live in exactly one
/// place.
///
/// `AuthStore` also satisfies `APIClient`'s `UnauthorizedHandling`: on a 401 the
/// client calls `didReceiveUnauthorized()`, which clears the Keychain token and
/// returns `AppState` to sign-in (Requirements 3.2, 3.3). Because that handler
/// mutates `AppState` (main-actor state), `AuthStore` is `@MainActor`.
///
/// Requires iOS 17 because it drives the `@Observable` `AppState` — consistent
/// with the rest of the app (see `EventsScannerApp.swift` / README).
@available(iOS 17.0, *)
@MainActor
final class AuthStore: UnauthorizedHandling {

    /// The shared app state this store advances through the auth flow.
    let appState: AppState

    /// Secure token storage (the Keychain). Only ever holds the Access_Token.
    private let tokenStore: TokenStore

    /// The stable per-device id sent in auth bodies and as `X-Device-Id`.
    private let deviceIdentity: DeviceIdentifying

    /// The one networking client, wired to read the token from `tokenStore`,
    /// the device id from `deviceIdentity`, and route 401s back here.
    private let client: APIClient

    /// Credentials held in memory only between a `2fa_required` login response
    /// and the TOTP challenge that must re-send them. Never persisted and
    /// cleared the moment the flow leaves the two-factor step (Requirement
    /// 12.4).
    private var pendingCredentials: (email: String, password: String)?

    /// Build the whole auth stack around an existing `AppState`.
    ///
    /// - Parameters:
    ///   - appState: the shared, observable app state to drive.
    ///   - baseURL: the HTTPS API base URL.
    ///   - transport: the HTTP transport (injected in tests).
    ///   - tokenStore: secure token storage (defaults to the real Keychain).
    ///   - deviceIdentity: the stable device id source (defaults to the real
    ///     Keychain-backed `DeviceIdentity`).
    /// - Throws: `APIError.insecureBaseURL` when `baseURL` is not HTTPS.
    init(
        appState: AppState,
        baseURL: URL,
        transport: HTTPTransport = URLSession.shared,
        tokenStore: TokenStore = KeychainStore(),
        deviceIdentity: DeviceIdentifying = DeviceIdentity()
    ) throws {
        self.appState = appState
        self.tokenStore = tokenStore
        self.deviceIdentity = deviceIdentity

        // A reference box lets the client's unauthorized handler point back at
        // this instance even though the client is built inside `init`.
        let box = UnauthorizedBox()
        self.client = try APIClient(
            baseURL: baseURL,
            transport: transport,
            tokenProvider: TokenStoreProvider(store: tokenStore),
            deviceIdentity: deviceIdentity,
            unauthorizedHandler: box
        )
        box.target = self
    }

    // MARK: - Sign-in flow

    /// Submit credentials + `device_id` to `POST /api/auth/login`.
    ///
    /// - On a token response: store the token, fetch `me`, and advance to
    ///   company selection (Requirement 1.1).
    /// - On `{status:"2fa_required"}`: route to the two-factor challenge with
    ///   no token stored (Requirement 1.3).
    ///
    /// The password is passed straight into the request body and never retained
    /// or persisted (Requirement 12.4).
    func signIn(email: String, password: String) async throws {
        let body = LoginRequest(
            email: email,
            password: password,
            deviceId: deviceIdentity.current()
        )
        let response: AuthResponseDTO = try await client.send(.login, body: body)

        if response.requiresTwoFactor {
            // Hold the credentials in memory for the challenge that must
            // re-send them; never written to disk.
            pendingCredentials = (email: email, password: password)
            appState.requireTwoFactor()
            return
        }

        guard let token = response.token, !token.isEmpty else {
            throw APIError.serverMessage("The server did not return a session token.")
        }
        try await completeAuthentication(storing: token)
    }

    // MARK: - Two-factor flow

    /// Submit the pending credentials + `device_id` + a TOTP `code` or
    /// `recovery_code` to `POST /api/auth/two-factor`. On a token response,
    /// store it and continue exactly as a direct sign-in would (Requirement
    /// 2.2). A failure throws an `APIError` and stores nothing (Requirement
    /// 2.3). The credentials are supplied from the in-memory hold established
    /// at `2fa_required`, so the TOTP screen never has to carry the password
    /// itself.
    func verifyTwoFactor(code: String?, recoveryCode: String?) async throws {
        guard let credentials = pendingCredentials else {
            throw APIError.serverMessage("Your session expired before the code could be verified. Please sign in again.")
        }

        let body = TwoFactorRequest(
            email: credentials.email,
            password: credentials.password,
            deviceId: deviceIdentity.current(),
            code: code?.isEmpty == true ? nil : code,
            recoveryCode: recoveryCode?.isEmpty == true ? nil : recoveryCode
        )
        let response: AuthResponseDTO = try await client.send(.twoFactor, body: body)

        guard let token = response.token, !token.isEmpty else {
            throw APIError.serverMessage("The two-factor challenge did not return a session token.")
        }
        // Drop the held credentials the instant the factor succeeds.
        pendingCredentials = nil
        try await completeAuthentication(storing: token)
    }

    /// Abandon the two-factor step (e.g. the user backs out). Clears the held
    /// credentials and returns to sign-in so nothing lingers in memory.
    func cancelTwoFactor() {
        pendingCredentials = nil
        appState.didSignOut()
    }

    // MARK: - Scanning

    /// Submit a scanned QR `payload` to `POST /api/scan` and return the decoded
    /// Scan_Outcome (Requirements 10.4, 6.2).
    ///
    /// No HMAC/verification happens on the device (Requirement 9.3): the raw
    /// decoded string is sent as-is and the server is the sole authority on the
    /// outcome. A transport failure surfaces `APIError.offline` to the caller,
    /// which must never be reported as a successful check-in (Requirement 9.2).
    func submitScan(payload: String) async throws -> ScanOutcomeDTO {
        try await client.send(
            .scan,
            body: ScanRequest(payload: payload, reference: nil, eventId: appState.selectedEvent?.id)
        )
    }

    /// Submit a manually typed Order_Reference to `POST /api/scan` and return
    /// the decoded Scan_Outcome (Requirements 6.1, 6.3). This is the
    /// always-available fallback to the camera: a manual reference and its
    /// scanned payload resolve to the same outcome server-side.
    func submitManual(reference: String) async throws -> ScanOutcomeDTO {
        try await client.send(
            .scan,
            body: ScanRequest(payload: nil, reference: reference, eventId: appState.selectedEvent?.id)
        )
    }

    // MARK: - Company selection

    /// Select a company for the current token via
    /// `POST /api/companies/{id}/select` and advance to the main tabs.
    func select(company: AppState.Membership) async throws {
        let response: SelectCompanyResponseDTO = try await client.send(.selectCompany(id: company.id))
        let selected = AppState.Membership(
            id: response.company.id,
            name: response.company.name,
            role: response.company.role
        )
        appState.didSelectCompany(selected, permittedActions: response.permittedActions)
    }

    // MARK: - Live dashboard

    /// Fetch the live door snapshot for the Active_Event via
    /// `GET /api/dashboard/live`. Routed through `AuthStore` so the whole app
    /// keeps a single `APIClient` with one 401 handler: a revoked/expired or
    /// wrong-device token surfaces as `APIError.unauthorized` here and the
    /// client has already cleared the Keychain and returned to sign-in
    /// (Requirements 3.2, 3.3). The caller (DashboardViewModel) decodes the
    /// attendance figures and recent scan feed.
    func liveSnapshot() async throws -> LiveSnapshotDTO {
        try await client.send(.dashboardLive(eventId: appState.selectedEvent?.id))
    }

    /// Fetch the live door snapshot for a SPECIFIC event id (the Dashboard's own
    /// event picker), independent of the scan selection.
    func liveSnapshot(eventId: Int?) async throws -> LiveSnapshotDTO {
        try await client.send(.dashboardLive(eventId: eventId))
    }

    // MARK: - Events

    /// List the Active_Company's events for the Events tab via `GET /api/events`.
    func listEvents() async throws -> EventsResponseDTO {
        try await client.send(.events)
    }

    // MARK: - Customer detail & orders

    /// Fetch one customer's orders via `GET /api/customers/{token}`.
    func customerDetail(token: String) async throws -> CustomerDetailDTO {
        try await client.send(.customerDetail(token: token))
    }

    /// Fetch one order's detail via `GET /api/orders/{id}`.
    func orderDetail(id: Int) async throws -> OrderDetailDTO {
        try await client.send(.order(id: id))
    }

    /// Manually mark an order as entered via `POST /api/orders/{id}/check-in`.
    /// Returns the same Scan_Outcome contract as a camera scan. The server gates
    /// this on `check_in`, so an accountant who can view orders cannot check in.
    func checkInOrder(id: Int) async throws -> ScanOutcomeDTO {
        try await client.send(.orderCheckIn(id: id), body: EmptyBody())
    }

    // MARK: - Logout

    /// Revoke the current token server-side via `POST /api/auth/logout`, then
    /// clear the Keychain token and return to sign-in. Unlike the 401 path, this
    /// actively revokes the token on the server so it can never be reused. A
    /// transport failure still signs the user out locally — we never want a
    /// "stuck signed in" state, and the token will expire regardless.
    func logout() async {
        do {
            try await client.send(.logout, body: EmptyBody())
        } catch {
            // Best effort: even if the revoke call fails (offline), clear local
            // state below so the user is signed out of the app.
        }
        try? tokenStore.delete()
        appState.didSignOut()
    }

    // MARK: - Sessions (self)

    /// List the signed-in user's own active Sessions via `GET /api/sessions`
    /// (Requirement 14.1). Each row carries its device label, last-used instant,
    /// and a `current` flag for the token this request authenticated with.
    func listSessions() async throws -> [SessionDTO] {
        let response: SessionsResponseDTO = try await client.send(.sessions)
        return response.sessions
    }

    /// Revoke one of the signed-in user's own Sessions via
    /// `DELETE /api/sessions/{id}` (Requirement 14.2). A foreign id is a 404 on
    /// the server, surfaced here as `APIError.serverMessage`.
    func revokeSession(id: Int) async throws {
        try await client.send(.revokeSession(id: id))
    }

    // Team-member session management was removed from the app (it is a
    // web-admin responsibility). The self-session calls above remain; the
    // Profile screen uses `logout()` rather than revoking individual sessions.

    // MARK: - Customer search

    /// Search the Active_Company's customers via `GET /api/customers?q=term`
    /// and return the decoded rows (Requirement 8.2). An empty/`nil` term
    /// returns the whole tenant-scoped roster. Routed through `AuthStore` so
    /// the app keeps a single `APIClient` with one 401 handler, exactly like
    /// `liveSnapshot()` and the scan calls.
    ///
    /// The server gates this on `ACTION_MANAGE_ORDERS`; the client only shows
    /// the Customer Search tab when the role holds that action (Requirements
    /// 8.4, 8.5), so a 403 is a defensive case the caller surfaces gracefully
    /// rather than the normal path.
    func searchCustomers(query: String?) async throws -> CustomersResponseDTO {
        try await client.send(.customers(query: query))
    }

    // MARK: - 401 handling

    /// Called by `APIClient` on any observed 401 (token missing/revoked/expired
    /// or bound to a different device). Clears the Keychain token and returns
    /// `AppState` to sign-in (Requirements 3.2, 3.3).
    func didReceiveUnauthorized() async {
        pendingCredentials = nil
        try? tokenStore.delete()
        appState.didSignOut()
    }

    // MARK: - Internals

    /// Store the issued token, then fetch `me` to learn memberships + permitted
    /// actions, then advance the flow to company selection.
    private func completeAuthentication(storing token: String) async throws {
        try tokenStore.save(token: token)

        let me: MeResponseDTO = try await client.send(.me)
        appState.applyMe(
            name: me.user.name,
            email: me.user.email,
            permittedActions: me.permittedActions,
            memberships: me.companies.map {
                AppState.Membership(id: $0.id, name: $0.name, role: $0.role)
            }
        )
        appState.didAuthenticate()
    }
}

/// A tiny mutable reference the `APIClient` holds so its unauthorized hook can
/// reach the `AuthStore` that is still being constructed in `AuthStore.init`.
/// `APIClient` only ever calls `didReceiveUnauthorized()`, so forwarding is all
/// this needs to do.
@available(iOS 17.0, *)
private final class UnauthorizedBox: UnauthorizedHandling {
    weak var target: AuthStore?
    func didReceiveUnauthorized() async {
        await target?.didReceiveUnauthorized()
    }
}

// MARK: - Request bodies
//
// Encodable bodies for the auth endpoints. Kept here (not in DTOs.swift, which
// models responses) so the request shapes sit next to the flow that sends them.

/// `POST /api/auth/login` body: credentials + the stable device id.
struct LoginRequest: Encodable, Equatable {
    let email: String
    let password: String
    let deviceId: String

    enum CodingKeys: String, CodingKey {
        case email
        case password
        case deviceId = "device_id"
    }
}

/// `POST /api/auth/two-factor` body: credentials + device id + one of a TOTP
/// `code` or a `recovery_code`. Both factors are optional in the type; the
/// view model supplies exactly one.
struct TwoFactorRequest: Encodable, Equatable {
    let email: String
    let password: String
    let deviceId: String
    let code: String?
    let recoveryCode: String?

    enum CodingKeys: String, CodingKey {
        case email
        case password
        case deviceId = "device_id"
        case code
        case recoveryCode = "recovery_code"
    }
}

/// `POST /api/scan` body: exactly one of a scanned `payload` or a manually
/// typed `reference`. Both are optional in the type; the scan path supplies the
/// decoded QR `payload`, the manual-entry fallback supplies the `reference`
/// (Requirements 6.1, 6.3). `nil` fields are omitted from the JSON so the
/// server sees only the key that was provided.
struct ScanRequest: Encodable, Equatable {
    let payload: String?
    let reference: String?
    /// The chosen event to scope this scan to. When present the server rejects
    /// an order for a different event as "wrong event". Omitted from the JSON
    /// when nil (no event selected).
    let eventId: Int?

    func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        if let payload { try container.encode(payload, forKey: .payload) }
        if let reference { try container.encode(reference, forKey: .reference) }
        if let eventId { try container.encode(eventId, forKey: .eventId) }
    }

    enum CodingKeys: String, CodingKey {
        case payload
        case reference
        case eventId = "event_id"
    }
}

/// An empty JSON body (`{}`) for POST endpoints that take no parameters (manual
/// check-in, logout). Lets those calls reuse the body-carrying `send` overload.
struct EmptyBody: Encodable, Equatable {}
