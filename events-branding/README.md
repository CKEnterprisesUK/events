# EventsScanner Branding Overlay — preprod

Applies to `CKEnterprisesUK/events`, branch `preprod`, path `EventsScanner/`.

1. Extract this `events-branding` directory into the **repository root** (next to `EventsScanner`).
2. Run `python3 events-branding/apply_branding.py` from the repository root.
3. Run `cd EventsScanner && xcodegen generate && open EventsScanner.xcodeproj`.
4. Build and test on an iOS 17+ simulator/device. The existing source has an iOS 16 deployment target but relies on Observation APIs from iOS 17.

The installer changes existing source files locally, and the overlay includes the brand assets, launch screen, and shared SwiftUI components. No repository changes have been pushed. Make a clean git commit before running the installer so the patch can be reviewed or reverted.

## Modifications
- Dynamic light/dark colours and AccentColor in Assets.xcassets
- Common/Brand.swift and reusable BrandEmptyState
- Branded Sign In header
- Navy launch screen with app icon and Info.plist launch reference
- Branded scanner result colours with readable foreground and success haptic
- Branded Dashboard empty state
- Root tint set to AccentColor

All API, Keychain, camera permission and backend validation logic remain unchanged. The included logo is the icon concept generated in this conversation; replace BrandLogo.png if you have a final production logo. Xcode builds were not run in this environment.
