// xcode: set sdk=iOS

import Foundation

/// Compile-time knowledge of which backend environment this build targets
/// (task 15.4).
///
/// The environment is baked into each build by `project.yml`: the Debug
/// configuration (preprod) is compiled with the `PREPROD` Swift active
/// compilation condition and points `API_HOST` at `preprod.events.ckent.uk`;
/// the Release configuration (production) has no such flag and points at
/// `events.ckent.uk`.
///
/// This is the *only* source of truth for the environment badge. It is derived
/// purely from the compile-time flag and never from any server response, so a
/// production build can never be made to look like preprod (or vice versa) by
/// anything the API returns. A production build does not even contain the
/// badge view (it is behind `#if PREPROD`).
enum AppEnvironment {

    /// `true` for the production (Release) build, `false` for preprod (Debug).
    ///
    /// Driven solely by the `PREPROD` compilation condition set per build
    /// configuration in `project.yml`.
    static var isProduction: Bool {
        #if PREPROD
        false
        #else
        true
        #endif
    }

    /// A short, user-visible label for non-production builds, or `nil` in
    /// production. Shown as an unmissable badge so a tester always knows which
    /// backend they are on.
    static var badgeLabel: String? {
        isProduction ? nil : "PREPROD"
    }
}
