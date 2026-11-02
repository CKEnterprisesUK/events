import SwiftUI

/// Drives the customer detail screen: loads one customer's orders via
/// `GET /api/customers/{token}` and lists them so the operator can drill into an
/// order to check it in manually.
@available(iOS 17.0, *)
@MainActor
final class CustomerDetailViewModel: ObservableObject {
    @Published private(set) var customer: CustomerIdentityDTO?
    @Published private(set) var orders: [CustomerOrderDTO] = []
    @Published private(set) var isLoading: Bool = false
    @Published var errorMessage: String?

    let token: String
    let fallbackName: String?
    private let auth: AuthStore

    init(auth: AuthStore, token: String, fallbackName: String?) {
        self.auth = auth
        self.token = token
        self.fallbackName = fallbackName
    }

    var title: String {
        customer?.name?.isEmpty == false ? customer!.name! : (fallbackName ?? customer?.email ?? "Customer")
    }

    func load() async {
        isLoading = true
        errorMessage = nil
        do {
            let detail = try await auth.customerDetail(token: token)
            customer = detail.customer
            orders = detail.orders
        } catch let error as APIError {
            errorMessage = error.userMessage
        } catch {
            errorMessage = APIError.invalidResponse.userMessage
        }
        isLoading = false
    }

    /// Update one order's row in place after a manual check-in returns, so the
    /// list reflects the new "entered" state without a full reload.
    func markScanned(orderId: Int, at scannedAt: String?) {
        guard let index = orders.firstIndex(where: { $0.id == orderId }) else { return }
        let existing = orders[index]
        orders[index] = CustomerOrderDTO(
            id: existing.id,
            reference: existing.reference,
            eventId: existing.eventId,
            eventName: existing.eventName,
            status: existing.status,
            isConfirmed: existing.isConfirmed,
            ticketsCount: existing.ticketsCount,
            scannedAt: scannedAt ?? existing.scannedAt ?? ISO8601DateFormatter().string(from: Date()),
            orderTotalMinor: existing.orderTotalMinor
        )
    }
}

/// The customer detail screen: the customer's orders, each a link into the
/// order detail where it can be checked in.
@available(iOS 17.0, *)
struct CustomerDetailView: View {
    @Environment(AppState.self) private var appState
    @StateObject private var model: CustomerDetailViewModel
    let auth: AuthStore

    init(auth: AuthStore, token: String, fallbackName: String?) {
        self.auth = auth
        _model = StateObject(wrappedValue: CustomerDetailViewModel(auth: auth, token: token, fallbackName: fallbackName))
    }

    var body: some View {
        List {
            if let errorMessage = model.errorMessage {
                Section {
                    Text(errorMessage)
                        .font(.callout)
                        .foregroundStyle(.red)
                        .accessibilityIdentifier("customerDetail.error")
                }
            }

            if let email = model.customer?.email {
                Section {
                    Text(email)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .textSelection(.enabled)
                }
            }

            Section("Orders") {
                if model.isLoading && model.orders.isEmpty {
                    HStack {
                        ProgressView()
                        Text("Loading orders…").foregroundStyle(.secondary)
                    }
                } else if model.orders.isEmpty {
                    Text("No orders for this customer.")
                        .foregroundStyle(.secondary)
                } else {
                    ForEach(model.orders) { order in
                        NavigationLink {
                            OrderDetailView(
                                auth: auth,
                                orderId: order.id,
                                canCheckIn: appState.canCheckIn,
                                onCheckedIn: { scannedAt in
                                    model.markScanned(orderId: order.id, at: scannedAt)
                                }
                            )
                        } label: {
                            CustomerOrderRow(order: order)
                        }
                    }
                }
            }
        }
        .navigationTitle(model.title)
        .navigationBarTitleDisplayMode(.inline)
        .task { await model.load() }
    }
}

/// One order row in the customer detail: reference, event, ticket count, and a
/// green "Entered" marker once the order has been checked in.
@available(iOS 17.0, *)
private struct CustomerOrderRow: View {
    let order: CustomerOrderDTO

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(order.reference ?? "—")
                    .font(.headline.monospaced())
                Spacer()
                if order.isScanned {
                    Label("Entered", systemImage: "checkmark.circle.fill")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.green)
                }
            }

            if let eventName = order.eventName {
                Text(eventName)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            HStack(spacing: 12) {
                Label("\(order.ticketsCount)", systemImage: "ticket")
                Text(order.status.capitalized)
            }
            .font(.footnote)
            .foregroundStyle(.secondary)
        }
        .padding(.vertical, 2)
    }
}
