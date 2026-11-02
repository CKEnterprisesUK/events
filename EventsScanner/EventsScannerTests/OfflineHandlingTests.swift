import XCTest
@testable import EventsScanner

/// Tests for offline handling: a network failure must map to `APIError.offline`
/// and the scan screen must NEVER report a successful check-in when a submit
/// fails offline (Requirements 9.1, 9.2).
///
/// Two layers are exercised, each with a focused double:
///
///  - The transport layer (Requirement 9.1): a `HTTPTransport` that throws a
///    `URLError` (as a real `URLSession` does when there is no network path) is
///    injected into `AuthStore`'s `APIClient`. A request routed through it
///    (`submitScan`) must surface `APIError.offline` — the client's uniform
///    mapping of any transport failure. A second case
///    (`.notConnectedToInternet`) proves the mapping is on the error *type*,
///    not a single code.
///
///  - The scan view-model layer (Requirement 9.2, the key assertion): a stub
///    `ScanSubmitting` that throws `APIError.offline` drives both the scanned
///    path (`handleDecoded`) and the manual-entry fallback (`submitManual`).
///    After each, the view model must hold NO outcome (nothing rendered as a
///    `checked_in`/success) and surface `APIError.offline.userMessage` instead.
///    This is the guarantee that an offline failure is never shown as a
///    successful check-in.
///
/// It also pins the shared presentation mapping
/// (`UserFacingError.presentation(for: .offline) == .banner(...)`) so the
/// offline state is surfaced as a non-blocking banner, consistent with
/// `OfflineBanner`.
///
/// On queueing: there is no scan queue in the app by design (Requirement 9.2 —
/// scans are sent in real time, never buffered for later). There is therefore
/// no queue to assert against; instead the view-model tests assert that exactly
/// one submission was attempted and that nothing was retained as a pending or
/// successful result after the offline failure.
@available(iOS 17.0, *)
final class OfflineHandlingTests: XCTestCase {

    // MARK: - Transport doubles

    /// An `HTTPTransport` that always throws the given `URLError`, mimicking a
    /// real `URLSession` when the device has no network path. Records how many
    /// times it was asked to perform a request so a test can assert exactly one
    /// attempt was made (and nothing was retried/queued).
    private final class OfflineTransport: HTTPTransport, @unchecked Sendable {
        let error: URLError
        private(set) var requestCount = 0

        init(_ code: URLError.Code) {
            self.error = URLError(code)
        }

        func data(for request: URLRequest) async throws -> (Data, URLResponse) {
            requestCount += 1
            throw error
        }
    }

    // MARK: - Scan submitter double

    /// A `ScanSubmitting` that always throws `APIError.offline`, counting calls
    /// so a test can assert a single submission was attempted. Used to drive
    /// `ScanViewModel` without a transport, isolating the "never report success
    /// when offline" guarantee (Requirement 9.2).
    @MainActor
    private final class OfflineScanSubmitter: ScanSubmitting {
        private(set) var scanCalls = 0
        private(set) var manualCalls = 0

        func submitScan(payload: String) async throws -> ScanOutcomeDTO {
            scanCalls += 1
            throw APIError.offline
        }

        func submitManual(reference: String) async throws -> ScanOutcomeDTO {
            manualCalls += 1
            throw APIError.offline
        }
    }

    /// A `CameraAuthorizing` fixed to `.authorized` so `ScanViewModel` treats
    /// the camera as live and `handleDecoded` is allowed to submit.
    private struct GrantedAuthorizer: CameraAuthorizing {
        func current() -> CameraPermission { .authorized }
        func request() async -> CameraPermission { .authorized }
    }

    // MARK: - Fixtures

    private let baseURL = URL(string: "https://api.example.test")!

    @MainActor
    private func makeAuthStore(transport: OfflineTransport) throws -> AuthStore {
        try AuthStore(
            appState: AppState(),
            baseURL: baseURL,
            transport: transport,
            tokenStore: InMemoryTokenStore(),
            deviceIdentity: ClosureDeviceIdentity(provider: { "test-device-id" })
        )
    }

    // MARK: - Requirement 9.1: a transport failure maps to .offline

    @MainActor
    func testTransportFailureMapsToOfflineOnSubmitScan() async throws {
        let transport = OfflineTransport(.notConnectedToInternet)
        let auth = try makeAuthStore(transport: transport)

        do {
            _ = try await auth.submitScan(payload: "QR-PAYLOAD")
            XCTFail("a transport failure must surface as APIError.offline")
        } catch let error as APIError {
            XCTAssertEqual(error, .offline, "URLError must map to APIError.offline")
        }

        // Exactly one round trip was attempted — the client does not retry or
        // queue the scan (Requirement 9.2).
        XCTAssertEqual(transport.requestCount, 1, "the scan is sent once, not queued or retried")
    }

    @MainActor
    func testTransportTimeoutAlsoMapsToOffline() async throws {
        // The mapping is on the URLError type, not one specific code: a timeout
        // (another "could not reach the API" transport failure) maps too.
        let transport = OfflineTransport(.timedOut)
        let auth = try makeAuthStore(transport: transport)

        do {
            _ = try await auth.submitManual(reference: "ORD-ABC123")
            XCTFail("a transport failure must surface as APIError.offline")
        } catch let error as APIError {
            XCTAssertEqual(error, .offline)
        }

        XCTAssertEqual(transport.requestCount, 1)
    }

    // MARK: - Requirement 9.2: the scan screen reports NO success when offline

    @MainActor
    func testScannedSubmitOfflineReportsNoSuccess() async throws {
        let submitter = OfflineScanSubmitter()
        let model = ScanViewModel(submitter: submitter, authorizer: GrantedAuthorizer())
        await model.onAppear()

        await model.handleDecoded(payload: "QR-PAYLOAD")

        // No outcome is retained — nothing is rendered as checked_in/success.
        XCTAssertNil(model.outcome, "an offline failure must never produce a success outcome")
        // The offline message is surfaced instead, verbatim from APIError.
        XCTAssertEqual(model.errorMessage, APIError.offline.userMessage)
        // The submit flag is reset; nothing is left in flight / pending.
        XCTAssertFalse(model.isSubmitting)
        // Exactly one submission was attempted (one scan, one send — never queued).
        XCTAssertEqual(submitter.scanCalls, 1)
        XCTAssertEqual(submitter.manualCalls, 0)
    }

    @MainActor
    func testManualSubmitOfflineReportsNoSuccess() async throws {
        let submitter = OfflineScanSubmitter()
        let model = ScanViewModel(submitter: submitter, authorizer: GrantedAuthorizer())
        await model.onAppear()

        await model.submitManual(reference: "ORD-ABC123")

        XCTAssertNil(model.outcome, "an offline manual submit must never produce a success outcome")
        XCTAssertEqual(model.errorMessage, APIError.offline.userMessage)
        XCTAssertFalse(model.isSubmitting)
        XCTAssertEqual(submitter.manualCalls, 1)
        XCTAssertEqual(submitter.scanCalls, 0)
    }

    // MARK: - Shared presentation: offline → banner

    func testOfflinePresentsAsBanner() {
        XCTAssertEqual(
            UserFacingError.presentation(for: .offline),
            .banner(APIError.offline.userMessage),
            "offline must present as a non-blocking banner, never implying success"
        )
    }
}
