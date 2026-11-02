import XCTest
@testable import EventsScanner

/// Tests for the sign-in → company-selection routing and the 401 reset
/// (Requirements 4.2, 4.6, 3.2).
///
/// These drive `AuthStore` on the `@MainActor` with a stub `HTTPTransport` that
/// returns canned `(Data, HTTPURLResponse)` per request path, the in-memory
/// `TokenStore` double (`InMemoryTokenStore`, from `KeychainStoreTests.swift`),
/// and a closure-backed `DeviceIdentifying` (`ClosureDeviceIdentity`). Nothing
/// touches a live server or the system Keychain, so the flow is exercised
/// deterministically.
///
/// What the three scenarios assert:
///
/// - SINGLE membership (Requirement 4.6): `login` → `{token}`, `me` → one
///   company. After `signIn`, `AppState` holds exactly one membership and moves
///   to `.selectingCompany`; the `CompanyPickerViewModel` then auto-selects on
///   appear WITHOUT the list being shown (`shouldShowPicker == false`), landing
///   on `.ready` with the single company active.
///
/// - MULTI membership (Requirement 4.2): `me` → two companies. After `signIn`
///   the flow routes to company selection, the picker IS shown
///   (`shouldShowPicker == true`), and `onAppear` does NOT auto-select — the
///   phase stays `.selectingCompany` and no company is active until a tap.
///
/// - 401 handling (Requirement 3.2): with a token seeded in the store, a 401
///   from an authenticated request routed through `AuthStore` (here
///   `liveSnapshot`) clears the token (`store.read() == nil`) and returns
///   `AppState` to `.signedOut`. The direct `didReceiveUnauthorized()` path is
///   asserted too.
@available(iOS 17.0, *)
final class AuthFlowRoutingTests: XCTestCase {

    // MARK: - Stub transport

    /// A canned reply for one request: a JSON body and an HTTP status.
    private struct StubResponse {
        let status: Int
        let body: Data

        init(status: Int, json: String) {
            self.status = status
            self.body = Data(json.utf8)
        }
    }

    /// An `HTTPTransport` that answers each request from a per-path table.
    ///
    /// Keyed by the request path (ignoring any query string) so a test can set
    /// up `/api/auth/login`, `/api/auth/me`, `/api/companies/1/select`,
    /// `/api/dashboard/live`, etc. independently. An unmapped path fails the
    /// test rather than silently returning garbage. The `@unchecked Sendable`
    /// box is safe here: the dictionary is populated before any request and
    /// only read during `data(for:)`.
    private final class StubTransport: HTTPTransport, @unchecked Sendable {
        private var responses: [String: StubResponse] = [:]
        private(set) var requestedPaths: [String] = []

        func stub(path: String, _ response: StubResponse) {
            responses[path] = response
        }

        func data(for request: URLRequest) async throws -> (Data, URLResponse) {
            let url = request.url!
            // Strip the query so `/api/customers?q=…` maps on its path, and
            // resolve the absolute path against the base URL.
            let path = url.path
            requestedPaths.append(path)

            guard let stub = responses[path] else {
                throw APIError.serverMessage("No stub registered for path \(path)")
            }

            let http = HTTPURLResponse(
                url: url,
                statusCode: stub.status,
                httpVersion: "HTTP/1.1",
                headerFields: ["Content-Type": "application/json"]
            )!
            return (stub.body, http)
        }
    }

    // MARK: - Fixtures

    private let baseURL = URL(string: "https://api.example.test")!

    private func makeDeviceIdentity() -> DeviceIdentifying {
        ClosureDeviceIdentity(provider: { "test-device-id" })
    }

    /// Build an `AuthStore` wired to the stub transport, the in-memory token
    /// store, and the stub device identity, over a fresh `AppState`.
    @MainActor
    private func makeAuthStore(
        transport: StubTransport,
        tokenStore: TokenStore
    ) throws -> AuthStore {
        try AuthStore(
            appState: AppState(),
            baseURL: baseURL,
            transport: transport,
            tokenStore: tokenStore,
            deviceIdentity: makeDeviceIdentity()
        )
    }

    private func loginTokenJSON() -> String {
        """
        { "token": "issued-token-abc", "permitted_actions": ["check_in"] }
        """
    }

    private func meJSON(companies: String) -> String {
        """
        {
            "user": { "id": 1, "name": "Ada Lovelace", "email": "ada@example.test" },
            "permitted_actions": ["check_in"],
            "companies": \(companies)
        }
        """
    }

    // MARK: - Single membership auto-selects (Requirement 4.6)

    @MainActor
    func testSingleMembershipRoutesToSelectionThenAutoSelectsWithoutPicker() async throws {
        let transport = StubTransport()
        let tokenStore = InMemoryTokenStore()

        transport.stub(path: "/api/auth/login",
                       StubResponse(status: 200, json: loginTokenJSON()))
        transport.stub(path: "/api/auth/me",
                       StubResponse(status: 200, json: meJSON(companies: """
                       [ { "id": 7, "name": "Acme Events", "role": "scanner" } ]
                       """)))
        transport.stub(path: "/api/companies/7/select",
                       StubResponse(status: 200, json: """
                       {
                           "company": { "id": 7, "name": "Acme Events", "role": "scanner" },
                           "permitted_actions": ["check_in"]
                       }
                       """))

        let auth = try makeAuthStore(transport: transport, tokenStore: tokenStore)

        try await auth.signIn(email: "ada@example.test", password: "secret")

        // After sign-in: token stored, one membership, routed to selection.
        XCTAssertEqual(tokenStore.read(), "issued-token-abc")
        XCTAssertTrue(auth.appState.hasToken)
        XCTAssertEqual(auth.appState.memberships.count, 1)
        XCTAssertEqual(auth.appState.phase, .selectingCompany)
        XCTAssertNil(auth.appState.selectedCompany, "not selected until the picker runs")

        // The picker VM for a single membership must NOT show the list and must
        // auto-select on appear (Requirement 4.6).
        let picker = CompanyPickerViewModel(auth: auth)
        XCTAssertFalse(picker.shouldShowPicker, "single membership must skip the picker")

        await picker.onAppear()

        XCTAssertEqual(auth.appState.selectedCompany?.id, 7)
        XCTAssertEqual(auth.appState.selectedCompany?.name, "Acme Events")
        XCTAssertEqual(auth.appState.phase, .ready, "auto-select lands on the main tabs")
        XCTAssertTrue(transport.requestedPaths.contains("/api/companies/7/select"),
                      "auto-select must call the select endpoint without user input")
    }

    // MARK: - Multi membership shows the picker, no auto-select (Requirement 4.2)

    @MainActor
    func testMultiMembershipShowsPickerAndDoesNotAutoSelect() async throws {
        let transport = StubTransport()
        let tokenStore = InMemoryTokenStore()

        transport.stub(path: "/api/auth/login",
                       StubResponse(status: 200, json: loginTokenJSON()))
        transport.stub(path: "/api/auth/me",
                       StubResponse(status: 200, json: meJSON(companies: """
                       [
                           { "id": 7, "name": "Acme Events", "role": "scanner" },
                           { "id": 9, "name": "Globex Live", "role": "owner" }
                       ]
                       """)))

        let auth = try makeAuthStore(transport: transport, tokenStore: tokenStore)

        try await auth.signIn(email: "ada@example.test", password: "secret")

        // Routed to company selection with more than one membership.
        XCTAssertEqual(auth.appState.memberships.count, 2)
        XCTAssertEqual(auth.appState.phase, .selectingCompany)
        XCTAssertNil(auth.appState.selectedCompany)

        let picker = CompanyPickerViewModel(auth: auth)
        XCTAssertTrue(picker.shouldShowPicker, "multi membership must show the picker")

        // onAppear must NOT auto-select for multiple memberships.
        await picker.onAppear()

        XCTAssertNil(auth.appState.selectedCompany, "no company is chosen without a tap")
        XCTAssertEqual(auth.appState.phase, .selectingCompany, "still awaiting a pick")
        XCTAssertFalse(transport.requestedPaths.contains("/api/companies/7/select"))
        XCTAssertFalse(transport.requestedPaths.contains("/api/companies/9/select"))
    }

    // MARK: - 401 clears the token and returns to sign-in (Requirement 3.2)

    @MainActor
    func testUnauthorizedFromAuthenticatedRequestClearsTokenAndSignsOut() async throws {
        let transport = StubTransport()
        let tokenStore = InMemoryTokenStore()

        // Seed a live token and put the app in the ready state, as if already
        // signed in and scoped to a company.
        try tokenStore.save(token: "live-token-xyz")

        let auth = try makeAuthStore(transport: transport, tokenStore: tokenStore)
        auth.appState.didAuthenticate()
        auth.appState.didSelectCompany(
            AppState.Membership(id: 7, name: "Acme Events", role: "scanner"),
            permittedActions: ["check_in"]
        )
        XCTAssertEqual(auth.appState.phase, .ready)

        // The authenticated request (live dashboard) answers 401: the client
        // runs the unauthorized hook before throwing.
        transport.stub(path: "/api/dashboard/live",
                       StubResponse(status: 401, json: """
                       { "message": "Unauthenticated." }
                       """))

        do {
            _ = try await auth.liveSnapshot()
            XCTFail("a 401 must surface as APIError.unauthorized")
        } catch let error as APIError {
            XCTAssertEqual(error, .unauthorized)
        }

        XCTAssertNil(tokenStore.read(), "the 401 must clear the Keychain token")
        XCTAssertFalse(auth.appState.hasToken)
        XCTAssertNil(auth.appState.selectedCompany)
        XCTAssertTrue(auth.appState.memberships.isEmpty)
        XCTAssertEqual(auth.appState.phase, .signedOut, "a 401 returns to sign-in")
    }

    @MainActor
    func testDidReceiveUnauthorizedDirectlyClearsTokenAndSignsOut() async throws {
        let transport = StubTransport()
        let tokenStore = InMemoryTokenStore()
        try tokenStore.save(token: "live-token-xyz")

        let auth = try makeAuthStore(transport: transport, tokenStore: tokenStore)
        auth.appState.didAuthenticate()
        XCTAssertTrue(auth.appState.hasToken)

        await auth.didReceiveUnauthorized()

        XCTAssertNil(tokenStore.read(), "the token must be cleared")
        XCTAssertFalse(auth.appState.hasToken)
        XCTAssertEqual(auth.appState.phase, .signedOut)
    }
}
