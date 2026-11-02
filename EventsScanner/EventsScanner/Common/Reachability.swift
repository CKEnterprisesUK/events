import Foundation
import Network
import Observation

/// Proactive network-reachability observer for the EventsScanner client.
///
/// `Reachability` wraps an `NWPathMonitor` and exposes a single observable
/// `isOnline` flag so views can show an offline banner *before* a request is
/// even attempted. It complements — it does not replace — the reactive mapping
/// in `APIClient`, where a failed round trip already becomes `APIError.offline`
/// (Requirements 9.1).
///
/// ## What this type deliberately does NOT do
///
/// It does **not** queue, buffer, retry, or otherwise defer scans. Requirement
/// 9.2 requires every scan to be sent to the API in real time and forbids
/// queuing for later submission. `Reachability` is purely a messaging/UX signal:
/// it tells the UI whether the device currently has a network path, and nothing
/// here ever holds a pending scan. The scan path in `AuthStore.submitScan` sends
/// straight through `APIClient`; when offline it surfaces `APIError.offline`,
/// which the UI must never present as a successful check-in (Requirement 9.1).
///
/// Requires iOS 17 because it is `@Observable`, consistent with `AppState` and
/// the rest of the app (see `EventsScanner/README.md`). The deployment target is
/// iOS 16.0 (Requirement 11.1), hence the availability gate.
@available(iOS 17.0, *)
@Observable
final class Reachability {

    /// Whether the device currently has a usable network path to the internet.
    ///
    /// Starts optimistically `true` so the UI does not flash an offline banner
    /// during the first path evaluation; the monitor corrects it (typically
    /// within milliseconds) on the first update. Views bind to this to show or
    /// hide an offline banner. It is a *hint* for messaging only — the server
    /// remains the sole authority on whether a scan succeeded, and a `true`
    /// value here never implies a successful check-in.
    private(set) var isOnline: Bool = true

    /// The underlying path monitor. `@ObservationIgnored` because the monitor
    /// itself is not observable state — only the derived `isOnline` flag is.
    @ObservationIgnored
    private let monitor: NWPathMonitor

    /// A dedicated serial queue for path callbacks (required by `NWPathMonitor`).
    @ObservationIgnored
    private let queue: DispatchQueue

    /// Whether `start()` has already begun monitoring, so repeated calls are
    /// harmless.
    @ObservationIgnored
    private var isMonitoring: Bool = false

    /// Build a reachability observer. Monitoring does not begin until `start()`
    /// is called, so a caller controls when path callbacks start arriving
    /// (and tests can inject a monitor without a live network).
    ///
    /// - Parameters:
    ///   - monitor: the path monitor to observe (defaults to a fresh
    ///     `NWPathMonitor` over all interface types).
    ///   - queue: the serial queue path callbacks are delivered on.
    init(
        monitor: NWPathMonitor = NWPathMonitor(),
        queue: DispatchQueue = DispatchQueue(label: "com.events.scanner.reachability")
    ) {
        self.monitor = monitor
        self.queue = queue
    }

    /// Begin observing network path changes. Idempotent: calling it more than
    /// once has no additional effect. Each path update maps `path.status ==
    /// .satisfied` onto `isOnline`, hopping to the main actor so the observable
    /// mutation happens where SwiftUI expects it.
    func start() {
        guard !isMonitoring else { return }
        isMonitoring = true

        monitor.pathUpdateHandler = { [weak self] path in
            let online = path.status == .satisfied
            Task { @MainActor in
                self?.isOnline = online
            }
        }
        monitor.start(queue: queue)
    }

    /// Stop observing. Safe to call whether or not monitoring was started.
    func stop() {
        guard isMonitoring else { return }
        isMonitoring = false
        monitor.cancel()
    }

    deinit {
        monitor.cancel()
    }
}
