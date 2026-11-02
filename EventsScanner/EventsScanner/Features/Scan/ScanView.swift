import SwiftUI

/// The behaviour the scan screen needs from the networking layer: submit a
/// decoded QR `payload` or a manually typed `reference` and get back a decoded
/// Scan_Outcome. `AuthStore` satisfies this (see its `submitScan`/`submitManual`),
/// so the view model depends on behaviour rather than on `AuthStore`/`APIClient`
/// concretely — mirroring how the auth screens consume `AuthStore`.
@available(iOS 17.0, *)
protocol ScanSubmitting {
    /// Submit a scanned QR payload for check-in (Requirements 10.4, 6.2).
    func submitScan(payload: String) async throws -> ScanOutcomeDTO
    /// Submit a manually typed Order_Reference for check-in (Requirements 6.1, 6.3).
    func submitManual(reference: String) async throws -> ScanOutcomeDTO
}

@available(iOS 17.0, *)
extension AuthStore: ScanSubmitting {}

/// Drives the scan screen: owns the camera permission state, feeds decoded QR
/// payloads to `POST /api/scan`, and holds the latest Scan_Outcome to render.
///
/// Permission (Requirements 10.1, 10.2):
///   - on first appearance, if permission is `.notDetermined`, the system
///     prompt is requested;
///   - `.denied` surfaces "camera access is required" and NO scanning occurs;
///   - `.authorized` runs the camera (the view renders `CameraScanner`).
///
/// Submission (Requirements 10.4, 9.2, 9.3):
///   - each decoded payload is submitted as-is — no HMAC/verification on device;
///   - a rapid repeat of the SAME payload while a submit is in flight or already
///     resolved is ignored, so one physical scan yields one submission;
///   - a failed submit surfaces the `APIError` message (offline etc.) and the
///     outcome is NOT treated as a successful check-in.
@available(iOS 17.0, *)
@MainActor
final class ScanViewModel: ObservableObject {

    /// The message shown when camera access is denied (Requirement 10.2).
    static let cameraAccessRequiredMessage = "Camera access is required"

    @Published private(set) var permission: CameraPermission = .notDetermined
    @Published private(set) var isSubmitting: Bool = false

    /// The most recent decoded Scan_Outcome, rendered below the camera.
    @Published private(set) var outcome: ScanOutcomeDTO?

    /// A transport/permission error message (e.g. offline), shown to the
    /// operator. Distinct from a resolved-but-non-success outcome, which is a
    /// normal `ScanOutcomeDTO` the server returned.
    @Published var errorMessage: String?

    private let submitter: ScanSubmitting
    private let authorizer: CameraAuthorizing

    /// The last payload we acted on, used to suppress the camera re-emitting the
    /// same QR dozens of times per second (Requirement 10.3 → one scan, one
    /// submit).
    private var lastSubmittedPayload: String?

    init(submitter: ScanSubmitting, authorizer: CameraAuthorizing = AVCameraAuthorizer()) {
        self.submitter = submitter
        self.authorizer = authorizer
        self.permission = authorizer.current()
    }

    /// Whether the live camera should be shown (access granted).
    var isCameraActive: Bool { permission == .authorized }

    /// Whether to show the "camera access is required" state (Requirement 10.2).
    var isCameraDenied: Bool { permission == .denied }

    /// On appear, request camera access the first time it is needed
    /// (Requirement 10.1). Already-decided states are left untouched.
    func onAppear() async {
        guard permission == .notDetermined else {
            permission = authorizer.current()
            return
        }
        permission = await authorizer.request()
    }

    /// Handle a payload the camera decoded. Ignores an identical repeat of the
    /// payload we last handled (duplicate suppression) and does nothing while a
    /// submit is already in flight.
    func handleDecoded(payload: String) async {
        guard permission == .authorized, !isSubmitting else { return }
        guard payload != lastSubmittedPayload else { return }
        lastSubmittedPayload = payload
        await submit { try await self.submitter.submitScan(payload: payload) }
    }

    /// Submit a manually typed Order_Reference (the always-available fallback —
    /// Requirements 6.1, 6.3). Clears the scan-side duplicate guard so the same
    /// reference can be re-scanned afterwards if needed.
    func submitManual(reference: String) async {
        let trimmed = reference.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty, !isSubmitting else { return }
        lastSubmittedPayload = nil
        await submit { try await self.submitter.submitManual(reference: trimmed) }
    }

    /// Clear the current outcome so the operator can scan the next ticket, and
    /// reset the duplicate guard.
    func reset() {
        outcome = nil
        errorMessage = nil
        lastSubmittedPayload = nil
    }

    /// Shared submit wrapper: flips the in-flight flag, renders the returned
    /// outcome, and maps any failure to a user message without ever reporting a
    /// successful check-in (Requirement 9.2).
    private func submit(_ operation: @escaping () async throws -> ScanOutcomeDTO) async {
        isSubmitting = true
        errorMessage = nil
        do {
            let result = try await operation()
            outcome = result
        } catch let error as APIError {
            // Offline or any API failure: show the message, keep no success.
            outcome = nil
            errorMessage = error.userMessage
        } catch {
            outcome = nil
            errorMessage = APIError.invalidResponse.userMessage
        }
        isSubmitting = false
    }
}

/// The scan screen: a live camera when access is granted, the "camera access is
/// required" state when denied, the manual-entry fallback, and the rendered
/// Scan_Outcome for the most recent submission.
@available(iOS 17.0, *)
struct ScanView: View {
    @StateObject private var model: ScanViewModel
    @State private var showingManualEntry = false

    init(auth: AuthStore) {
        _model = StateObject(wrappedValue: ScanViewModel(submitter: auth))
    }

    /// Test/preview seam allowing an injected submitter + authorizer.
    init(submitter: ScanSubmitting, authorizer: CameraAuthorizing) {
        _model = StateObject(wrappedValue: ScanViewModel(submitter: submitter, authorizer: authorizer))
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                cameraRegion
                    .frame(maxWidth: .infinity)
                    .frame(height: 320)
                    .background(Color.black.opacity(0.9))
                    .clipped()

                outcomeRegion
            }
            .navigationTitle("Scan")
            .toolbar {
                ToolbarItem(placement: .primaryAction) {
                    Button("Enter reference") { showingManualEntry = true }
                        .accessibilityIdentifier("scan.manualEntry")
                }
            }
            .sheet(isPresented: $showingManualEntry) {
                ManualEntryView { reference in
                    await model.submitManual(reference: reference)
                }
            }
        }
        .task { await model.onAppear() }
    }

    /// The camera (granted) or the "camera access is required" state (denied),
    /// with a neutral prompt while the decision is pending.
    @ViewBuilder
    private var cameraRegion: some View {
        if model.isCameraActive {
            CameraScanner { payload in
                Task { await model.handleDecoded(payload: payload) }
            }
        } else if model.isCameraDenied {
            VStack(spacing: 12) {
                Image(systemName: "camera.fill").font(.largeTitle)
                Text(ScanViewModel.cameraAccessRequiredMessage)
                    .font(.headline)
                    .foregroundStyle(.white)
                    .accessibilityIdentifier("scan.cameraDenied")
                Text("Enable camera access in Settings, or enter the reference manually.")
                    .font(.caption)
                    .foregroundStyle(.white.opacity(0.7))
                    .multilineTextAlignment(.center)
            }
            .padding()
        } else {
            VStack(spacing: 12) {
                ProgressView().tint(.white)
                Text("Preparing camera…")
                    .font(.caption)
                    .foregroundStyle(.white.opacity(0.7))
            }
        }
    }

    /// The most recent outcome, an error line, or an idle prompt.
    @ViewBuilder
    private var outcomeRegion: some View {
        ScrollView {
            VStack(spacing: 16) {
                if model.isSubmitting {
                    ProgressView("Checking in…")
                        .padding(.top)
                }

                if let errorMessage = model.errorMessage {
                    Text(errorMessage)
                        .font(.callout)
                        .foregroundStyle(.red)
                        .multilineTextAlignment(.center)
                        .accessibilityIdentifier("scan.error")
                }

                if let outcome = model.outcome {
                    ScanOutcomeCard(outcome: outcome)
                    Button("Scan next") { model.reset() }
                        .accessibilityIdentifier("scan.next")
                } else if model.errorMessage == nil && !model.isSubmitting {
                    Text("Point the camera at a ticket QR code.")
                        .font(.callout)
                        .foregroundStyle(.secondary)
                        .padding(.top)
                }
            }
            .padding()
        }
    }
}

/// Renders a single Scan_Outcome: a coloured status banner, the message, and
/// the richer fields (customer, reference, scanned-at/by, ticket breakdown)
/// that the contract populates per status.
@available(iOS 17.0, *)
struct ScanOutcomeCard: View {
    let outcome: ScanOutcomeDTO

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Image(systemName: symbolName)
                Text(statusTitle)
                    .font(.headline)
                Spacer()
            }
            .foregroundStyle(.white)
            .padding()
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(statusColor)
            .clipShape(RoundedRectangle(cornerRadius: 10))
            .accessibilityIdentifier("scan.outcome.\(outcome.status.rawValue)")

            Text(outcome.message)
                .font(.body)

            if let customer = outcome.customer {
                labelled("Customer", customer)
            }
            if let reference = outcome.reference {
                labelled("Reference", reference)
            }
            if let scannedAt = outcome.scannedAt {
                labelled("Scanned at", scannedAt)
            }
            if let scannedBy = outcome.scannedBy {
                labelled("Scanned by", scannedBy)
            }

            if let breakdown = outcome.breakdown, !breakdown.isEmpty {
                Divider()
                ForEach(breakdown, id: \.name) { row in
                    HStack {
                        Text(row.name)
                        Spacer()
                        Text("×\(row.quantity)")
                            .foregroundStyle(.secondary)
                    }
                    .font(.callout)
                }
            }
        }
        .padding()
        .background(Color(.secondarySystemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    private func labelled(_ label: String, _ value: String) -> some View {
        HStack(alignment: .firstTextBaseline) {
            Text(label)
                .font(.caption)
                .foregroundStyle(.secondary)
            Spacer()
            Text(value)
                .font(.callout)
                .multilineTextAlignment(.trailing)
        }
    }

    /// A human title for each status.
    private var statusTitle: String {
        switch outcome.status {
        case .checkedIn: return "Checked In"
        case .alreadyScanned: return "Already Scanned"
        case .rejected: return "Rejected"
        case .failed: return "Failed"
        case .invalid: return "Invalid"
        case .unreadable: return "Unreadable"
        }
    }

    /// Green for a fresh check-in, amber for a duplicate, red otherwise — so the
    /// door operator reads the result at a glance.
    private var statusColor: Color {
        switch outcome.status {
        case .checkedIn: return .green
        case .alreadyScanned: return .orange
        case .rejected, .failed, .invalid, .unreadable: return .red
        }
    }

    private var symbolName: String {
        switch outcome.status {
        case .checkedIn: return "checkmark.circle.fill"
        case .alreadyScanned: return "exclamationmark.triangle.fill"
        case .rejected, .failed, .invalid, .unreadable: return "xmark.octagon.fill"
        }
    }
}
