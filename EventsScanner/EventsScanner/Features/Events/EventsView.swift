import SwiftUI

/// Drives the Events tab — the app's landing screen. Lists the Active_Company's
/// events with a tickets-sold count and the public web URL, lets the operator
/// choose which event to scan for, and shows a "start your event" empty state
/// (a link to the web app) when the company has no events yet.
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

/// The Events tab: a list of the company's events (each selectable for scanning,
/// with a link to buy tickets on the web), or the "start your event" empty
/// state when there are none.
@available(iOS 17.0, *)
struct EventsView: View {
    @Environment(AppState.self) private var appState
    @StateObject private var model: EventsViewModel

    init(auth: AuthStore) {
        _model = StateObject(wrappedValue: EventsViewModel(auth: auth))
    }

    var body: some View {
        NavigationStack {
            content
                .navigationTitle("Events")
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
            emptyState
        } else {
            eventList
        }
    }

    // MARK: - Event list

    private var eventList: some View {
        List {
            if let errorMessage = model.errorMessage {
                Section {
                    Text(errorMessage)
                        .font(.callout)
                        .foregroundStyle(.red)
                        .accessibilityIdentifier("events.error")
                }
            }

            Section {
                ForEach(model.events) { event in
                    EventRow(
                        event: event,
                        isSelected: appState.selectedEvent?.id == event.id,
                        onScan: { appState.selectEvent(event) }
                    )
                }
            } footer: {
                Text("Tap an event to scan for it. The Scan tab checks tickets in for your selected event.")
            }
        }
    }

    // MARK: - Empty state (no events)

    private var emptyState: some View {
        VStack(spacing: 16) {
            Image(systemName: "calendar.badge.plus")
                .font(.largeTitle)
                .foregroundStyle(.secondary)
            Text("No events yet")
                .font(.headline)
            Text("Create your first event in the web app to start selling tickets and checking people in.")
                .font(.callout)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)

            if let url = model.webDashboardURL {
                Link(destination: url) {
                    Text("Click here to start your event")
                        .fontWeight(.semibold)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)
                }
                .buttonStyle(.borderedProminent)
                .accessibilityIdentifier("events.startEvent")
            }

            if let errorMessage = model.errorMessage {
                Text(errorMessage)
                    .font(.callout)
                    .foregroundStyle(.red)
                    .padding(.top, 4)
                    .accessibilityIdentifier("events.error")
            }
        }
        .padding(24)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

/// One event row: name, venue, start, status, tickets-sold, a "scan this event"
/// action (or a "scanning" marker when selected) and a buy-tickets web link.
@available(iOS 17.0, *)
private struct EventRow: View {
    let event: EventDTO
    let isSelected: Bool
    let onScan: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Text(event.name)
                    .font(.headline)
                Spacer()
                statusBadge
            }

            if let venue = event.venue, !venue.isEmpty {
                Label(venue, systemImage: "mappin.and.ellipse")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            if let startsAt = event.startsAt {
                Label(startsAt, systemImage: "clock")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            Label(soldText, systemImage: "ticket")
                .font(.footnote)
                .foregroundStyle(.secondary)

            HStack(spacing: 12) {
                if isSelected {
                    Label("Scanning this event", systemImage: "qrcode.viewfinder")
                        .font(.footnote.weight(.semibold))
                        .foregroundStyle(Color.accentColor)
                } else {
                    Button {
                        onScan()
                    } label: {
                        Label("Scan this event", systemImage: "qrcode.viewfinder")
                            .font(.footnote.weight(.semibold))
                    }
                    .buttonStyle(.bordered)
                    .accessibilityIdentifier("events.scanEvent.\(event.id)")
                }

                Spacer()

                if let web = event.webURL, let url = URL(string: web) {
                    Link(destination: url) {
                        Label("Tickets", systemImage: "arrow.up.right.square")
                            .font(.footnote)
                    }
                    .accessibilityIdentifier("events.tickets.\(event.id)")
                }
            }
            .padding(.top, 2)
        }
        .padding(.vertical, 4)
    }

    private var soldText: String {
        if let capacity = event.capacity, capacity > 0 {
            return "\(event.ticketsSold) of \(capacity) tickets sold"
        }
        return "\(event.ticketsSold) tickets sold"
    }

    @ViewBuilder
    private var statusBadge: some View {
        if event.isCancelled {
            badge("Cancelled", color: .red)
        } else if event.isPublished {
            badge("Live", color: .green)
        } else {
            badge("Draft", color: .orange)
        }
    }

    private func badge(_ text: String, color: Color) -> some View {
        Text(text)
            .font(.caption2.weight(.semibold))
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .background(color.opacity(0.15))
            .foregroundStyle(color)
            .clipShape(Capsule())
    }
}
