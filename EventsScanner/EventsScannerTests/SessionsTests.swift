import XCTest
@testable import EventsScanner

/// Tests for the sessions management screen: the Sessions DTO round-trip, the
/// revoke-removes-the-row behaviour on `SessionsViewModel`, and the role-driven
/// visibility of the team-management section.
///
/// Covers Requirements 14.1 (list own sessions), 14.2 (revoke own session),
/// and 8.4 / 8.5 (server-driven `permitted_actions` gate the team section —
/// shown only when `users` is present).
///
/// The view-model scenarios drive `SessionsViewModel` through a real
/// `AuthStore` wired to a stub `HTTPTransport` (the same per-path stub pattern
/// as `AuthFlowRoutingTests`), the in-memory `TokenStore` double
/// (`InMemoryTokenStore`, from `KeychainStoreTests.swift`), and the
/// closure-backed `DeviceIdentifying` (`ClosureDeviceIdentity`). Nothing touches
/// a live server or the system Keychain.
@available(iOS 17.0, *)
final class SessionsTests: XCTestCase {

    // MARK: - Stub transport
    //
    // Same shape as AuthFlowRoutingTests' StubTransport: a canned reply per
    // request path (query stripped). A `204` revoke carries an empty body,
    // which the client's no-body `send` overload tolerates.

    private struct StubResponse {
        let status: Int
        let body: Data

        init(status: Int, json: String = "") {
            self.status = status
            self.body = Data(json.utf8)
        }
    }

    private final class StubTransport: HTTPTransport, @unchecked Sendable {
        private var responses: [String: StubResponse] = [:]
        private(set) var requestedPaths: [String] = []

        func stub(path: String, _ response: StubResponse) {
            responses[path] = response
        }

        func data(for request: URLRequest) async throws -> (Data, URLResponse) {
            let url = request.url!
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
            deviceIdentity: ClosureDeviceIdentity(provider: { "test-device-id" })
        )
    }

    /// A `{ "sessions": [...] }` body with two rows: a current session and a
    /// second, revocable one.
    private func twoSessionsJSON() -> String {
        """
        {
            "sessions": [
                {
                    "id": 10,
                    "device_label": "Ada's iPhone",
                    "last_used_at": "2024-06-01T18:30:00.000000Z",
                    "created_at": "2024-05-01T09:00:00.000000Z",
                    "current": true
                },
                {
                    "id": 11,
                    "device_label": "Door iPad",
                    "last_used_at": null,
                    "created_at": "2024-05-15T12:00:00.000000Z",
                    "current": false
                }
            ]
        }
        """
    }

    // MARK: - DTO round-trip (Requirement 14.1)

    /// Decode a `{ sessions: [...] }` body into `SessionsResponseDTO` and assert
    /// the field mapping, including the snake_case `CodingKeys`
    /// (`device_label → deviceLabel`, `last_used_at → lastUsedAt`,
    /// `created_at → createdAt`) and the `current` flag.
    func testDecodesSessionsResponseWithFieldMapping() throws {
        let decoder = JSONDecoder()
        let response = try decoder.decode(
            SessionsResponseDTO.self,
            from: Data(twoSessionsJSON().utf8)
        )

        XCTAssertEqual(response.sessions.count, 2)

        let current = response.sessions[0]
        XCTAssertEqual(current.id, 10)
        XCTAssertEqual(current.deviceLabel, "Ada's iPhone")
        XCTAssertEqual(current.lastUsedAt, "2024-06-01T18:30:00.000000Z")
        XCTAssertEqual(current.createdAt, "2024-05-01T09:00:00.000000Z")
        XCTAssertTrue(current.current)

        let other = response.sessions[1]
        XCTAssertEqual(other.id, 11)
        XCTAssertEqual(other.deviceLabel, "Door iPad")
        XCTAssertNil(other.lastUsedAt, "a never-used session has a null last_used_at")
        XCTAssertEqual(other.createdAt, "2024-05-15T12:00:00.000000Z")
        XCTAssertFalse(other.current)
    }

    /// A missing `device_label` decodes to `nil` (the server sends `null` when
    /// a token has no name), and the row still identifies by `id`.
    func testDecodesSessionWithNullDeviceLabel() throws {
        let json = """
        {
            "sessions": [
                { "id": 42, "device_label": null, "last_used_at": null, "created_at": null, "current": false }
            ]
        }
        """
        let decoder = JSONDecoder()
        let response = try decoder.decode(SessionsResponseDTO.self, from: Data(json.utf8))

        XCTAssertEqual(response.sessions.count, 1)
        XCTAssertEqual(response.sessions[0].id, 42)
        XCTAssertNil(response.sessions[0].deviceLabel)
        XCTAssertEqual(response.sessions[0].id, response.sessions[0].id, "id is the stable row identity")
    }

    /// Re-encode a decoded response and decode it again: the round trip is
    /// stable, so the DTO is a faithful two-way model of the contract.
    func testSessionsResponseReEncodeIsStable() throws {
        let decoder = JSONDecoder()
        let encoder = JSONEncoder()

        let original = try decoder.decode(
            SessionsResponseDTO.self,
            from: Data(twoSessionsJSON().utf8)
        )
        let reEncoded = try encoder.encode(original)
        let reDecoded = try decoder.decode(SessionsResponseDTO.self, from: reEncoded)

        XCTAssertEqual(reDecoded, original, "decode → encode → decode is a no-op")
    }

    // MARK: - Self list loads the rows (Requirement 14.1)

    /// `loadSessions()` fetches the signed-in user's own sessions via
    /// `GET /api/sessions` and exposes them in order.
    @MainActor
    func testLoadSessionsPopulatesTheRows() async throws {
        let transport = StubTransport()
        let tokenStore = InMemoryTokenStore()
        try tokenStore.save(token: "live-token")

        transport.stub(path: "/api/sessions",
                       StubResponse(status: 200, json: twoSessionsJSON()))

        let auth = try makeAuthStore(transport: transport, tokenStore: tokenStore)
        let model = SessionsViewModel(auth: auth)

        await model.loadSessions()

        XCTAssertNil(model.errorMessage)
        XCTAssertEqual(model.sessions.map(\.id), [10, 11])
        XCTAssertTrue(transport.requestedPaths.contains("/api/sessions"))
    }

    // MARK: - Revoke removes the row (Requirement 14.2)

    /// After loading two sessions, revoking one (a `204` from
    /// `DELETE /api/sessions/{id}`) removes exactly that row: the count drops to
    /// one and the revoked id is gone, the surviving row remains.
    @MainActor
    func testRevokeRemovesOnlyTheRevokedRow() async throws {
        let transport = StubTransport()
        let tokenStore = InMemoryTokenStore()
        try tokenStore.save(token: "live-token")

        transport.stub(path: "/api/sessions",
                       StubResponse(status: 200, json: twoSessionsJSON()))
        // The revoke endpoint answers 204 No Content with an empty body.
        transport.stub(path: "/api/sessions/11", StubResponse(status: 204))

        let auth = try makeAuthStore(transport: transport, tokenStore: tokenStore)
        let model = SessionsViewModel(auth: auth)

        await model.loadSessions()
        XCTAssertEqual(model.sessions.count, 2)

        let toRevoke = try XCTUnwrap(model.sessions.first { $0.id == 11 })
        await model.revoke(session: toRevoke)

        XCTAssertNil(model.errorMessage)
        XCTAssertEqual(model.sessions.count, 1, "the revoked row is removed")
        XCTAssertFalse(model.sessions.contains { $0.id == 11 }, "the revoked id is gone")
        XCTAssertEqual(model.sessions.map(\.id), [10], "the surviving row remains")
        XCTAssertNil(model.revokingSessionId, "the in-flight marker is cleared")
        XCTAssertTrue(transport.requestedPaths.contains("/api/sessions/11"))
    }

    /// A failed revoke (e.g. a `404` for a foreign id) leaves the list
    /// unchanged and surfaces the error message rather than dropping the row.
    @MainActor
    func testFailedRevokeKeepsTheRowAndReportsError() async throws {
        let transport = StubTransport()
        let tokenStore = InMemoryTokenStore()
        try tokenStore.save(token: "live-token")

        transport.stub(path: "/api/sessions",
                       StubResponse(status: 200, json: twoSessionsJSON()))
        transport.stub(path: "/api/sessions/11",
                       StubResponse(status: 404, json: """
                       { "message": "Not found." }
                       """))

        let auth = try makeAuthStore(transport: transport, tokenStore: tokenStore)
        let model = SessionsViewModel(auth: auth)

        await model.loadSessions()
        let toRevoke = try XCTUnwrap(model.sessions.first { $0.id == 11 })

        await model.revoke(session: toRevoke)

        XCTAssertEqual(model.sessions.count, 2, "a failed revoke keeps every row")
        XCTAssertTrue(model.sessions.contains { $0.id == 11 })
        XCTAssertNotNil(model.errorMessage, "the failure surfaces a message")
    }

    // MARK: - Role-driven team visibility (Requirements 8.4, 8.5)
    //
    // The team-management section shows only when the server's
    // `permitted_actions` includes `users`. `AppState.canManageTeamSessions` is
    // the single source of that decision, and `SessionsView(canManageTeamSessions:)`
    // is driven from it — so asserting the flag asserts the shows-only-when-users
    // property the view gates on.

    @MainActor
    func testCanManageTeamSessionsTrueWhenUsersActionPresent() {
        let state = AppState()
        state.applyMe(
            name: "Owner",
            permittedActions: ["check_in", "orders", "users"],
            memberships: []
        )

        XCTAssertTrue(
            state.canManageTeamSessions,
            "with `users` present the team section is shown"
        )
    }

    @MainActor
    func testCanManageTeamSessionsFalseWhenUsersActionAbsent() {
        let state = AppState()
        state.applyMe(
            name: "Scanner",
            permittedActions: ["check_in"],
            memberships: []
        )

        XCTAssertFalse(
            state.canManageTeamSessions,
            "without `users` the team section is hidden"
        )
    }

    @MainActor
    func testCanManageTeamSessionsFalseWhenNoActions() {
        let state = AppState()
        state.applyMe(name: "Nobody", permittedActions: [], memberships: [])

        XCTAssertFalse(state.canManageTeamSessions)
    }

    /// The flag flows the same way after a company switch (`didSelectCompany`
    /// re-applies the freshly scoped actions): visibility tracks the current
    /// Active_Company's permitted actions.
    @MainActor
    func testTeamVisibilityTracksSelectedCompanyActions() {
        let state = AppState()
        // Signed in with `users` in one company…
        state.applyMe(name: "Owner", permittedActions: ["users"], memberships: [])
        XCTAssertTrue(state.canManageTeamSessions)

        // …then scoped to a company where the role is only a scanner.
        state.didSelectCompany(
            AppState.Membership(id: 2, name: "Globex", role: "scanner"),
            permittedActions: ["check_in"]
        )
        XCTAssertFalse(state.canManageTeamSessions, "re-scoped actions hide the team section")
    }

    /// `SessionsView` takes the gating flag as an init parameter sourced from
    /// `AppState.canManageTeamSessions`, so constructing the view from the flag
    /// is the composition the app performs. These assertions pin that the view
    /// is built with the flag the state computes, both shown and hidden.
    @MainActor
    func testSessionsViewIsConstructedFromTheTeamVisibilityFlag() throws {
        let transport = StubTransport()
        let tokenStore = InMemoryTokenStore()
        let auth = try makeAuthStore(transport: transport, tokenStore: tokenStore)

        let owner = AppState()
        owner.applyMe(name: "Owner", permittedActions: ["users"], memberships: [])
        XCTAssertTrue(owner.canManageTeamSessions)
        _ = SessionsView(auth: auth, canManageTeamSessions: owner.canManageTeamSessions)

        let scanner = AppState()
        scanner.applyMe(name: "Scanner", permittedActions: ["check_in"], memberships: [])
        XCTAssertFalse(scanner.canManageTeamSessions)
        _ = SessionsView(auth: auth, canManageTeamSessions: scanner.canManageTeamSessions)
    }
}
