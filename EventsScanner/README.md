# EventsScanner (iOS)

Native Swift/SwiftUI client for the Laravel JSON API in this repository. It lets
event staff sign in, scan ticket QR codes, view a live check-in dashboard, and
search customers — all scoped to a selected company. See
`.kiro/specs/ios-scanner-app/` for requirements and design.

This directory is a sibling of the Laravel app (`app/`, `routes/`, …) and is a
self-contained Xcode project source tree.

## Status

Scaffold only (task 15.1): the app entry point and the top-level `AppState`.
Networking, auth, and feature screens are added in tasks 15.2 → 22.

## Layout

```
EventsScanner/
  project.yml                 # XcodeGen project definition (generates .xcodeproj)
  EventsScanner/
    Info.plist                # iOS 17 min OS, camera usage description
    App/
      EventsScannerApp.swift  # @main App + root view
      AppState.swift          # @Observable app-wide state
    Networking/               # APIClient, APIError, Endpoints, DTOs   (task 15.2)
    Auth/                     # AuthStore, KeychainStore, DeviceIdentity (16.1 / 17.1)
    Features/
      SignIn/ TwoFactor/ Company/ Scan/ Dashboard/ Customers/
    Common/                   # Reachability, shared views              (task 22.1)
  EventsScannerTests/         # XCTest targets                          (15.3 → 22.2)
```

## Opening / creating the Xcode project

A `.xcodeproj` is a generated bundle that cannot be reliably hand-authored, so
it is **not** checked in. Generate it from `project.yml` with
[XcodeGen](https://github.com/yonyz/XcodeGen):

```sh
brew install xcodegen
cd EventsScanner
xcodegen generate
open EventsScanner.xcodeproj
```

If you prefer not to use XcodeGen, create the project by hand in Xcode:

1. File → New → Project → iOS → App.
2. Product Name `EventsScanner`, Interface **SwiftUI**, Language **Swift**.
3. Set **Minimum Deployments** to **iOS 17.0**.
4. Delete the generated `ContentView.swift`/`App.swift`, then add the existing
   `EventsScanner/` source folders to the target.
5. Point the target's Info.plist at the checked-in `EventsScanner/Info.plist`
   (or copy over `NSCameraUsageDescription` and `MinimumOSVersion`).

> The build and run steps require the Xcode GUI and were **not** performed in
> this headless environment. The Swift sources were verified by review only.

## Switching environment (preprod vs production)

The API environment is a **build-time** choice baked into each build, never read
from the server (task 15.4). Two XcodeGen configurations drive it:

| Build configuration | Environment | `APIBaseURL` | Bundle id | Badge |
|---|---|---|---|---|
| **Debug** | preprod / staging | `https://preprod.events.ckent.uk` | `com.events.scanner.EventsScanner.preprod` | **PREPROD** banner shown |
| **Release** | production | `https://events.ckent.uk` | `com.events.scanner.EventsScanner` | none |

How it works:

- `project.yml` sets a per-configuration `API_HOST` build setting (plus a
  distinct `PRODUCT_BUNDLE_IDENTIFIER`). `Info.plist` resolves
  `APIBaseURL = https://$(API_HOST)`, so each build bakes in only its own URL —
  a production build can never contain the preprod URL and vice versa.
- Why `API_HOST` (host only) and not a full `API_BASE_URL`: an xcconfig-style
  build setting treats `//` as the start of a comment, which would truncate a
  value like `https://…`. Storing the **host only** and prepending `https://`
  in `Info.plist` sidesteps that pitfall entirely. The URL is the site **root**;
  the `/api/...` prefix already lives in the `Endpoint` paths, so nothing
  appends `/api`.
- The Debug configuration compiles with the `PREPROD` Swift active compilation
  condition. `App/AppEnvironment.swift` reads it (`#if PREPROD`) to expose
  `isProduction` / `badgeLabel`; `ObservableRootView` overlays an unmissable
  orange **PREPROD** banner for non-production builds. Production builds don't
  contain the badge at all. This is derived purely from the compile flag, never
  from any API response.

To pick an environment in Xcode:

1. `xcodegen generate && open EventsScanner.xcodeproj`.
2. **Product → Scheme → Edit Scheme…** (or hold ⌥ and click Run).
3. Set the **Build Configuration** for the action you're about to run:
   - **Run** tab → **Debug** to run/test against **preprod** (shows the PREPROD
     badge, installs as `…EventsScanner.preprod`).
   - **Archive** tab → **Release** to ship **production** (no badge, installs as
     `…EventsScanner`).

The distinct bundle ids let the preprod and production apps co-exist on one
device, so a tester can run preprod from Xcode while keeping the production app
installed from TestFlight.

> **TLS note:** HTTPS is enforced in `APIClient.init` (a non-HTTPS base throws
> `APIError.insecureBaseURL`) and no ATS exception is shipped. **Preprod must be
> served over real TLS with a valid certificate** — a self-signed preprod cert
> would require an ATS exception we deliberately do not add (it would weaken the
> production build too).

> The `.xcodeproj` is generated and gitignored; `project.yml`, `Info.plist`, and
> the Swift sources are the source of truth. Building/running/archiving requires
> the Xcode GUI and was **not** performed in this headless environment (only the
> Command Line Tools are installed). Settings were verified from the generated
> project and the Swift sources by `swiftc -parse` under both the PREPROD and
> production compile flags.

## iOS 17 minimum and `@Observable`

The app uses an `@Observable` `AppState` (the Observation framework macro),
which requires **iOS 17**. The minimum deployment target is therefore set to
**iOS 17.0** so `@Observable` and the full functional app are available
unconditionally — there is no iOS 16 placeholder fallback.

This is configured in:

- `project.yml` — `options.deploymentTarget.iOS`, `IPHONEOS_DEPLOYMENT_TARGET`,
  and each target's `deploymentTarget`, all `"17.0"`.
- `RootView` renders the real app directly; the former "Update Required"
  placeholder for iOS 16 has been removed.

Distribution (App Store / TestFlight) also requires the Xcode GUI and an Apple
Developer account.
