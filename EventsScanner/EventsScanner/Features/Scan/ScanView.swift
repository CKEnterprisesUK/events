import SwiftUI
import UIKit

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
            if result.status == .checkedIn {
                UINotificationFeedbackGenerator().notificationOccurred(.success)
            }
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
    @Environment(AppState.self) private var appState
    @StateObject private var model: ScanViewModel
    @State private var showingManualEntry = false
    @State private var torchOn = false

    init(auth: AuthStore) {
        _model = StateObject(wrappedValue: ScanViewModel(submitter: auth))
    }

    /// Test/preview seam allowing an injected submitter + authorizer.
    init(submitter: ScanSubmitting, authorizer: CameraAuthorizing) {
        _model = StateObject(wrappedValue: ScanViewModel(submitter: submitter, authorizer: authorizer))
    }

    var body: some View {
        NavigationStack {
            Group {
                if let event = appState.selectedEvent {
                    scanner(for: event)
                } else {
                    noEventSelected
                }
            }
            .navigationBarTitleDisplayMode(.inline)
            .brandNavigationTitle()
            .sheet(isPresented: $showingManualEntry) {
                ManualEntryView { reference in
                    await model.submitManual(reference: reference)
                }
            }
            .onChange(of: torchOn) { _, _ in } // keeps SwiftUI observing the toggle
            .onDisappear { torchOn = false }
        }
        .task { await model.onAppear() }
    }

    /// The full-bleed scanner: the camera fills the screen with a reticle in the
    /// centre, a compact event banner up top, a torch toggle, and the outcome as
    /// a bottom card — no wasted blank space.
    private func scanner(for event: EventDTO) -> some View {
        ZStack {
            Color.black.ignoresSafeArea(edges: .bottom)

            cameraRegion
                .ignoresSafeArea(edges: .bottom)

            // Dim + reticle overlay, only while the live camera is up.
            if model.isCameraActive {
                reticleOverlay
            }

            VStack(spacing: 0) {
                selectedEventBanner(event)
                Spacer()
                bottomBar
            }
        }
    }

    /// A translucent target square with corner marks to aim the QR into.
    private var reticleOverlay: some View {
        GeometryReader { geo in
            let side = min(geo.size.width, geo.size.height) * 0.62
            ZStack {
                RoundedRectangle(cornerRadius: 24)
                    .stroke(.white.opacity(0.9), lineWidth: 3)
                    .frame(width: side, height: side)
                    .shadow(color: .black.opacity(0.4), radius: 6)
                RoundedRectangle(cornerRadius: 24)
                    .stroke(Brand.emerald, lineWidth: 3)
                    .frame(width: side, height: side)
                    .opacity(model.isSubmitting ? 1 : 0.0)
            }
            .frame(width: geo.size.width, height: geo.size.height)
        }
        .allowsHitTesting(false)
        .accessibilityHidden(true)
    }

    /// A slim, glassy banner naming the event being scanned for, with a quick
    /// "Change" to return to event selection.
    private func selectedEventBanner(_ event: EventDTO) -> some View {
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text("Scanning for")
                    .font(.caption2)
                    .foregroundStyle(.white.opacity(0.7))
                Text(event.name)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(.white)
                    .lineLimit(1)
            }
            Spacer()
            Button("Change") { appState.clearSelectedEvent() }
                .font(.footnote.weight(.semibold))
                .foregroundStyle(Brand.emerald)
                .accessibilityIdentifier("scan.changeEvent")
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .background(.ultraThinMaterial)
    }

    /// The bottom controls: either the live outcome card, or the idle prompt
    /// with the torch toggle and manual-entry button.
    @ViewBuilder
    private var bottomBar: some View {
        VStack(spacing: 12) {
            if model.isSubmitting {
                ProgressView("Checking in…")
                    .tint(.white)
                    .foregroundStyle(.white)
            }

            if let errorMessage = model.errorMessage {
                Label(errorMessage, systemImage: "wifi.slash")
                    .font(.callout)
                    .foregroundStyle(.white)
                    .padding(12)
                    .frame(maxWidth: .infinity)
                    .background(Brand.danger.opacity(0.9), in: RoundedRectangle(cornerRadius: 12))
                    .accessibilityIdentifier("scan.error")
            }

            if let outcome = model.outcome {
                ScanOutcomeCard(outcome: outcome)
                Button {
                    model.reset()
                } label: {
                    Text("Scan next")
                        .fontWeight(.semibold)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)
                }
                .background(Brand.emerald)
                .foregroundStyle(Brand.navy)
                .clipShape(RoundedRectangle(cornerRadius: 12))
                .accessibilityIdentifier("scan.next")
            } else if !model.isSubmitting && model.errorMessage == nil {
                Text("Point the camera at a ticket QR code")
                    .font(.callout.weight(.medium))
                    .foregroundStyle(.white)
            }

            controlRow
        }
        .padding(16)
        .background(
            LinearGradient(colors: [.clear, .black.opacity(0.7)], startPoint: .top, endPoint: .bottom)
                .ignoresSafeArea(edges: .bottom)
        )
    }

    /// Torch toggle + manual entry, side by side.
    private var controlRow: some View {
        HStack(spacing: 12) {
            Button {
                torchOn.toggle()
            } label: {
                Label(torchOn ? "Light on" : "Light off",
                      systemImage: torchOn ? "flashlight.on.fill" : "flashlight.off.fill")
                    .font(.callout.weight(.medium))
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
            }
            .background(.white.opacity(torchOn ? 0.9 : 0.15))
            .foregroundStyle(torchOn ? Brand.navy : .white)
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .accessibilityIdentifier("scan.torch")

            Button {
                showingManualEntry = true
            } label: {
                Label("Enter reference", systemImage: "keyboard")
                    .font(.callout.weight(.medium))
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
            }
            .background(.white.opacity(0.15))
            .foregroundStyle(.white)
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .accessibilityIdentifier("scan.manualEntry")
        }
    }

    /// Shown when no event is selected: scanning is scoped to a chosen event, so
    /// the operator picks one on the Events tab first.
    private var noEventSelected: some View {
        VStack(spacing: 16) {
            BrandEmptyState(
                symbol: "calendar.badge.exclamationmark",
                title: "Choose an event to scan",
                message: "Open the Events tab, pick an event, and tap “Scan” to check people in for it."
            )
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .accessibilityIdentifier("scan.noEvent")
    }

    /// The camera (granted) or the "camera access is required" state (denied),
    /// with a neutral prompt while the decision is pending.
    @ViewBuilder
    private var cameraRegion: some View {
        if model.isCameraActive {
            CameraScanner(
                onDecode: { payload in
                    Task { await model.handleDecoded(payload: payload) }
                },
                torchOn: torchOn
            )
        } else if model.isCameraDenied {
            VStack(spacing: 12) {
                Image(systemName: "camera.fill").font(.largeTitle).foregroundStyle(.white)
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
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color.black)
        } else {
            VStack(spacing: 12) {
                ProgressView().tint(.white)
                Text("Preparing camera…")
                    .font(.caption)
                    .foregroundStyle(.white.opacity(0.7))
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color.black)
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
            .foregroundStyle(outcome.status == .checkedIn || outcome.status == .alreadyScanned ? Brand.navy : .white)
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
        case .checkedIn: return Brand.emerald
        case .alreadyScanned: return Brand.amber
        case .rejected, .failed, .invalid, .unreadable: return Brand.danger
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
