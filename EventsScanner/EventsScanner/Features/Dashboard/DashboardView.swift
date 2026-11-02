import SwiftUI

/// Drives the live door dashboard: polls `GET /api/dashboard/live` on a fixed
/// interval and publishes the Active_Event's attendance figures and recent
/// scan feed for `DashboardView` to render (Requirements 7.1, 7.2).
///
/// Polling behaviour:
///   - a single long-lived `Task` loops: fetch → publish → sleep → repeat, so
///     there is never more than one request in flight (no overlap);
///   - `start()` is idempotent (a running loop is reused) and `stop()` cancels
///     it, so the view can start on appear and stop on disappear without
///     leaking a timer;
///   - a fetch failure is surfaced via `APIError.userMessage` and the loop
///     keeps polling, so a transient blip recovers on the next tick. A 401 has
///     already been handled by the shared client (Keychain cleared, routed to
///     sign-in — Requirements 3.2, 3.3); a 403 should not normally reach here
///     because the Dashboard tab is gated on `check_in`, but if it does it is
///     shown as the forbidden message rather than crashing.
///
/// The idle case — the company has no eligible Active_Event — comes back as
/// `event == nil` with zeroed `stats` and an empty `recent` feed; the view
/// renders that gracefully as an "all clear / no active event" state.
///
/// Requires iOS 17 because it drives work off the `@Observable` `AppState`
/// stack via `AuthStore` — consistent with the rest of the app.
@available(iOS 17.0, *)
@MainActor
final class DashboardViewModel: ObservableObject {

    /// The most recent snapshot, or `nil` before the first successful fetch.
    @Published private(set) var snapshot: LiveSnapshotDTO?

    /// Whether a request is currently in flight (drives the first-load spinner
    /// and a subtle refreshing indicator).
    @Published private(set) var isLoading: Bool = false

    /// The last error's user-facing message, cleared on the next success.
    @Published var errorMessage: String?

    /// The events the operator can pick between (live events only). Loaded once
    /// on appear so the picker can offer them.
    @Published private(set) var events: [EventDTO] = []

    /// The event the dashboard is currently showing. Nil means "let the server
    /// resolve the most imminent event" (the default).
    @Published var selectedEventId: Int?

    private let auth: AuthStore

    /// How long to wait between polls. Kept lightweight so the door view stays
    /// current without hammering the API.
    private let interval: Duration

    /// The running poll loop, if any. Holding it lets `start()` be idempotent
    /// and `stop()` cancel cleanly on disappear.
    private var pollTask: Task<Void, Never>?

    init(auth: AuthStore, interval: Duration = .seconds(5)) {
        self.auth = auth
        self.interval = interval
    }

    /// The name of the currently-selected event, for the picker label.
    var selectedEventName: String? {
        guard let id = selectedEventId else { return nil }
        return events.first(where: { $0.id == id })?.name
    }

    /// Load the event list (live events) so the picker has options. Also seeds
    /// `selectedEventId` from the current scan selection if the caller passed
    /// one in, so the dashboard opens on the event you're scanning.
    func loadEvents(preferredId: Int?) async {
        do {
            let response = try await auth.listEvents()
            events = response.events.filter { $0.scannable }
            if selectedEventId == nil {
                selectedEventId = preferredId ?? events.first?.id
            }
        } catch {
            // Non-fatal: the picker just has no options; the snapshot still
            // resolves the server's default event.
        }
    }

    /// Change the shown event and refresh immediately.
    func choose(eventId: Int?) async {
        selectedEventId = eventId
        snapshot = nil
        await fetchOnce()
    }

    /// Convenience view of the current stats, zeroed until the first fetch and
    /// for the idle (no Active_Event) case.
    var stats: LiveStatsDTO {
        snapshot?.stats ?? LiveStatsDTO(scanned: 0, sold: 0, remaining: 0, rate: nil)
    }

    /// The recent scan feed, empty until the first fetch and for the idle case.
    var recent: [LiveRecentScanDTO] {
        snapshot?.recent ?? []
    }

    /// The Active_Event, or `nil` when the company has no eligible event.
    var event: LiveEventDTO? {
        snapshot?.event
    }

    /// The check-in rate formatted as a whole-number percentage, or a dash when
    /// nothing is sold yet (`rate == nil`).
    var rateText: String {
        guard let rate = stats.rate else { return "—" }
        return "\(Int(rate.rounded()))%"
    }

    /// Start polling. Idempotent: if a loop is already running it is left in
    /// place, so repeated `onAppear`s don't spawn overlapping loops.
    func start() {
        guard pollTask == nil else { return }

        pollTask = Task { [weak self] in
            guard let self else { return }
            // Loop until cancelled (view disappeared). Each iteration performs
            // at most one request, so requests never overlap.
            while !Task.isCancelled {
                await self.fetchOnce()
                if Task.isCancelled { break }
                try? await Task.sleep(for: self.interval)
            }
        }
    }

    /// Stop polling and release the loop. Safe to call when not running.
    func stop() {
        pollTask?.cancel()
        pollTask = nil
    }

    /// Perform exactly one snapshot fetch, updating published state. Errors are
    /// surfaced but non-fatal: the loop keeps polling and recovers on success.
    func fetchOnce() async {
        isLoading = true
        do {
            let latest = try await auth.liveSnapshot(eventId: selectedEventId)
            snapshot = latest
            errorMessage = nil
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = APIError.invalidResponse.userMessage
        }
        isLoading = false
    }
}

/// The live door dashboard. Shows the Active_Event header, the four attendance
/// stats (scanned / sold / remaining / rate), and the recent scan feed, polling
/// in the background while visible. Renders a clear idle state when there is no
/// Active_Event (Requirement 7.2).
@available(iOS 17.0, *)
struct DashboardView: View {
    @Environment(AppState.self) private var appState
    @StateObject private var model: DashboardViewModel

    init(auth: AuthStore) {
        _model = StateObject(wrappedValue: DashboardViewModel(auth: auth))
    }

    var body: some View {
        NavigationStack {
            content
                .brandNavigationTitle()
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .primaryAction) {
                        eventPicker
                    }
                }
        }
        .task {
            await model.loadEvents(preferredId: appState.selectedEvent?.id)
            model.start()
        }
        .onDisappear {
            model.stop()
        }
    }

    /// A menu to switch which live event the dashboard shows.
    @ViewBuilder
    private var eventPicker: some View {
        if model.events.isEmpty {
            EmptyView()
        } else {
            Menu {
                ForEach(model.events) { event in
                    Button {
                        Task { await model.choose(eventId: event.id) }
                    } label: {
                        if model.selectedEventId == event.id {
                            Label(event.name, systemImage: "checkmark")
                        } else {
                            Text(event.name)
                        }
                    }
                }
            } label: {
                Label(model.selectedEventName ?? "Event", systemImage: "chevron.down.circle")
                    .font(.footnote.weight(.semibold))
                    .lineLimit(1)
            }
            .accessibilityIdentifier("dashboard.eventPicker")
        }
    }

    @ViewBuilder
    private var content: some View {
        if model.snapshot == nil && model.isLoading {
            // First load, nothing to show yet.
            ProgressView("Loading…")
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if let event = model.event {
            activeEvent(event)
        } else {
            idle
        }
    }

    // MARK: - Active event

    private func activeEvent(_ event: LiveEventDTO) -> some View {
        List {
            if let errorMessage = model.errorMessage {
                Section {
                    Text(errorMessage)
                        .font(.callout)
                        .foregroundStyle(.red)
                        .accessibilityIdentifier("dashboard.error")
                }
            }

            Section {
                VStack(alignment: .leading, spacing: 4) {
                    Text(event.name)
                        .font(.headline)
                    if let pretty = EventDTO.prettyDate(from: event.startsAt) {
                        Text(pretty)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
            }

            Section("Check-in") {
                statRow(label: "Scanned", value: "\(model.stats.scanned)")
                statRow(label: "Sold", value: "\(model.stats.sold)")
                statRow(label: "Remaining", value: "\(model.stats.remaining)")
                statRow(label: "Rate", value: model.rateText)
            }

            Section("Recent scans") {
                if model.recent.isEmpty {
                    Text("No scans yet.")
                        .font(.callout)
                        .foregroundStyle(.secondary)
                } else {
                    ForEach(Array(model.recent.enumerated()), id: \.offset) { _, scan in
                        recentRow(scan)
                    }
                }
            }
        }
    }

    private func statRow(label: String, value: String) -> some View {
        HStack {
            Text(label)
            Spacer()
            Text(value)
                .font(.body.monospacedDigit())
                .foregroundStyle(.secondary)
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(label): \(value)")
    }

    private func recentRow(_ scan: LiveRecentScanDTO) -> some View {
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text(scan.customer ?? scan.reference ?? "—")
                    .font(.body)
                Text("\(scan.outcome.capitalized) · \(scan.operatorName)")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            Spacer()
            Text("\(scan.tickets)")
                .font(.body.monospacedDigit())
                .foregroundStyle(.secondary)
        }
    }

    // MARK: - Idle (no active event)

    private var idle: some View {
        VStack(spacing: 12) {
            BrandEmptyState(
                symbol: "calendar.badge.clock",
                title: "No active event",
                message: "The live dashboard will populate when an event is in progress."
            )

            if let errorMessage = model.errorMessage {
                Text(errorMessage)
                    .font(.callout)
                    .foregroundStyle(.red)
                    .padding(.top, 4)
                    .accessibilityIdentifier("dashboard.error")
            }
        }
        .padding()
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}
