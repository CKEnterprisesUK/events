import XCTest
@testable import EventsScanner

/// Tests for `DeviceIdentity` (Requirements 2.1, 2.4).
///
/// `DeviceIdentity` is backed by a `TokenStore`, so these inject the in-memory
/// double (`InMemoryTokenStore`, defined in `KeychainStoreTests.swift`) to drive
/// the stability guarantees deterministically without the system Keychain:
///
/// - the id is stable across repeated calls on one instance (stable across
///   launches),
/// - the id persists for a new `DeviceIdentity` built over the *same* backing
///   store (the Keychain-survives-reinstall property, modelled by the shared
///   store),
/// - a `DeviceIdentity` over a *fresh* empty store generates a new id (first
///   run),
/// - the id is a non-empty, valid UUID string.
final class DeviceIdentityTests: XCTestCase {

    // Requirement 2.1 / 2.4: current() returns the same value across repeated
    // calls on a single instance (stable within and across launches).
    func testCurrentIsStableAcrossRepeatedCalls() {
        let identity = DeviceIdentity(store: InMemoryTokenStore())

        let first = identity.current()
        let second = identity.current()
        let third = identity.current()

        XCTAssertEqual(first, second)
        XCTAssertEqual(second, third)
    }

    // Requirement 2.1 / 2.4: reinstall simulation. A new DeviceIdentity built
    // over the SAME backing store (the Keychain survives reinstall) returns the
    // id that was already persisted — a fresh app process keeps the same device
    // id, so a trusted device stays trusted and a token keeps matching.
    func testIdPersistsAcrossNewInstanceOverSameStore() {
        let store = InMemoryTokenStore()

        let original = DeviceIdentity(store: store).current()

        // Simulate a reinstall: a brand-new DeviceIdentity (new app launch)
        // over the same underlying Keychain-backed store.
        let afterReinstall = DeviceIdentity(store: store).current()

        XCTAssertEqual(afterReinstall, original, "device id must survive a reinstall")
    }

    // A DeviceIdentity over a fresh, empty store generates a new id on first
    // run — and a different installation (a different store) gets a different
    // id.
    func testFreshStoreGeneratesNewId() {
        let firstInstall = DeviceIdentity(store: InMemoryTokenStore()).current()
        let secondInstall = DeviceIdentity(store: InMemoryTokenStore()).current()

        XCTAssertNotEqual(
            firstInstall,
            secondInstall,
            "independent installations (distinct stores) generate distinct ids"
        )
    }

    // The generated id is a non-empty, valid UUID string.
    func testCurrentReturnsNonEmptyUUID() {
        let id = DeviceIdentity(store: InMemoryTokenStore()).current()

        XCTAssertFalse(id.isEmpty)
        XCTAssertNotNil(UUID(uuidString: id), "device id must be a valid UUID string")
    }

    // When the store already holds an id, current() returns it verbatim rather
    // than generating a new one (the persisted value wins).
    func testReturnsPersistedIdWithoutRegenerating() throws {
        let store = InMemoryTokenStore()
        let seeded = UUID().uuidString
        try store.save(token: seeded)

        let id = DeviceIdentity(store: store).current()

        XCTAssertEqual(id, seeded)
    }
}
