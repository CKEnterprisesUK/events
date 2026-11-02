import SwiftUI

/// Drives the Events tab — the app's landing screen. Lists the Active_Company's
/// events; tapping one opens its detail page, from which the operator can start
/// scanning (live events only). Shows a "start your event" empty state linking
/// to the web app when the company has no events.
@available(iOS 17.0, *)
@MainActor
final class EventsViewModel: ObservableObject {
    @Published private(set) var events: [EventDTO] = []
    @Published private(set) var webDashboardURL: URL?
    @Published private(set) var isLoading: Bool = false
    @Published private(set) var hasLoaded: Bool = false
    @Published var errorMessage: String?

    private let auth: AuthStore

    init(auth: AuthStore) {
        self.auth = auth
    }

    func load() async {
        isLoading = true
        errorMessage = nil
        do {
            let response = try await auth.listEvents()
            events = response.events
            webDashboardURL = URL(string: response.webDashboardURL)
            hasLoaded = true
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = APIError.invalidResponse.userMessage
        }
        isLoading = false
    }
}

/// The Events tab: a list of the company's events, each a link into its detail
/// page, or the "start your event" empty state when there are none.
@available(iOS 17.0, *)
struct EventsView: View {
    @Environment(AppState.self) private var appState
    @StateObject private var model: EventsViewModel
    private let auth: AuthStore

    init(auth: AuthStore) {
        self.auth = auth
        _model = StateObject(wrappedValue: EventsViewModel(auth: auth))
    }

    var body: some View {
        NavigationStack {
            content
                .brandNavigationTitle()
                .navigationBarTitleDisplayMode(.inline)
                .refreshable { await model.load() }
        }
        .task {
            if !model.hasLoaded { await model.load() }
        }
    }

    @ViewBuilder
    private var content: some View {
        if model.isLoading && model.events.isEmpty {
            ProgressView("Loading events…")
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if model.events.isEmpty && model.hasLoaded {
            CreateEventView(webDashboardURL: model.webDashboardURL, errorMessage: model.errorMessage)
        } else {
            eventList
        }
    }

    private var eventList: some View {
        List {
            if let errorMessage = model.errorMessage {
                Section {
                    InlineErrorText(message: errorMessage)
                        .accessibilityIdentifier("events.error")
                }
            }

            Section {
                ForEach(model.events) { event in
                    NavigationLink {
                        EventDetailView(
                            event: event,
                            isSelected: appState.selectedEvent?.id == event.id,
                            onScan: { appState.selectEvent(event) }
                        )
                    } label: {
                        EventRow(
                            event: event,
                            isSelected: appState.selectedEvent?.id == event.id
                        )
                    }
                }
            } header: {
                Text("Your events")
            } footer: {
                Text("Tap an event to see its details and start scanning.")
            }
        }
    }
}

/// One compact event row in the list: name, pretty date, venue, tickets sold,
/// and a status badge (with a "Scanning" marker when it's the selected event).
@available(iOS 17.0, *)
private struct EventRow: View {
    let event: EventDTO
    let isSelected: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text(event.name)
                    .font(.headline)
                    .lineLimit(2)
                Spacer()
                EventStatusBadge(event: event)
            }

            if let pretty = event.prettyDate {
                Label(pretty, systemImage: "calendar")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            if let venue = event.venue, !venue.isEmpty {
                Label(venue, systemImage: "mappin.and.ellipse")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }

            HStack(spacing: 10) {
                Label(soldText, systemImage: "ticket")
                if isSelected {
                    Label("Scanning", systemImage: "qrcode.viewfinder")
                        .foregroundStyle(Brand.emerald)
                        .fontWeight(.semibold)
                }
            }
            .font(.caption)
            .foregroundStyle(.secondary)
        }
        .padding(.vertical, 4)
    }

    private var soldText: String {
        if let capacity = event.capacity, capacity > 0 {
            return "\(event.ticketsSold) of \(capacity) sold"
        }
        return "\(event.ticketsSold) sold"
    }
}

/// The status pill: Cancelled (red), Live (emerald), or Draft (amber).
@available(iOS 17.0, *)
struct EventStatusBadge: View {
    let event: EventDTO

    var body: some View {
        Text(event.statusLabel)
            .font(.caption2.weight(.semibold))
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .background(color.opacity(0.15))
            .foregroundStyle(color)
            .clipShape(Capsule())
    }

    private var color: Color {
        if event.isCancelled { return Brand.danger }
        return event.isPublished ? Brand.emerald : Brand.amber
    }
}
