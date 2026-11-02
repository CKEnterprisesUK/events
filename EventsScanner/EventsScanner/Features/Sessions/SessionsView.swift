import SwiftUI

/// Drives the sessions management screen.
///
/// The screen has two parts:
///
///  - **My sessions** (always available, Requirements 14.1, 14.2): the
///    signed-in user's own active Sessions from `GET /api/sessions`, each
///    showing its device label, last-used instant, and a "This device" marker
///    for the `current` flag. Any row can be revoked via
///    `DELETE /api/sessions/{id}`; on success the row is removed from the list.
///
///  - **Team sessions** (Owner/Admin only, Requirements 14.3, 8.4, 8.5): shown
///    only when the Active_Company role grants the `users` action
///    (`AppState.canManageTeamSessions`, driven by the server's
///    `permitted_actions` exactly like customer-search visibility). It lists a
///    chosen member's Sessions via `GET /api/users/{user}/sessions` and revokes
///    via `DELETE /api/users/{user}/sessions/{id}`.
///
///    Member selection is intentionally minimal here: there is no members-list
///    endpoint in scope, so the operator enters the target user id directly and
///    loads that member's sessions. When such an endpoint lands, this field can
///    be replaced with a picker without touching the revoke flow.
///
/// Errors from any call surface `APIError.userMessage`. A 401 is handled by the
/// shared `APIClient` (clears the token, returns to sign-in); a 403 on the team
/// routes surfaces the "not permitted" message.
///
/// Requires iOS 17 because it reads the `@Observable` `AppState` and drives the
/// `@Observable`-era `AuthStore` — consistent with the rest of the app.
@available(iOS 17.0, *)
@MainActor
final class SessionsViewModel: ObservableObject {

    // MARK: - My sessions

    @Published private(set) var sessions: [SessionDTO] = []
    @Published private(set) var isLoading: Bool = false
    @Published var errorMessage: String?

    /// The id currently being revoked, so the affected row can show progress
    /// and ignore repeat taps.
    @Published private(set) var revokingSessionId: Int?

    // MARK: - Team sessions

    /// The user id the operator has typed for the team lookup. Kept as a string
    /// so the text field can bind directly; parsed on demand.
    @Published var teamUserIdInput: String = ""

    @Published private(set) var teamSessions: [SessionDTO] = []
    @Published private(set) var teamLoadedUserId: Int?
    @Published private(set) var isLoadingTeam: Bool = false
    @Published var teamErrorMessage: String?
    @Published private(set) var revokingTeamSessionId: Int?

    private let auth: AuthStore

    init(auth: AuthStore) {
        self.auth = auth
    }

    // MARK: - My sessions actions

    /// Load the signed-in user's own sessions. Safe to call on appear and to
    /// pull-to-refresh.
    func loadSessions() async {
        isLoading = true
        errorMessage = nil
        do {
            sessions = try await auth.listSessions()
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = APIError.invalidResponse.userMessage
        }
        isLoading = false
    }

    /// Revoke one of the signed-in user's own sessions and remove the row on
    /// success (Requirement 14.2).
    func revoke(session: SessionDTO) async {
        guard revokingSessionId == nil else { return }
        revokingSessionId = session.id
        errorMessage = nil
        do {
            try await auth.revokeSession(id: session.id)
            sessions.removeAll { $0.id == session.id }
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = APIError.invalidResponse.userMessage
        }
        revokingSessionId = nil
    }

    // MARK: - Team sessions actions

    /// Whether the typed team user id parses to a positive integer.
    var canLoadTeam: Bool {
        parsedTeamUserId != nil && !isLoadingTeam
    }

    /// The parsed team target user id, or `nil` when the field is empty/invalid.
    var parsedTeamUserId: Int? {
        let trimmed = teamUserIdInput.trimmingCharacters(in: .whitespaces)
        guard let value = Int(trimmed), value > 0 else { return nil }
        return value
    }

    /// Load the chosen member's sessions via the team route (Requirement 14.3).
    func loadTeamSessions() async {
        guard let userId = parsedTeamUserId else { return }
        isLoadingTeam = true
        teamErrorMessage = nil
        do {
            teamSessions = try await auth.listUserSessions(userId: userId)
            teamLoadedUserId = userId
        } catch let error as APIError {
            teamErrorMessage = error.userMessage
            teamSessions = []
            teamLoadedUserId = nil
        } catch {
            teamErrorMessage = APIError.invalidResponse.userMessage
            teamSessions = []
            teamLoadedUserId = nil
        }
        isLoadingTeam = false
    }

    /// Revoke one of the loaded member's sessions and remove the row on success
    /// (Requirement 14.3).
    func revokeTeam(session: SessionDTO) async {
        guard let userId = teamLoadedUserId, revokingTeamSessionId == nil else { return }
        revokingTeamSessionId = session.id
        teamErrorMessage = nil
        do {
            try await auth.revokeUserSession(userId: userId, sessionId: session.id)
            teamSessions.removeAll { $0.id == session.id }
        } catch let error as APIError {
            teamErrorMessage = error.userMessage
        } catch {
            teamErrorMessage = APIError.invalidResponse.userMessage
        }
        revokingTeamSessionId = nil
    }
}

/// The sessions management screen.
///
/// Slots into the main `TabView` built by task 20.1 as the "Sessions" screen.
/// Self-sessions are always available; the team section is gated internally on
/// `appState.canManageTeamSessions` so the containing tab can be shown to
/// everyone while the team controls remain Owner/Admin-only. If task 20.1 wants
/// to gate the *whole* tab instead, it can check the same flag.
@available(iOS 17.0, *)
struct SessionsView: View {
    @StateObject private var model: SessionsViewModel
    private let canManageTeamSessions: Bool

    /// - Parameters:
    ///   - auth: the shared `AuthStore` the view model calls.
    ///   - canManageTeamSessions: the server-driven `users`-ability flag
    ///     (`AppState.canManageTeamSessions`). Passed in rather than read from
    ///     `AppState` directly so the view stays testable and the visibility
    ///     decision has one source.
    init(auth: AuthStore, canManageTeamSessions: Bool) {
        _model = StateObject(wrappedValue: SessionsViewModel(auth: auth))
        self.canManageTeamSessions = canManageTeamSessions
    }

    var body: some View {
        NavigationStack {
            List {
                mySessionsSection

                if canManageTeamSessions {
                    teamSessionsSection
                }
            }
            .navigationTitle("Sessions")
            .refreshable { await model.loadSessions() }
            .task { await model.loadSessions() }
        }
    }

    // MARK: - My sessions

    @ViewBuilder
    private var mySessionsSection: some View {
        Section("My Sessions") {
            if model.isLoading && model.sessions.isEmpty {
                HStack {
                    ProgressView()
                    Text("Loading sessions…")
                        .foregroundStyle(.secondary)
                }
            } else if model.sessions.isEmpty {
                Text("No active sessions.")
                    .foregroundStyle(.secondary)
            } else {
                ForEach(model.sessions) { session in
                    SessionRow(
                        session: session,
                        isRevoking: model.revokingSessionId == session.id
                    )
                    .swipeActions(edge: .trailing) {
                        if !session.current {
                            Button(role: .destructive) {
                                Task { await model.revoke(session: session) }
                            } label: {
                                Label("Revoke", systemImage: "trash")
                            }
                        }
                    }
                }
            }

            if let errorMessage = model.errorMessage {
                Text(errorMessage)
                    .font(.callout)
                    .foregroundStyle(.red)
                    .accessibilityIdentifier("sessions.error")
            }
        }
    }

    // MARK: - Team sessions

    @ViewBuilder
    private var teamSessionsSection: some View {
        Section("Team Member Sessions") {
            VStack(alignment: .leading, spacing: 8) {
                Text("Enter a team member's user id to view and revoke their sessions.")
                    .font(.footnote)
                    .foregroundStyle(.secondary)

                HStack {
                    TextField("User id", text: $model.teamUserIdInput)
                        .keyboardType(.numberPad)
                        .textFieldStyle(.roundedBorder)
                        .accessibilityIdentifier("sessions.team.userId")

                    Button("Load") {
                        Task { await model.loadTeamSessions() }
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(!model.canLoadTeam)
                }
            }

            if model.isLoadingTeam {
                HStack {
                    ProgressView()
                    Text("Loading member sessions…")
                        .foregroundStyle(.secondary)
                }
            } else if let userId = model.teamLoadedUserId {
                if model.teamSessions.isEmpty {
                    Text("No active sessions for user \(userId).")
                        .foregroundStyle(.secondary)
                } else {
                    ForEach(model.teamSessions) { session in
                        SessionRow(
                            session: session,
                            isRevoking: model.revokingTeamSessionId == session.id
                        )
                        .swipeActions(edge: .trailing) {
                            Button(role: .destructive) {
                                Task { await model.revokeTeam(session: session) }
                            } label: {
                                Label("Revoke", systemImage: "trash")
                            }
                        }
                    }
                }
            }

            if let teamErrorMessage = model.teamErrorMessage {
                Text(teamErrorMessage)
                    .font(.callout)
                    .foregroundStyle(.red)
                    .accessibilityIdentifier("sessions.team.error")
            }
        }
    }
}

/// One session row: device label, last-used/created, and a "This device"
/// marker for the current token. Shows a spinner while its revoke is in flight.
@available(iOS 17.0, *)
private struct SessionRow: View {
    let session: SessionDTO
    let isRevoking: Bool

    var body: some View {
        HStack {
            VStack(alignment: .leading, spacing: 4) {
                HStack(spacing: 6) {
                    Text(session.deviceLabel ?? "Unknown device")
                        .font(.body)
                    if session.current {
                        Text("This device")
                            .font(.caption2)
                            .padding(.horizontal, 6)
                            .padding(.vertical, 2)
                            .background(Color.accentColor.opacity(0.15))
                            .clipShape(Capsule())
                            .foregroundStyle(Color.accentColor)
                            .accessibilityIdentifier("sessions.current")
                    }
                }

                Text(lastUsedDescription)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            Spacer()

            if isRevoking {
                ProgressView()
            }
        }
    }

    /// A human-facing "last used" line, falling back to the created instant and
    /// then to a neutral message when neither is known.
    private var lastUsedDescription: String {
        if let formatted = SessionRow.relativeDescription(from: session.lastUsedAt) {
            return "Last used \(formatted)"
        }
        if let formatted = SessionRow.relativeDescription(from: session.createdAt) {
            return "Created \(formatted)"
        }
        return "Not used yet"
    }

    /// Parse an ISO-8601 instant and render it relative to now
    /// (e.g. "2 hours ago"); returns `nil` for a missing/unparseable value.
    /// Tries the fractional-seconds form first (Laravel/Carbon serialises
    /// `…T12:00:00.000000Z`) and falls back to the plain form.
    private static func relativeDescription(from iso: String?) -> String? {
        guard let iso else { return nil }
        let date = fractionalFormatter.date(from: iso) ?? plainFormatter.date(from: iso)
        guard let date else { return nil }
        return relativeFormatter.localizedString(for: date, relativeTo: Date())
    }

    private static let fractionalFormatter: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return formatter
    }()

    private static let plainFormatter: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime]
        return formatter
    }()

    private static let relativeFormatter: RelativeDateTimeFormatter = {
        let formatter = RelativeDateTimeFormatter()
        formatter.unitsStyle = .full
        return formatter
    }()
}
