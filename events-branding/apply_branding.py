#!/usr/bin/env python3
"""Apply EventsScanner branding to a clean preprod checkout.
Run from repository root: python3 events-branding/apply_branding.py
Idempotent; refuses incompatible source rather than silently patching incorrectly.
"""
from pathlib import Path
import shutil, sys
here=Path(__file__).resolve().parent
repo=here.parent
app=repo/'EventsScanner/EventsScanner'
if not (repo/'EventsScanner/project.yml').exists():
    sys.exit('Run from a checkout of CKEnterprisesUK/events; place events-branding at repository root.')

def replace(path, old, new):
    data=path.read_text()
    if new in data: return
    if old not in data: sys.exit(f'Expected code not found: {path}; aborting without changing this file.')
    path.write_text(data.replace(old,new,1))

# Check compatibility before applying files.
checks={
    app/'App/EventsScannerApp.swift':'            RootView()\n',
    app/'Features/SignIn/SignInView.swift':'            Form {\n',
    app/'Features/Scan/ScanView.swift':'        case .checkedIn: return .green',
    app/'Features/Dashboard/DashboardView.swift':'    private var idle: some View {',
    repo/'EventsScanner/project.yml':'    sources:\n      - path: EventsScanner',
    app/'Info.plist':'	<key>CFBundleDevelopmentRegion</key>',
}
for path, snippet in checks.items():
    if not path.exists() or (snippet not in path.read_text() and 'Brand' not in path.read_text()):
        sys.exit(f'Incompatible source file: {path}')

for src in (here/'EventsScanner/EventsScanner/Assets.xcassets').rglob('*'):
    if src.is_file():
        dst=app/'Assets.xcassets'/src.relative_to(here/'EventsScanner/EventsScanner/Assets.xcassets')
        dst.parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(src,dst)
shutil.copy2(here/'EventsScanner/EventsScanner/Common/Brand.swift',app/'Common/Brand.swift')
shutil.copy2(here/'EventsScanner/EventsScanner/LaunchScreen.storyboard',app/'LaunchScreen.storyboard')

p=app/'App/EventsScannerApp.swift'
replace(p,'            RootView()\n','            RootView()\n                .tint(Brand.accent)\n')
p=app/'Features/SignIn/SignInView.swift'
replace(p,'            Form {\n',"""            Form {
                Section {
                    VStack(spacing: 10) {
                        Image("BrandLogo")
                            .resizable()
                            .scaledToFit()
                            .frame(width: 94, height: 94)
                            .clipShape(RoundedRectangle(cornerRadius: 20))
                            .accessibilityHidden(true)
                        Text("Events by CK Enterprises")
                            .font(.title2.bold())
                            .multilineTextAlignment(.center)
                        Text("Secure event check-in")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 18)
                }
""")
p=app/'Features/Scan/ScanView.swift'
replace(p,'        case .checkedIn: return .green','        case .checkedIn: return Brand.emerald')
replace(p,'        case .alreadyScanned: return .orange','        case .alreadyScanned: return Brand.amber')
replace(p,'        case .rejected, .failed, .invalid, .unreadable: return .red','        case .rejected, .failed, .invalid, .unreadable: return Brand.danger')
replace(p,'            .foregroundStyle(.white)\n            .padding()\n            .frame(maxWidth: .infinity, alignment: .leading)\n            .background(statusColor)','            .foregroundStyle(outcome.status == .checkedIn || outcome.status == .alreadyScanned ? Brand.navy : .white)\n            .padding()\n            .frame(maxWidth: .infinity, alignment: .leading)\n            .background(statusColor)')
# Scan haptic triggered only after server returns a successful check-in.
replace(p,'import SwiftUI\n','import SwiftUI\nimport UIKit\n')
replace(p,'            outcome = result\n',"""            outcome = result
            if result.status == .checkedIn {
                UINotificationFeedbackGenerator().notificationOccurred(.success)
            }
""")
p=app/'Features/Dashboard/DashboardView.swift'
old="""        VStack(spacing: 12) {
            Image(systemName: "calendar.badge.clock")
                .font(.largeTitle)
                .foregroundStyle(.secondary)
            Text("No active event")
                .font(.headline)
            Text("The live dashboard will populate when an event is in progress.")
                .font(.callout)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
"""
new="""        VStack(spacing: 12) {
            BrandEmptyState(
                symbol: "calendar.badge.clock",
                title: "No active event",
                message: "The live dashboard will populate when an event is in progress."
            )
"""
replace(p,old,new)
p=repo/'EventsScanner/project.yml'
replace(p,'    info:\n      path: EventsScanner/Info.plist','    info:\n      path: EventsScanner/Info.plist') # no-op safe
# XcodeGen sources auto-discover Swift and storyboard under EventsScanner.
p=app/'Info.plist'
replace(p,'	<key>CFBundleDevelopmentRegion</key>','	<key>UILaunchStoryboardName</key>\n	<string>LaunchScreen</string>\n	<key>CFBundleDevelopmentRegion</key>')
print('Branding applied. Next: cd EventsScanner && xcodegen generate && open EventsScanner.xcodeproj')
