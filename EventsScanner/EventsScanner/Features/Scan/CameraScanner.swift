import AVFoundation
import SwiftUI
import Vision

/// The camera authorization states the scan screen branches on. Mirrors
/// `AVAuthorizationStatus` but collapses it to the three cases the UI cares
/// about: still-to-ask, usable, and refused.
///
/// On `.denied`/`.restricted` the screen shows "camera access is required" and
/// does NOT scan (Requirement 10.2); on `.notDetermined` the scanner requests
/// access on first need (Requirement 10.1); only `.authorized` runs the capture
/// session and decodes QR payloads (Requirement 10.3).
enum CameraPermission: Equatable {
    /// Permission has not been requested yet — the scanner asks on first need.
    case notDetermined
    /// The user granted camera access — the capture session may run.
    case authorized
    /// The user denied access (or it is restricted) — no scanning occurs and
    /// the "camera access is required" message is shown.
    case denied

    /// Collapse the platform authorization status into the three cases the
    /// scan UI branches on.
    init(status: AVAuthorizationStatus) {
        switch status {
        case .authorized:
            self = .authorized
        case .notDetermined:
            self = .notDetermined
        case .denied, .restricted:
            self = .denied
        @unknown default:
            self = .denied
        }
    }
}

/// Thin seam over `AVCaptureDevice` authorization so the permission flow is
/// expressed as behaviour. The real implementation forwards to AVFoundation;
/// the scan view model depends on this protocol rather than the framework type.
protocol CameraAuthorizing {
    /// The current camera authorization, mapped to `CameraPermission`.
    func current() -> CameraPermission
    /// Prompt for camera access (the system prompt on first ask) and return the
    /// resulting permission. A no-op that returns the current state when the
    /// decision has already been made.
    func request() async -> CameraPermission
}

/// Default `CameraAuthorizing` backed by `AVCaptureDevice`.
struct AVCameraAuthorizer: CameraAuthorizing {
    func current() -> CameraPermission {
        CameraPermission(status: AVCaptureDevice.authorizationStatus(for: .video))
    }

    func request() async -> CameraPermission {
        // Only `.notDetermined` triggers the system prompt; any other state is
        // already final and AVFoundation returns it unchanged.
        let granted = await AVCaptureDevice.requestAccess(for: .video)
        return granted ? .authorized : .denied
    }
}

/// A SwiftUI view that shows the live camera preview and emits decoded QR
/// strings while camera access is granted.
///
/// It wraps an `AVCaptureSession` whose video frames feed a Vision
/// `VNDetectBarcodesRequest` constrained to the `.qr` symbology (Requirement
/// 10.3). Each decoded payload string is handed to `onDecode`; the hosting view
/// model decides whether to submit it (debouncing duplicates) and NEVER
/// verifies or HMAC-checks the payload on device (Requirement 9.3) — the server
/// is the sole authority.
///
/// This view assumes access is already granted: the scan screen only renders it
/// in the `.authorized` state, so the preview and session never run under a
/// denied permission (Requirement 10.2).
@available(iOS 17.0, *)
struct CameraScanner: UIViewControllerRepresentable {
    /// Called on the main actor with each decoded QR payload string.
    let onDecode: (String) -> Void

    /// Whether the device torch (flashlight) should be on. Driven by the scan
    /// screen's torch toggle; the controller applies it to the capture device.
    var torchOn: Bool = false

    func makeUIViewController(context: Context) -> CameraScannerViewController {
        let controller = CameraScannerViewController()
        controller.onDecode = onDecode
        return controller
    }

    func updateUIViewController(_ controller: CameraScannerViewController, context: Context) {
        // Keep the latest closure so SwiftUI state captured by `onDecode` stays
        // current across view updates.
        controller.onDecode = onDecode
        controller.setTorch(torchOn)
    }

    static func dismantleUIViewController(
        _ controller: CameraScannerViewController,
        coordinator: Void
    ) {
        controller.stop()
    }
}

/// The UIKit controller that owns the `AVCaptureSession`, renders its preview,
/// and runs the Vision QR request over sampled frames.
///
/// Capture configuration and the session's start/stop run off the main thread
/// (AVFoundation requirement); decoded payloads are delivered back on the main
/// actor so SwiftUI state can be mutated safely.
final class CameraScannerViewController: UIViewController {

    /// Delivered on the main actor for each decoded QR payload.
    var onDecode: ((String) -> Void)?

    private let session = AVCaptureSession()
    private let sessionQueue = DispatchQueue(label: "com.events.scanner.camera.session")
    private let videoOutput = AVCaptureVideoDataOutput()
    private var previewLayer: AVCaptureVideoPreviewLayer?

    /// The active capture device, kept so the torch can be toggled on it.
    private var captureDevice: AVCaptureDevice?
    /// The last requested torch state, so `setTorch` is idempotent and cheap.
    private var torchIsOn = false

    /// The QR-only Vision request, built once and reused per frame.
    private lazy var barcodeRequest: VNDetectBarcodesRequest = {
        let request = VNDetectBarcodesRequest()
        request.symbologies = [.qr]
        return request
    }()

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .black
        configureSession()
    }

    override func viewDidLayoutSubviews() {
        super.viewDidLayoutSubviews()
        previewLayer?.frame = view.bounds
    }

    override func viewWillAppear(_ animated: Bool) {
        super.viewWillAppear(animated)
        start()
    }

    override func viewWillDisappear(_ animated: Bool) {
        super.viewWillDisappear(animated)
        stop()
    }

    /// Build the capture graph: the default video device as input and a sample
    /// buffer output feeding Vision. Runs on the session queue.
    private func configureSession() {
        sessionQueue.async { [weak self] in
            guard let self else { return }

            self.session.beginConfiguration()
            self.session.sessionPreset = .high

            guard
                let device = AVCaptureDevice.default(for: .video),
                let input = try? AVCaptureDeviceInput(device: device),
                self.session.canAddInput(input)
            else {
                self.session.commitConfiguration()
                return
            }
            self.session.addInput(input)
            self.captureDevice = device

            self.videoOutput.alwaysDiscardsLateVideoFrames = true
            self.videoOutput.setSampleBufferDelegate(
                self,
                queue: DispatchQueue(label: "com.events.scanner.camera.frames")
            )
            if self.session.canAddOutput(self.videoOutput) {
                self.session.addOutput(self.videoOutput)
            }

            self.session.commitConfiguration()

            // The preview layer must be created/attached on the main thread.
            DispatchQueue.main.async {
                let layer = AVCaptureVideoPreviewLayer(session: self.session)
                layer.videoGravity = .resizeAspectFill
                layer.frame = self.view.bounds
                self.view.layer.addSublayer(layer)
                self.previewLayer = layer
            }
        }
    }

    /// Start the capture session if it is not already running.
    func start() {
        sessionQueue.async { [weak self] in
            guard let self, !self.session.isRunning else { return }
            self.session.startRunning()
        }
    }

    /// Stop the capture session if it is running. Also turns the torch off so it
    /// never lingers on after the scanner closes.
    func stop() {
        setTorch(false)
        sessionQueue.async { [weak self] in
            guard let self, self.session.isRunning else { return }
            self.session.stopRunning()
        }
    }

    /// Turn the device torch on or off, when the device has one. Idempotent and
    /// safe to call before the device is configured (it no-ops until then).
    func setTorch(_ on: Bool) {
        sessionQueue.async { [weak self] in
            guard let self else { return }
            guard self.torchIsOn != on else { return }
            guard let device = self.captureDevice, device.hasTorch, device.isTorchAvailable else { return }

            do {
                try device.lockForConfiguration()
                if on {
                    try device.setTorchModeOn(level: AVCaptureDevice.maxAvailableTorchLevel)
                } else {
                    device.torchMode = .off
                }
                device.unlockForConfiguration()
                self.torchIsOn = on
            } catch {
                // A device without a controllable torch (or a transient lock
                // failure) simply leaves the torch as-is; the UI toggle is
                // best-effort and never blocks scanning.
            }
        }
    }
}

extension CameraScannerViewController: AVCaptureVideoDataOutputSampleBufferDelegate {
    /// Run the QR Vision request over each sampled frame and forward the first
    /// decoded payload string to `onDecode` on the main actor. The frame handler
    /// is intentionally lightweight — duplicate suppression and submission live
    /// in the view model (Requirement 10.3).
    func captureOutput(
        _ output: AVCaptureOutput,
        didOutput sampleBuffer: CMSampleBuffer,
        from connection: AVCaptureConnection
    ) {
        guard let pixelBuffer = CMSampleBufferGetImageBuffer(sampleBuffer) else { return }

        let handler = VNImageRequestHandler(cvPixelBuffer: pixelBuffer, options: [:])
        try? handler.perform([barcodeRequest])

        guard
            let results = barcodeRequest.results,
            let payload = results.compactMap(\.payloadStringValue).first,
            !payload.isEmpty
        else {
            return
        }

        DispatchQueue.main.async { [weak self] in
            self?.onDecode?(payload)
        }
    }
}
