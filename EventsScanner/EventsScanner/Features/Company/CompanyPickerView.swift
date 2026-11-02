import SwiftUI

/// Drives company selection off the memberships `AppState` learned from
/// `GET /api/auth/me`.
///
/// - A single membership auto-selects on appear and skips the picker entirely
///   (`POST /api/companies/{id}/select`, then phase → `.ready`) — Requirement
///   4.6.
/// - More than one membership presents the list and selects on tap
///   (Requirement 4.2).
///
/// Selection goes through `AuthStore.select(company:)`, which calls the API and
/// advances `AppState` to the ready/tab phase on success.
@available(iOS 17.0, *)
@MainActor
final class CompanyPickerViewModel: ObservableObject {
    @Published private(set) var isSelecting: Bool = false
    @Published var errorMessage: String?

    private let auth: AuthStore
    private let appState: AppState

    init(auth: AuthStore) {
        self.auth = auth
        self.appState = auth.appState
    }

    /// The memberships to choose from (the source of truth is `AppState`).
    var memberships: [AppState.Membership] { appState.memberships }

    /// Whether the picker list should be shown. A single membership auto-selects
    /// and never shows the list (Requirement 4.6); zero memberships also shows
    /// nothing (an empty state is rendered while that edge is resolved).
    var shouldShowPicker: Bool { memberships.count > 1 }

    /// On appear: when there is exactly one membership, select it immediately
    /// and skip the picker (Requirement 4.6).
    func onAppear() async {
        guard memberships.count == 1, let only = memberships.first else { return }
        await select(only)
    }

    /// Select a membership (tap in the multi-membership list, or the auto-select
    /// of a single membership).
    func select(_ company: AppState.Membership) async {
        guard !isSelecting else { return }
        isSelecting = true
        errorMessage = nil

        do {
            try await auth.select(company: company)
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = APIError.invalidResponse.userMessage
        }

        isSelecting = false
    }
}

/// The company picker. For a single membership it shows a brief loading state
/// while `onAppear` auto-selects; for several it lists them for the user to tap.
@available(iOS 17.0, *)
struct CompanyPickerView: View {
    @StateObject private var model: CompanyPickerViewModel

    init(auth: AuthStore) {
        _model = StateObject(wrappedValue: CompanyPickerViewModel(auth: auth))
    }

    var body: some View {
        NavigationStack {
            Group {
                if model.shouldShowPicker {
                    List(model.memberships) { membership in
                        Button {
                            Task { await model.select(membership) }
                        } label: {
                            HStack {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(membership.name)
                                        .font(.body)
                                    Text(membership.role.capitalized)
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                                Spacer()
                            }
                            .contentShape(Rectangle())
                        }
                        .disabled(model.isSelecting)
                    }
                } else {
                    // Single (or zero) membership: no list; the auto-select runs
                    // in `onAppear`.
                    VStack(spacing: 12) {
                        ProgressView()
                        Text("Opening your company…")
                            .font(.callout)
                            .foregroundStyle(.secondary)
                    }
                    .padding()
                }
            }
            .overlay(alignment: .bottom) {
                if let errorMessage = model.errorMessage {
                    Text(errorMessage)
                        .font(.callout)
                        .foregroundStyle(.red)
                        .padding()
                        .accessibilityIdentifier("companyPicker.error")
                }
            }
            .navigationTitle("Choose Company")
        }
        .task { await model.onAppear() }
    }
}
