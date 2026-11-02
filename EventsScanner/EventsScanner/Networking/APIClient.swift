import Foundation

/// The transport the client performs requests over. `URLSession` conforms to it
/// (via the extension below), and tests inject a stub so DTO decoding and error
/// mapping can be exercised without a live server.
protocol HTTPTransport {
    func data(for request: URLRequest) async throws -> (Data, URLResponse)
}

extension URLSession: HTTPTransport {}

/// Supplies the current Access_Token (or `nil` when signed out) on every
/// request. Backed by the Keychain in the running app (task 16.1), but modelled
/// as a closure-bearing protocol so this task depends on behaviour, not on the
/// concrete `KeychainStore` type.
protocol TokenProviding {
    /// The bearer token to send, or `nil` when there is none (public routes).
    func currentToken() -> String?
}

// The stable per-install device identifier the API binds a token to
// (Requirement 13.4) is modelled by the `DeviceIdentifying` protocol declared
// in `Auth/DeviceIdentity.swift` (method `current()`). The networking layer
// depends on that single protocol — there is intentionally no second
// `DeviceIdentifying` here, so the two definitions cannot collide.

/// Invoked when the API answers `401` (token missing/revoked/expired, or bound
/// to a different device — Requirements 13.2, 13.4). The app wires this to
/// clear the Keychain token and route back to sign-in (Requirements 3.2, 3.3).
/// Modelled as a protocol so `AppState`/`AuthStore` can satisfy it without the
/// client importing them.
protocol UnauthorizedHandling {
    /// Called once per observed 401, before `APIError.unauthorized` is thrown.
    func didReceiveUnauthorized() async
}

// MARK: - Lightweight closure-backed adapters
//
// So call sites and tests can supply plain closures rather than defining a type.

/// A `TokenProviding` backed by a closure.
struct ClosureTokenProvider: TokenProviding {
    let provider: () -> String?
    func currentToken() -> String? { provider() }
}

/// A `DeviceIdentifying` backed by a closure. Satisfies the `current()`
/// requirement of the single `DeviceIdentifying` protocol in `Auth/`.
struct ClosureDeviceIdentity: DeviceIdentifying {
    let provider: () -> String
    func current() -> String { provider() }
}

/// An `UnauthorizedHandling` backed by an async closure.
struct ClosureUnauthorizedHandler: UnauthorizedHandling {
    let handler: () async -> Void
    func didReceiveUnauthorized() async { await handler() }
}

/// The app's one networking entry point.
///
/// Every request it builds:
///   - targets an **HTTPS** base URL (a non-HTTPS base is rejected with
///     `APIError.insecureBaseURL`, never sent — Requirement 12.2);
///   - carries `Authorization: Bearer <token>` when a token is available and
///     `X-Device-Id: <device id>` **always** (the id the API binds the token to
///     for Requirement 13.4), plus `Accept: application/json`;
///   - encodes a JSON body for `POST`s that have one.
///
/// Responses are mapped uniformly:
///   - `2xx` → decode the expected `Codable` DTO (or succeed with no body for a
///     `204`/empty response);
///   - `401` → fire the unauthorized hook (clear token + route to sign-in) and
///     throw `.unauthorized`;
///   - `403` → `.forbidden`;
///   - any other non-2xx → `.serverMessage` parsed from the API error body
///     (`{ "message": "…" }`), or a status-code fallback;
///   - a `URLError` transport failure → `.offline`.
///
/// No HMAC/verification happens here (Requirement 9.3): the client only submits
/// payloads and reads structured outcomes.
final class APIClient {
    private let baseURL: URL
    private let transport: HTTPTransport
    private let tokenProvider: TokenProviding
    private let deviceIdentity: DeviceIdentifying
    private let unauthorizedHandler: UnauthorizedHandling?
    private let decoder: JSONDecoder
    private let encoder: JSONEncoder

    /// Create a client. Throws `APIError.insecureBaseURL` if `baseURL` is not
    /// an `https://` URL, so an insecure configuration fails fast at wiring time
    /// rather than leaking a cleartext request later (Requirement 12.2).
    init(
        baseURL: URL,
        transport: HTTPTransport = URLSession.shared,
        tokenProvider: TokenProviding,
        deviceIdentity: DeviceIdentifying,
        unauthorizedHandler: UnauthorizedHandling? = nil
    ) throws {
        guard baseURL.scheme?.lowercased() == "https" else {
            throw APIError.insecureBaseURL
        }

        self.baseURL = baseURL
        self.transport = transport
        self.tokenProvider = tokenProvider
        self.deviceIdentity = deviceIdentity
        self.unauthorizedHandler = unauthorizedHandler
        self.decoder = JSONDecoder()
        self.encoder = JSONEncoder()
    }

    // MARK: - Requests

    /// Perform an endpoint that returns a decodable body.
    ///
    /// - Parameters:
    ///   - endpoint: the endpoint (method + path).
    ///   - body: an optional `Encodable` request body (JSON-encoded for POSTs).
    /// - Returns: the decoded `Response`.
    /// - Throws: `APIError` on any transport/HTTP/decoding failure.
    func send<Response: Decodable>(
        _ endpoint: Endpoint,
        body: (any Encodable)? = nil
    ) async throws -> Response {
        let (data, http) = try await perform(endpoint, body: body)
        try await throwIfUnsuccessful(http, data: data)

        do {
            return try decoder.decode(Response.self, from: data)
        } catch {
            throw APIError.decoding(String(describing: error))
        }
    }

    /// Perform an endpoint that returns no meaningful body (e.g. a `204` from
    /// `logout` or a session revoke). Still maps non-2xx to `APIError`.
    func send(
        _ endpoint: Endpoint,
        body: (any Encodable)? = nil
    ) async throws {
        let (data, http) = try await perform(endpoint, body: body)
        try await throwIfUnsuccessful(http, data: data)
    }

    // MARK: - Internals

    /// Build and run the request, mapping transport failures to `.offline`.
    private func perform(
        _ endpoint: Endpoint,
        body: (any Encodable)?
    ) async throws -> (Data, HTTPURLResponse) {
        let request = try makeRequest(endpoint, body: body)

        do {
            let (data, response) = try await transport.data(for: request)
            guard let http = response as? HTTPURLResponse else {
                throw APIError.invalidResponse
            }
            return (data, http)
        } catch let error as APIError {
            throw error
        } catch is URLError {
            // Any reachability/transport failure means the device could not
            // complete the round trip; the caller must not report success.
            throw APIError.offline
        }
    }

    /// Assemble the `URLRequest`: resolve the path against the HTTPS base,
    /// attach the auth + device + accept headers, and encode the JSON body.
    private func makeRequest(
        _ endpoint: Endpoint,
        body: (any Encodable)?
    ) throws -> URLRequest {
        // `path` already carries any `?q=…`; resolve it against the base so the
        // host/scheme always come from the (HTTPS-guarded) base URL.
        guard let url = URL(string: endpoint.path, relativeTo: baseURL) else {
            throw APIError.invalidResponse
        }

        var request = URLRequest(url: url)
        request.httpMethod = endpoint.method.rawValue
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        // X-Device-Id on EVERY request — the id the API binds the token to
        // (Requirement 13.4). Authorization only when a token exists (public
        // login/two-factor routes have none yet).
        request.setValue(deviceIdentity.current(), forHTTPHeaderField: "X-Device-Id")
        if let token = tokenProvider.currentToken() {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            do {
                request.httpBody = try encoder.encode(AnyEncodable(body))
            } catch {
                throw APIError.decoding(String(describing: error))
            }
        }

        return request
    }

    /// Map a non-2xx response to the matching `APIError`. On `401` the
    /// unauthorized hook runs first (clear token + route to sign-in) before the
    /// error propagates. A 2xx response returns without throwing.
    private func throwIfUnsuccessful(_ http: HTTPURLResponse, data: Data) async throws {
        switch http.statusCode {
        case 200...299:
            return
        case 401:
            await unauthorizedHandler?.didReceiveUnauthorized()
            throw APIError.unauthorized
        case 403:
            throw APIError.forbidden
        default:
            throw APIError.serverMessage(serverMessage(from: data, statusCode: http.statusCode))
        }
    }

    /// Extract Laravel's `{ "message": "…" }` from an error body, falling back
    /// to a generic status-code message when the body is empty or unparseable.
    private func serverMessage(from data: Data, statusCode: Int) -> String {
        if let envelope = try? decoder.decode(ServerErrorEnvelope.self, from: data),
           let message = envelope.message, !message.isEmpty {
            return message
        }
        return "The request failed (status \(statusCode))."
    }
}

/// The Laravel error envelope: `{ "message": "…", "errors": { … } }`. Only the
/// top-level message is surfaced to the user.
private struct ServerErrorEnvelope: Decodable {
    let message: String?
}

/// Type-erases an `Encodable` so a heterogeneous request body can be encoded
/// without the client being generic over the body type at every call site.
private struct AnyEncodable: Encodable {
    private let encodeTo: (Encoder) throws -> Void

    init(_ wrapped: any Encodable) {
        self.encodeTo = wrapped.encode
    }

    func encode(to encoder: Encoder) throws {
        try encodeTo(encoder)
    }
}
