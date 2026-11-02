import SwiftUI

/// The always-available fallback to the camera (Requirement 6.3): a form where
/// the operator types an Order_Reference and submits it to `POST /api/scan` as
/// `{ "reference": … }`.
///
/// A manual reference and the scanned QR payload resolve to the same
/// Scan_Outcome server-side (Requirements 6.1), so this view does no local
/// validation beyond "non-empty" — it hands the trimmed reference to the
/// caller's `submit` closure (the `ScanViewModel`), which performs the request
/// and renders the result on the scan screen.
@available(iOS 17.0, *)
struct ManualEntryView: View {
    /// Submits the entered reference. Supplied by the scan screen, which owns
    /// the network call and the outcome rendering.
    let submit: (String) async -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var reference: String = ""
    @State private var isSubmitting = false

    private var canSubmit: Bool {
        !reference.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && !isSubmitting
    }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("Order reference", text: $reference)
                        .textInputAutocapitalization(.characters)
                        .autocorrectionDisabled()
                        .accessibilityIdentifier("manualEntry.reference")
                } footer: {
                    Text("Enter the order reference printed on the ticket.")
                }

                Section {
                    Button {
                        Task {
                            isSubmitting = true
                            await submit(reference)
                            isSubmitting = false
                            dismiss()
                        }
                    } label: {
                        if isSubmitting {
                            ProgressView().frame(maxWidth: .infinity)
                        } else {
                            Text("Check In").frame(maxWidth: .infinity)
                        }
                    }
                    .disabled(!canSubmit)
                    .accessibilityIdentifier("manualEntry.submit")
                }
            }
            .navigationTitle("Manual Entry")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
        }
    }
}
