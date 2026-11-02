import Foundation
import Security

/// Abstraction over secure persistence of the single Access_Token the client
/// holds (Requirement 12.1).
///
/// `APIClient` (task 15.2) and the sign-in flow (task 17.1) depend on this
/// protocol rather than a concrete Keychain type, so the networking layer can
/// be driven with an in-memory double in tests (task 16.2) without touching the
/// real system Keychain.
///
/// Only the token is ever stored. The user's password is used transiently to
/// authenticate and is never persisted (Requirement 12.4).
protocol TokenStore {
    /// Persist (or overwrite) the current Access_Token.
    func save(token: String) throws

    /// Return the stored Access_Token, or `nil` when none is present
    /// (e.g. before first sign-in or after logout).
    func read() -> String?

    /// Remove the stored Access_Token. Idempotent — deleting when nothing is
    /// stored is a no-op, not an error.
    func delete() throws
}

/// Errors surfaced by `KeychainStore` when a Keychain operation fails for a
/// reason other than "item not found".
enum KeychainError: Error, Equatable {
    /// A `SecItem*` call returned an unexpected `OSStatus`.
    case unexpectedStatus(OSStatus)
    /// A stored value could not be decoded back into a UTF-8 string.
    case dataDecodingFailed
}

/// Keychain-backed `TokenStore` that persists exactly one value: the
/// Access_Token (Requirement 12.1). The password is never written here
/// (Requirement 12.4) — it only ever passes through the sign-in request.
///
/// ## Item class and accessibility
///
/// The token is stored as a `kSecClassGenericPassword` item. Accessibility is
/// set to `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`:
///
/// - **`ThisDeviceOnly`** keeps the item from ever syncing to iCloud Keychain
///   or migrating to another device. A bearer token is a device-bound secret
///   (the API binds it to this device's `X-Device-Id`, Requirement 13.4), so
///   it must not leave the device.
/// - **`AfterFirstUnlock`** (rather than `WhenUnlocked`) lets the token be read
///   after the first unlock following a reboot, so background refreshes and
///   relaunches work while the device is locked, while still denying access
///   before the user has unlocked the device at least once per boot.
///
/// A single fixed account key is used, so a second `save` overwrites the first
/// rather than accumulating duplicate items.
struct KeychainStore: TokenStore {

    /// Keychain service namespace. Scopes this app's items away from any other
    /// items in the shared Keychain.
    private let service: String

    /// Fixed account key for the one token item. Using a constant account means
    /// there is always at most one token stored.
    private let account: String

    /// Accessibility applied to the token item. Device-only so it never syncs
    /// off-device; readable after first unlock so relaunches work while locked.
    private let accessibility: CFString

    /// - Parameters:
    ///   - service: Keychain service namespace (defaults to the bundle id, or a
    ///     stable fallback when none is available, e.g. in unit tests).
    ///   - account: Fixed account key identifying the single token item.
    init(
        service: String = Bundle.main.bundleIdentifier ?? "com.events.scanner.EventsScanner",
        account: String = "access-token"
    ) {
        self.service = service
        self.account = account
        self.accessibility = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
    }

    func save(token: String) throws {
        let data = Data(token.utf8)

        // Update first; if the item does not exist yet, add it. This avoids a
        // delete+add race and keeps accessibility attributes consistent.
        let query = baseQuery()
        let attributes: [String: Any] = [kSecValueData as String: data]

        let updateStatus = SecItemUpdate(query as CFDictionary, attributes as CFDictionary)

        switch updateStatus {
        case errSecSuccess:
            return
        case errSecItemNotFound:
            var addQuery = query
            addQuery[kSecValueData as String] = data
            addQuery[kSecAttrAccessible as String] = accessibility
            let addStatus = SecItemAdd(addQuery as CFDictionary, nil)
            guard addStatus == errSecSuccess else {
                throw KeychainError.unexpectedStatus(addStatus)
            }
        default:
            throw KeychainError.unexpectedStatus(updateStatus)
        }
    }

    func read() -> String? {
        var query = baseQuery()
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne

        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)

        guard status == errSecSuccess,
              let data = result as? Data,
              let token = String(data: data, encoding: .utf8) else {
            return nil
        }
        return token
    }

    func delete() throws {
        let status = SecItemDelete(baseQuery() as CFDictionary)
        // Treat "nothing to delete" as success so logout is idempotent.
        guard status == errSecSuccess || status == errSecItemNotFound else {
            throw KeychainError.unexpectedStatus(status)
        }
    }

    /// The attributes that uniquely identify this app's single token item.
    private func baseQuery() -> [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
    }
}
