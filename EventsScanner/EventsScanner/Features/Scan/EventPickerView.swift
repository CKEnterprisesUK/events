import SwiftUI

/// The behaviour the in-place event picker needs from the networking layer:
/// list the Active_Company's events so the operator can choose one to scan for.
/// `AuthStore` satisfies this (see its `listEvents`), so the picker depends on
/// behaviour rather than on `AuthStore`/`APIClient` concretely — mirroring how
/// the scan screen consumes `ScanSubmitting`.
@available(iOS 17.0, *)
protocol EventListing {
    /// List the Active_Company's events via `GET /api/events`.
    func listEvents() async throws -> EventsResponseDTO
}

@available(iOS 17.0, *)
extension AuthStore: EventListing {}

/// Drives the event picker sheet: loads the company's events and exposes a
/// loading / loaded / error state the sheet renders. Kept separate from the
/// Events tab's own view model so the picker can be presented from the Scan tab
/// without pulling in the tab's navigation chrome.
@available(iOS 17.0, *)
@MainActor
final class EventPickerViewModel: ObservableObject {
    @Published private(set) var events: [EventDTO] = []
    @Published private(set) var isLoading = false
    @Published private(set) var hasLoaded = false
    @Published var errorMessage: String?

    private let lister: EventListing

    init(lister: EventListing) {
        self.lister = lister
    }

    func load() async {
        isLoading = true
        errorMessage = nil
        do {
            let response = try await lister.listEvents()
            events = response.events
            hasLoaded = true
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = APIError.invalidResponse.userMessage
        }
        isLoading = false
    }
}

/// A sheet that lets the operator pick the event to scan for without leaving
/// the Scan tab. Reached from the calendar control on the Scan screen (the
/// toolbar button, the "Choose event" empty-state button, and the banner's
/// "Change"). Picking an event calls `onSelect` and dismisses; the Scan screen
/// then scopes check-ins to that event.
@available(iOS 17.0, *)
struct EventPickerView: View {
    @Environment(\.dismiss) private var dismiss
    @StateObject private var model: EventPickerViewModel

    /// The currently selected event id, so the sheet can mark it.
    let selectedEventID: Int?
    /// Called with the chosen event when the operator taps a row.
    let onSelect: (EventDTO) -> Void

    init(lister: EventListing, selectedEventID: Int?, onSelect: @escaping (EventDTO) -> Void) {
        _model = StateObject(wrappedValue: EventPickerViewModel(lister: lister))
        self.selectedEventID = selectedEventID
        self.onSelect = onSelect
    }

    var body: some View {
        NavigationStack {
            content
                .navigationTitle("Choose event")
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .topBarLeading) {
                        Button("Close") { dismiss() }
                            .accessibilityIdentifier("eventPicker.close")
                    }
                }
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

    private var eventList: some View {
        List {
            if let errorMessage = model.errorMessage {
                Section {
                    InlineErrorText(message: errorMessage)
                        .accessibilityIdentifier("eventPicker.error")
                }
            }

            Section {
                ForEach(model.events) { event in
                    Button {
                        onSelect(event)
                        dismiss()
                    } label: {
                        EventPickerRow(
                            event: event,
                            isSelected: selectedEventID == event.id
                        )
                    }
                    .accessibilityIdentifier("eventPicker.event.\(event.id)")
                }
            } header: {
                Text("Your events")
            } footer: {
                Text("Pick the event you're checking people in for. You can change it any time.")
            }
        }
    }

    private var emptyState: some View {
        VStack(spacing: 16) {
            BrandEmptyState(
                symbol: "calendar.badge.exclamationmark",
                title: "No events yet",
                message: "Create an event in the web app, then come back to scan tickets for it."
            )
            if let errorMessage = model.errorMessage {
                InlineErrorText(message: errorMessage)
                    .accessibilityIdentifier("eventPicker.error")
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

/// One event row in the picker: name, date, venue, and a status badge, with a
/// checkmark on the currently selected event.
@available(iOS 17.0, *)
private struct EventPickerRow: View {
    let event: EventDTO
    let isSelected: Bool

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            VStack(alignment: .leading, spacing: 6) {
                HStack {
                    Text(event.name)
                        .font(.headline)
                        .foregroundStyle(.primary)
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
            }

            if isSelected {
                Image(systemName: "checkmark.circle.fill")
                    .foregroundStyle(Brand.emerald)
                    .accessibilityLabel("Selected")
            }
        }
        .padding(.vertical, 4)
        .contentShape(Rectangle())
    }
}
