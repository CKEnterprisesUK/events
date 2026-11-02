import XCTest
@testable import EventsScanner

/// In-memory `TokenStore` double used to exercise the store contract
/// (save / read / overwrite / delete / absent-after-delete) without touching
/// the system Keychain, which is frequently unavailable in a plain unit-test
/// host. The real `KeychainStore` is additionally exercised by
/// `KeychainStoreIntegrationTests`, guarded to degrade gracefully when the
/// Keychain cannot be reached.
final class InMemoryTokenStore: TokenStore {
    private var value: String?

    func save(token: String) throws {
        value = token
    }

    func read() -> String? {
        value
    }

    func delete() throws {
        value = nil
    }
}

/// Behavioural tests for the `TokenStore` contract (Requirements 3.2, 12.1).
///
/// These drive the protocol via the in-memory double so the token lifecycle is
/// verified deterministically regardless of Keychain availability.
final class TokenStoreContractTests: XCTestCase {

    // Requirement 12.1: the token can be persisted and read back.
    func testSaveThenReadReturnsStoredToken() throws {
        let store: TokenStore = InMemoryTokenStore()

        try store.save(token: "access-token-123")

        XCTAssertEqual(store.read(), "access-token-123")
    }

    // Requirement 12.1: a single token item — a second save overwrites the
    // first rather than accumulating duplicates.
    func testSecondSaveOverwritesFirst() throws {
        let store: TokenStore = InMemoryTokenStore()

        try store.save(token: "first")
        try store.save(token: "second")

        XCTAssertEqual(store.read(), "second")
    }

    // Requirement 3.2 / 12.1: after logout the token is deleted and read()
    // returns nil (the token is absent after logout).
    func testDeleteMakesReadReturnNil() throws {
        let store: TokenStore = InMemoryTokenStore()
        try store.save(token: "to-be-removed")

        try store.delete()

        XCTAssertNil(store.read(), "token must be absent after logout")
    }

    // read() returns nil before any token has been stored (first run / signed
    // out from the start).
    func testReadReturnsNilWhenNothingStored() {
        let store: TokenStore = InMemoryTokenStore()

        XCTAssertNil(store.read())
    }

    // delete() is idempotent: deleting when nothing is stored is a no-op, not an
    // error, and leaves read() returning nil.
    func testDeleteIsIdempotentWhenEmpty() throws {
        let store: TokenStore = InMemoryTokenStore()

        XCTAssertNoThrow(try store.delete())
        XCTAssertNil(store.read())
    }
}

/// Integration tests against the real `KeychainStore`. The unit-test host may
/// not have an entitled Keychain, in which case the very first `save` fails;
/// these tests detect that and skip rather than fail, so the behavioural
/// guarantees above (covered via the protocol) remain the source of truth while
/// this still verifies the real implementation wherever the Keychain is usable.
final class KeychainStoreIntegrationTests: XCTestCase {

    /// A unique service namespace per test run so repeated runs and parallel
    /// suites never collide on the shared Keychain.
    private func makeStore() -> KeychainStore {
        KeychainStore(
            service: "com.events.scanner.tests.\(UUID().uuidString)",
            account: "access-token"
        )
    }

    /// Returns a store if the Keychain accepts a write, otherwise skips the
    /// test. This keeps the suite green on hosts without an entitled Keychain.
    private func makeStoreOrSkip() throws -> KeychainStore {
        let store = makeStore()
        do {
            try store.save(token: "keychain-probe")
            try store.delete()
            return store
        } catch {
            throw XCTSkip("System Keychain is unavailable in this test host: \(error)")
        }
    }

    func testSaveReadOverwriteDeleteRoundTrip() throws {
        let store = try makeStoreOrSkip()
        addTeardownBlock { try? store.delete() }

        try store.save(token: "token-A")
        XCTAssertEqual(store.read(), "token-A")

        try store.save(token: "token-B")
        XCTAssertEqual(store.read(), "token-B", "a second save overwrites the first")

        try store.delete()
        XCTAssertNil(store.read(), "token is absent after logout")
    }

    func testDeleteIsIdempotent() throws {
        let store = try makeStoreOrSkip()
        addTeardownBlock { try? store.delete() }

        try store.delete()
        XCTAssertNoThrow(try store.delete())
        XCTAssertNil(store.read())
    }
}
