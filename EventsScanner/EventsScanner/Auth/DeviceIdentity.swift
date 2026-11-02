import Foundation
import Security

/// Abstraction over the stable per-device identifier the client sends as the
/// `X-Device-Id` header on every request (Requirement 13.4).
///
/// Device-trust (Requirements 2.1, 2.4) and token binding rely on this value
/// being the *same* across launches and even across app reinstalls, so a
/// trusted device stays trusted and a token keeps matching its issuing device.
/// The networking layer (task 15.2) and tests (task 16.2) depend on this
/// protocol rather than the concrete type.
protocol DeviceIdentifying {
    /// Return the stable device id, generating and persisting it on first use.
    func current() -> String
}

/// Keychain-backed `DeviceIdentifying` that produces a UUID once and returns the
/// same value for the lifetime of the installation **and** across reinstalls.
///
/// ## Why the Keychain and not `identifierForVendor`
///
/// `UIDevice.identifierForVendor` resets whenever all of a vendor's apps are
/// uninstalled, and `UserDefaults` is wiped on uninstall — neither survives a
/// reinstall. A `kSecClassGenericPassword` Keychain item, by contrast, persists
/// across app reinstalls on iOS (it is tied to the device/keychain, not the app
/// container). Storing the generated UUID in the Keychain therefore gives a
/// device id that is stable across launches *and* reinstalls, which is what the
/// API binds tokens and device-trust to (Requirements 2.1, 2.4, 13.4).
///
/// The id is generated lazily on the first call to `current()`: if an id is
/// already stored it is returned as-is; otherwise a fresh `UUID` is generated,
/// written to the Keychain, and returned. The item uses
/// `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly` so it never syncs
/// off-device — the device id is meaningless on any other device.
final class DeviceIdentity: DeviceIdentifying {

    /// Backing store for the id. A `TokenStore` is reused here because the
    /// storage shape (one string under one account) is identical; a distinct
    /// account key keeps the device id separate from the Access_Token.
    private let store: TokenStore

    /// Serialises `current()` so two concurrent first-callers cannot each
    /// generate and persist a different UUID.
    private let lock = NSLock()

    /// - Parameter store: Secure store for the id. Defaults to a `KeychainStore`
    ///   scoped to a dedicated `device-id` account so it never collides with the
    ///   Access_Token item. Tests inject an in-memory double.
    init(
        store: TokenStore = KeychainStore(
            service: Bundle.main.bundleIdentifier ?? "com.events.scanner.EventsScanner",
            account: "device-id"
        )
    ) {
        self.store = store
    }

    func current() -> String {
        lock.lock()
        defer { lock.unlock() }

        if let existing = store.read(), !existing.isEmpty {
            return existing
        }

        let generated = UUID().uuidString
        // If persistence fails we still return a usable id for this launch;
        // the next launch will try to persist again. We never want to block a
        // scan on a Keychain hiccup.
        try? store.save(token: generated)
        return generated
    }
}
