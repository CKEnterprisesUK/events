import SwiftUI

/// An event's detail page: a hero image, the key facts (when, where, how much,
/// how many sold), and a "Scan for this event" button in the top-right — shown
/// only for a live (scannable) event. Non-live events explain why scanning is
/// unavailable and offer the web link to manage the event.
@available(iOS 17.0, *)
struct EventDetailView: View {
    @Environment(AppState.self) private var appState
    @Environment(\.dismiss) private var dismiss

    let event: EventDTO
    /// Whether this event is already the selected scan target.
    let isSelected: Bool
    /// Selects this event for scanning.
    let onScan: () -> Void

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                hero
                header
                facts
                if let priceSummary = event.priceSummary {
                    tickets(priceSummary)
                }
                if let description = event.description, !description.isEmpty {
                    about(description)
                }
                webLink
                if !event.scannable {
                    notLiveNotice
                }
            }
            .padding()
        }
        .brandNavigationTitle()
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            // "Scan for this event" lives in the top-right, but only for a live
            // event — you can't scan a draft/cancelled one.
            if event.scannable {
                ToolbarItem(placement: .primaryAction) {
                    Button {
                        onScan()
                        dismiss()
                    } label: {
                        Label(isSelected ? "Scanning" : "Scan", systemImage: "qrcode.viewfinder")
                    }
                    .fontWeight(.semibold)
                    .accessibilityIdentifier("eventDetail.scan")
                }
            }
        }
    }

    // MARK: - Hero image

    @ViewBuilder
    private var hero: some View {
        if let urlString = event.imageURL, let url = URL(string: urlString) {
            AsyncImage(url: url) { phase in
                switch phase {
                case .success(let image):
                    image.resizable().scaledToFill()
                case .empty:
                    ZStack { Brand.surface; ProgressView() }
                default:
                    heroPlaceholder
                }
            }
            .frame(height: 180)
            .frame(maxWidth: .infinity)
            .clipShape(RoundedRectangle(cornerRadius: 16))
        } else {
            heroPlaceholder
                .frame(height: 140)
                .frame(maxWidth: .infinity)
                .clipShape(RoundedRectangle(cornerRadius: 16))
        }
    }

    private var heroPlaceholder: some View {
        ZStack {
            Brand.navy
            Image(systemName: "ticket.fill")
                .font(.system(size: 44))
                .foregroundStyle(.white.opacity(0.85))
        }
    }

    // MARK: - Header (name + status)

    private var header: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(alignment: .top) {
                Text(event.name)
                    .font(.title2.bold())
                Spacer()
                EventStatusBadge(event: event)
            }
            if isSelected && event.scannable {
                Label("You're scanning this event", systemImage: "qrcode.viewfinder")
                    .font(.footnote.weight(.semibold))
                    .foregroundStyle(Brand.emerald)
            }
        }
    }

    // MARK: - Facts (when / where / sold)

    private var facts: some View {
        VStack(alignment: .leading, spacing: 12) {
            if let pretty = event.prettyDate {
                factRow(icon: "calendar", title: "When", value: pretty)
            }
            if let location = locationText {
                factRow(icon: "mappin.and.ellipse", title: "Where", value: location)
            }
            factRow(icon: "ticket", title: "Tickets sold", value: soldText)
        }
        .padding()
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Brand.surface, in: RoundedRectangle(cornerRadius: 16))
    }

    private func factRow(icon: String, title: String, value: String) -> some View {
        HStack(alignment: .top, spacing: 12) {
            Image(systemName: icon)
                .foregroundStyle(Brand.emerald)
                .frame(width: 22)
                .accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                Text(value)
                    .font(.callout)
            }
            Spacer(minLength: 0)
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(title): \(value)")
    }

    private var locationText: String? {
        let parts = [event.venue, event.address].compactMap { $0 }.filter { !$0.isEmpty }
        return parts.isEmpty ? nil : parts.joined(separator: "\n")
    }

    private var soldText: String {
        if let capacity = event.capacity, capacity > 0 {
            return "\(event.ticketsSold) of \(capacity)"
        }
        return "\(event.ticketsSold)"
    }

    // MARK: - Tickets

    private func tickets(_ summary: String) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text("Tickets")
                    .font(.headline)
                Spacer()
                Text(summary)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(Brand.emerald)
            }
            ForEach(event.ticketTypes) { type in
                HStack {
                    Text(type.name)
                        .font(.callout)
                    Spacer()
                    Text(type.priceMinor == 0 ? "Free" : EventDTO.money(type.priceMinor, currency: event.currency))
                        .font(.callout.monospacedDigit())
                        .foregroundStyle(.secondary)
                }
            }
        }
        .padding()
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Brand.surface, in: RoundedRectangle(cornerRadius: 16))
    }

    // MARK: - About

    private func about(_ description: String) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("About")
                .font(.headline)
            Text(description)
                .font(.callout)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    // MARK: - Web link + non-live notice

    @ViewBuilder
    private var webLink: some View {
        if let web = event.webURL, let url = URL(string: web) {
            Link(destination: url) {
                Label("Buy tickets / manage online", systemImage: "arrow.up.right.square")
                    .font(.callout.weight(.medium))
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 10)
            }
            .buttonStyle(.bordered)
            .accessibilityIdentifier("eventDetail.web")
        }
    }

    private var notLiveNotice: some View {
        HStack(spacing: 10) {
            Image(systemName: "exclamationmark.triangle.fill")
                .foregroundStyle(Brand.amber)
                .accessibilityHidden(true)
            Text(event.isCancelled
                 ? "This event is cancelled, so tickets can't be scanned."
                 : "This event isn't live yet. Publish it online before scanning tickets.")
                .font(.footnote)
                .foregroundStyle(.secondary)
        }
        .padding()
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Brand.amber.opacity(0.12), in: RoundedRectangle(cornerRadius: 12))
    }
}
