import SwiftUI

/// Drives the order detail screen: loads one order via `GET /api/orders/{id}`
/// and, for operators who may check in, performs a manual "mark as entered" via
/// `POST /api/orders/{id}/check-in` (the same outcome contract as a scan).
@available(iOS 17.0, *)
@MainActor
final class OrderDetailViewModel: ObservableObject {
    @Published private(set) var order: OrderDetailDTO?
    @Published private(set) var isLoading: Bool = false
    @Published private(set) var isCheckingIn: Bool = false
    @Published var errorMessage: String?

    /// The outcome of the most recent manual check-in, shown as a banner.
    @Published private(set) var outcome: ScanOutcomeDTO?

    let orderId: Int
    private let auth: AuthStore

    init(auth: AuthStore, orderId: Int) {
        self.auth = auth
        self.orderId = orderId
    }

    func load() async {
        isLoading = true
        errorMessage = nil
        do {
            order = try await auth.orderDetail(id: orderId)
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = APIError.invalidResponse.userMessage
        }
        isLoading = false
    }

    /// Whether a manual check-in is sensible to offer: the order is confirmed
    /// and not already scanned.
    var canAttemptCheckIn: Bool {
        guard let order else { return false }
        return order.isConfirmed && !order.isScanned
    }

    /// Perform the manual check-in. Returns the ISO scanned-at on a successful
    /// check-in so the caller can update its list; nil otherwise.
    @discardableResult
    func checkIn() async -> String? {
        guard !isCheckingIn else { return nil }
        isCheckingIn = true
        errorMessage = nil
        defer { isCheckingIn = false }
        do {
            let result = try await auth.checkInOrder(id: orderId)
            outcome = result
            // Refresh the order so the detail reflects the new state (scanned_at,
            // who scanned it) regardless of the outcome.
            await load()
            if result.status == .checkedIn {
                return result.scannedAt
            }
            return nil
        } catch let error as APIError {
            errorMessage = error.userMessage
            return nil
        } catch {
            errorMessage = APIError.invalidResponse.userMessage
            return nil
        }
    }
}

/// The order detail screen with a manual check-in action for box-office-and-up
/// operators (the server also gates on `check_in`, so an accountant is denied).
@available(iOS 17.0, *)
struct OrderDetailView: View {
    @StateObject private var model: OrderDetailViewModel

    /// Whether the signed-in role may check in (drives whether the button is
    /// shown; the server enforces the same rule).
    let canCheckIn: Bool

    /// Called with the ISO scanned-at when a manual check-in succeeds, so the
    /// presenting list can mark the row green.
    let onCheckedIn: (String?) -> Void

    init(auth: AuthStore, orderId: Int, canCheckIn: Bool, onCheckedIn: @escaping (String?) -> Void) {
        _model = StateObject(wrappedValue: OrderDetailViewModel(auth: auth, orderId: orderId))
        self.canCheckIn = canCheckIn
        self.onCheckedIn = onCheckedIn
    }

    var body: some View {
        List {
            if let errorMessage = model.errorMessage {
                Section {
                    Text(errorMessage)
                        .font(.callout)
                        .foregroundStyle(.red)
                        .accessibilityIdentifier("orderDetail.error")
                }
            }

            if model.isLoading && model.order == nil {
                Section {
                    HStack {
                        ProgressView()
                        Text("Loading order…").foregroundStyle(.secondary)
                    }
                }
            } else if let order = model.order {
                orderSections(order)
            }
        }
        .navigationTitle(model.order?.reference ?? "Order")
        .navigationBarTitleDisplayMode(.inline)
        .task { await model.load() }
    }

    @ViewBuilder
    private func orderSections(_ order: OrderDetailDTO) -> some View {
        Section {
            if order.isScanned {
                Label("Already entered", systemImage: "checkmark.circle.fill")
                    .font(.headline)
                    .foregroundStyle(.green)
                if let scannedBy = order.scannedBy {
                    Text("Checked in by \(scannedBy)")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            } else if order.isConfirmed {
                Label("Not yet entered", systemImage: "circle")
                    .font(.headline)
                    .foregroundStyle(.secondary)
            } else {
                Label("Not valid for entry (\(order.status.capitalized))", systemImage: "xmark.circle")
                    .font(.headline)
                    .foregroundStyle(.red)
            }
        }

        Section("Customer") {
            LabeledContent("Name", value: order.customerName ?? "—")
            if let email = order.customerEmail {
                LabeledContent("Email", value: email)
            }
            if let eventName = order.eventName {
                LabeledContent("Event", value: eventName)
            }
        }

        if !order.breakdown.isEmpty {
            Section("Tickets") {
                ForEach(order.breakdown, id: \.name) { row in
                    HStack {
                        Text(row.name)
                        Spacer()
                        Text("\(row.quantity)")
                            .font(.body.monospacedDigit())
                            .foregroundStyle(.secondary)
                    }
                }
            }
        }

        if let outcome = model.outcome {
            Section {
                Text(outcome.message)
                    .font(.callout)
                    .foregroundStyle(outcome.status == .checkedIn ? .green : .red)
                    .accessibilityIdentifier("orderDetail.outcome")
            }
        }

        if canCheckIn && model.canAttemptCheckIn {
            Section {
                Button {
                    Task {
                        let scannedAt = await model.checkIn()
                        if scannedAt != nil || model.order?.isScanned == true {
                            onCheckedIn(scannedAt)
                        }
                    }
                } label: {
                    HStack {
                        if model.isCheckingIn {
                            ProgressView()
                            Text("Checking in…")
                        } else {
                            Label("Mark as entered", systemImage: "checkmark.circle")
                        }
                        Spacer()
                    }
                }
                .disabled(model.isCheckingIn)
                .accessibilityIdentifier("orderDetail.checkIn")
            } footer: {
                Text("Marks this order as entered and checks in its tickets, just as if they were scanned.")
            }
        }
    }
}
