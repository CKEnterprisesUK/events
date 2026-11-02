import SwiftUI

/// Drives the customer search screen: takes a free-text term, calls
/// `GET /api/customers?q=term` (through `AuthStore`), and lists the matching
/// `{name, email, orders_count, confirmed_spend_minor}` rows (Requirement 8.2).
///
/// This screen is only ever reached from the Customer Search tab, which
/// `MainTabView` shows only when the role holds `ACTION_MANAGE_ORDERS`
/// (Requirements 8.4, 8.5). A 403 therefore should not occur here; it is still
/// handled gracefully by surfacing `APIError.forbidden`'s user message rather
/// than crashing or showing a blank list.
///
/// Requires iOS 17 because it drives the `@Observable` `AppState` via
/// `AuthStore` — consistent with the rest of the app (see README).
@available(iOS 17.0, *)
@MainActor
final class CustomerSearchViewModel: ObservableObject {
    @Published var query: String = ""
    @Published private(set) var customers: [CustomerDTO] = []
    @Published private(set) var isSearching: Bool = false
    @Published var errorMessage: String?

    /// True once a search has returned so the UI can tell "no results for this
    /// term" apart from "you haven't searched yet".
    @Published private(set) var hasSearched: Bool = false

    private let auth: AuthStore

    init(auth: AuthStore) {
        self.auth = auth
    }

    /// Run the search for the current `query`. An empty term returns the whole
    /// tenant-scoped roster (the server treats a missing `q` that way), so the
    /// field doubles as a browse-all when cleared.
    func search() async {
        guard !isSearching else { return }
        isSearching = true
        errorMessage = nil

        let term = query.trimmingCharacters(in: .whitespacesAndNewlines)

        do {
            let response = try await auth.searchCustomers(query: term.isEmpty ? nil : term)
            customers = response.customers
            hasSearched = true
        } catch let error as APIError {
            errorMessage = error.userMessage
            customers = []
        } catch {
            errorMessage = APIError.invalidResponse.userMessage
            customers = []
        }

        isSearching = false
    }
}

/// The customer search form: a search field, a result list, and inline
/// loading/empty/error states.
@available(iOS 17.0, *)
struct CustomerSearchView: View {
    @StateObject private var model: CustomerSearchViewModel
    private let auth: AuthStore

    init(auth: AuthStore) {
        self.auth = auth
        _model = StateObject(wrappedValue: CustomerSearchViewModel(auth: auth))
    }

    var body: some View {
        NavigationStack {
            List {
                if let errorMessage = model.errorMessage {
                    Section {
                        Text(errorMessage)
                            .font(.callout)
                            .foregroundStyle(.red)
                            .accessibilityIdentifier("customers.error")
                    }
                }

                if model.isSearching && model.customers.isEmpty {
                    Section {
                        HStack {
                            ProgressView()
                            Text("Searching…")
                                .foregroundStyle(.secondary)
                        }
                    }
                } else if model.customers.isEmpty && model.hasSearched && model.errorMessage == nil {
                    Section {
                        Text("No customers match that search.")
                            .foregroundStyle(.secondary)
                    }
                } else {
                    ForEach(model.customers) { customer in
                        NavigationLink {
                            CustomerDetailView(
                                auth: auth,
                                token: customer.token,
                                fallbackName: customer.name
                            )
                        } label: {
                            CustomerRow(customer: customer)
                        }
                    }
                }
            }
            .brandNavigationTitle()
            .navigationBarTitleDisplayMode(.inline)
            .searchable(
                text: $model.query,
                placement: .navigationBarDrawer(displayMode: .always),
                prompt: "Name, email or order reference"
            )
            .onSubmit(of: .search) {
                Task { await model.search() }
            }
            .task { await model.search() }
        }
    }
}

/// One customer row: name (or email when unnamed), the email, the order count,
/// and the confirmed spend formatted from minor units.
@available(iOS 17.0, *)
private struct CustomerRow: View {
    let customer: CustomerDTO

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(customer.name?.isEmpty == false ? customer.name! : customer.email)
                .font(.headline)

            if customer.name?.isEmpty == false {
                Text(customer.email)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            HStack(spacing: 12) {
                Label("\(customer.ordersCount)", systemImage: "bag")
                Label(formattedSpend, systemImage: "creditcard")
            }
            .font(.footnote)
            .foregroundStyle(.secondary)
        }
        .padding(.vertical, 2)
    }

    /// Format the confirmed spend (minor units) as a major-unit currency
    /// string using the device locale. Pure display; no rounding assumptions
    /// beyond dividing by 100.
    private var formattedSpend: String {
        let major = Double(customer.confirmedSpendMinor) / 100.0
        return major.formatted(.currency(code: Locale.current.currency?.identifier ?? "GBP"))
    }
}
