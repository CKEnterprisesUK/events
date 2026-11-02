import XCTest
@testable import EventsScanner

/// Decoding tests for the Scan_Outcome contract (`POST /api/scan`).
///
/// Each representative payload mirrors exactly what the Laravel
/// `ScanApiController::serialise()` emits for the matching `ScanResult`:
///
///   - `checked_in`      status + message + reference, customer, scanned_at, breakdown[]
///   - `already_scanned` status + message + reference, customer, scanned_at, scanned_by
///   - `rejected` / `failed` / `invalid` / `unreadable`   status + message only
///
/// The tests assert the raw `status` string maps to the right `ScanStatus`
/// case, that the per-status richer fields are present for the statuses that
/// define them and absent (`nil`) for the ones that don't, and that an unknown
/// status string decodes to the safe `.unreadable` fallback from
/// `ScanStatus`'s custom initializer.
///
/// Covers Requirements 5.3, 5.4, 5.5, 5.6, 5.7, 5.8.
final class ScanOutcomeDTOTests: XCTestCase {

    private let decoder = JSONDecoder()

    private func decode(_ json: String) throws -> ScanOutcomeDTO {
        try decoder.decode(ScanOutcomeDTO.self, from: Data(json.utf8))
    }

    // MARK: - checked_in (Requirement 5.8)

    func testDecodesCheckedInWithBreakdown() throws {
        // Matches serialise(): status, message, reference, customer,
        // scanned_at (ISO-8601) and a breakdown of {name, quantity} rows.
        let json = """
        {
            "status": "checked_in",
            "message": "Checked in.",
            "reference": "ORD-ABC123",
            "customer": "Ada Lovelace",
            "scanned_at": "2024-06-01T18:30:00+00:00",
            "breakdown": [
                { "name": "General", "quantity": 2 },
                { "name": "VIP", "quantity": 1 }
            ]
        }
        """

        let outcome = try decode(json)

        XCTAssertEqual(outcome.status, .checkedIn)
        XCTAssertEqual(outcome.message, "Checked in.")
        XCTAssertEqual(outcome.reference, "ORD-ABC123")
        XCTAssertEqual(outcome.customer, "Ada Lovelace")
        XCTAssertEqual(outcome.scannedAt, "2024-06-01T18:30:00+00:00")
        XCTAssertEqual(outcome.breakdown, [
            ScanBreakdownRow(name: "General", quantity: 2),
            ScanBreakdownRow(name: "VIP", quantity: 1),
        ])
        // scanned_by is an already_scanned-only field; absent here.
        XCTAssertNil(outcome.scannedBy)
    }

    // MARK: - already_scanned (Requirement 5.7)

    func testDecodesAlreadyScannedWithScannedByAndNoBreakdown() throws {
        // Matches serialise(): status, message, reference, customer,
        // scanned_at and scanned_by — but no breakdown.
        let json = """
        {
            "status": "already_scanned",
            "message": "Already scanned.",
            "reference": "ORD-ABC123",
            "customer": "Ada Lovelace",
            "scanned_at": "2024-06-01T18:30:00+00:00",
            "scanned_by": "Grace Hopper"
        }
        """

        let outcome = try decode(json)

        XCTAssertEqual(outcome.status, .alreadyScanned)
        XCTAssertEqual(outcome.message, "Already scanned.")
        XCTAssertEqual(outcome.reference, "ORD-ABC123")
        XCTAssertEqual(outcome.customer, "Ada Lovelace")
        XCTAssertEqual(outcome.scannedAt, "2024-06-01T18:30:00+00:00")
        XCTAssertEqual(outcome.scannedBy, "Grace Hopper")
        // breakdown is a checked_in-only field; absent here.
        XCTAssertNil(outcome.breakdown)
    }

    // MARK: - rejected / failed / invalid / unreadable
    // (Requirements 5.5, 5.6, 5.4, 5.3) — status + message only, richer fields nil.

    func testDecodesRejectedWithOnlyStatusAndMessage() throws {
        let outcome = try decode("""
        { "status": "rejected", "message": "Ticket not found." }
        """)

        XCTAssertEqual(outcome.status, .rejected)
        XCTAssertEqual(outcome.message, "Ticket not found.")
        assertRicherFieldsAbsent(outcome)
    }

    func testDecodesFailedWithOnlyStatusAndMessage() throws {
        let outcome = try decode("""
        { "status": "failed", "message": "Failed. This ticket is no longer valid." }
        """)

        XCTAssertEqual(outcome.status, .failed)
        XCTAssertEqual(outcome.message, "Failed. This ticket is no longer valid.")
        assertRicherFieldsAbsent(outcome)
    }

    func testDecodesInvalidWithOnlyStatusAndMessage() throws {
        let outcome = try decode("""
        { "status": "invalid", "message": "Invalid code. This ticket could not be verified." }
        """)

        XCTAssertEqual(outcome.status, .invalid)
        XCTAssertEqual(outcome.message, "Invalid code. This ticket could not be verified.")
        assertRicherFieldsAbsent(outcome)
    }

    func testDecodesUnreadableWithOnlyStatusAndMessage() throws {
        let outcome = try decode("""
        { "status": "unreadable", "message": "Unreadable code. Please try scanning again." }
        """)

        XCTAssertEqual(outcome.status, .unreadable)
        XCTAssertEqual(outcome.message, "Unreadable code. Please try scanning again.")
        assertRicherFieldsAbsent(outcome)
    }

    // MARK: - Unknown status → safe fallback

    func testUnknownStatusDecodesToUnreadableFallback() throws {
        // ScanStatus's custom init maps any unrecognised string to .unreadable
        // so the door operator never sees a success it didn't earn.
        let outcome = try decode("""
        { "status": "something_new_from_the_server", "message": "?" }
        """)

        XCTAssertEqual(outcome.status, .unreadable)
        assertRicherFieldsAbsent(outcome)
    }

    func testGarbageStatusDecodesToUnreadableFallback() throws {
        let outcome = try decode("""
        { "status": "", "message": "" }
        """)

        XCTAssertEqual(outcome.status, .unreadable)
    }

    // MARK: - Helpers

    /// The richer, status-specific fields must all be absent for the
    /// status + message-only outcomes.
    private func assertRicherFieldsAbsent(
        _ outcome: ScanOutcomeDTO,
        file: StaticString = #filePath,
        line: UInt = #line
    ) {
        XCTAssertNil(outcome.reference, "reference should be nil", file: file, line: line)
        XCTAssertNil(outcome.customer, "customer should be nil", file: file, line: line)
        XCTAssertNil(outcome.scannedAt, "scanned_at should be nil", file: file, line: line)
        XCTAssertNil(outcome.scannedBy, "scanned_by should be nil", file: file, line: line)
        XCTAssertNil(outcome.breakdown, "breakdown should be nil", file: file, line: line)
    }
}
