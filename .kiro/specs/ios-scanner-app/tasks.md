# Implementation Plan: iOS Scanner App

## Overview

The work lands in two sequenced stages. The Laravel JSON API ships first because it unblocks the client: Sanctum auth, a token-driven tenant binding, the extracted shared check-in decision tree, and the role-gated scan/dashboard/customer endpoints. Only then does the native Swift/SwiftUI iOS app follow, consuming a stable contract.

Each task is incremental and builds on the previous one, ending with wiring that leaves no orphaned code. Backend tasks carry PHPUnit feature tests for every path (mirroring the existing `ScanCheckInTest` conventions). The ten correctness properties from the design are covered by property-focused tests where they bite hardest: the scan decision tree (Property 1), atomic concurrency (Property 2), manual/scan equivalence (Property 3), ScanEvent recording (Property 4), tenant isolation (Property 5), role gating (Property 6), token-issued-iff (Property 7), device trust (Property 8), membership (Property 9), and revocation (Property 10).

iOS build, signing, and distribution steps that require a physical device, an Apple Developer account, or the Xcode GUI are marked optional/manual because they cannot run headless in CI.

## Tasks

- [ ] 1. Install and configure Sanctum and the API surface
  - [ ] 1.1 Install Sanctum and register the API routing + middleware groups
    - `composer require laravel/sanctum`; publish sanctum config; run the `personal_access_tokens` migration
    - Add `api: __DIR__.'/../routes/api.php'` to `withRouting` in `bootstrap/app.php` and create an empty `routes/api.php`
    - In `withMiddleware`, prepend `EnforceHttps` to the `api` group and register the `api.tenant` group (`EnforceTenantScope` + `ResolveApiTenant` placeholder)
    - Add the `Laravel\Sanctum\HasApiTokens` trait to `App\Models\User`
    - Declare named `login` and `2fa` rate limiters
    - _Requirements: 12.2, 3.3, 12.5_

  - [ ] 1.2 Add the EnforceHttps middleware
    - Create `App\Http\Middleware\EnforceHttps` rejecting any non-TLS request on the API group before any handler runs
    - _Requirements: 12.2_

  - [ ]* 1.3 Write feature test for TLS enforcement
    - Assert a non-TLS request to an API route is rejected and a TLS request passes through
    - _Requirements: 12.2_

- [ ] 2. Membership, device-trust, and token data models
  - [ ] 2.1 Create the company_user membership pivot and User::companies relation
    - Migration for `company_user` (`company_id`, `user_id`, `role`, timestamps) with a unique index on `(company_id, user_id)`
    - Add `User::companies(): BelongsToMany` carrying the per-company `role`
    - Data backfill seeding one membership row from each existing `(user.company_id, user.role)` so current users keep working
    - _Requirements: 4.2, 4.3, 4.4, 4.5, 4.6_

  - [ ]* 2.2 Write feature test for membership backfill and relation
    - Assert every existing user gains a membership mirroring `(company_id, role)` and `companies()` returns it
    - _Requirements: 4.2_

  - [ ] 2.3 Create the trusted_devices table and TrustedDevice model
    - Migration (`user_id`, `device_id` indexed, `name` null, `trusted_at`, `last_used_at` null, timestamps) with a unique index on `(user_id, device_id)`
    - Add the `TrustedDevice` model with the `user` relation
    - _Requirements: 2.1, 2.4_

  - [ ] 2.4 Add current_company_id to personal access tokens
    - Migration adding a nullable `current_company_id` column to `personal_access_tokens` so a token carries its Active_Company
    - _Requirements: 4.3, 4.4_

- [ ] 3. Token-driven tenant binding
  - [ ] 3.1 Implement ResolveApiTenant middleware
    - Read the token's `current_company_id`, verify the user is a member via `company_user`, bind it with `TenantContext::setCompany()`; `EnforceTenantScope` clears it after the response
    - Mirror `ResolveDashboardTenant` behaviour but drive the company from the token rather than `user.company_id`; leave the web path untouched
    - _Requirements: 4.1, 4.3, 12.3_

  - [ ]* 3.2 Write feature test for tenant isolation at the middleware layer
    - **Property 5: Tenant scope confines every API response**
    - **Validates: Requirements 4.1, 12.3, 7.4**
    - Assert a token scoped to company A cannot read or mutate company B's tenant-scoped records

- [ ] 4. Extract the shared TicketCheckInService
  - [ ] 4.1 Extract the decision tree into TicketCheckInService
    - Move `ScanController`'s `resolve`/`checkIn`/`alreadyScanned`/`breakdown` and `ScanEvent` recording into `App\Services\TicketCheckInService`, unchanged in behaviour
    - Pass the operator identity in (replace `Auth` reads with an `Operator` argument); introduce the `ScanResult` structured outcome
    - _Requirements: 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 5.10, 6.1, 6.2_

  - [ ] 4.2 Refactor the web ScanController to call the service
    - Make `ScanController::scan` a thin adapter: build an `Operator` from `Auth::user()`, call the service, keep the existing session-recap/HTML-fragment rendering
    - _Requirements: 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 5.10_

  - [ ]* 4.3 Write property test for the Scan_Outcome decision tree
    - **Property 1: QR payload resolves to the correct Scan_Outcome**
    - **Validates: Requirements 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 6.2**
    - One case per outcome: `unreadable`, `invalid`, `rejected`, `failed`, `already_scanned`, `checked_in`

  - [ ]* 4.4 Write property test for atomic concurrency
    - **Property 2: Check-in is atomic under concurrency**
    - **Validates: Requirements 5.8, 5.9**
    - Drive many confirmed/unscanned Orders with concurrent attempts; assert exactly one `checked_in` and the rest `already_scanned` carrying the winner's `scanned_at`

  - [ ]* 4.5 Write property test for manual/scan equivalence
    - **Property 3: Manual reference and scanned payload are equivalent**
    - **Validates: Requirements 6.1, 5.8**
    - Submitting an Order_Reference manually (any letter case) and its QR_Payload both yield `checked_in` with the same breakdown

  - [ ]* 4.6 Write property test for ScanEvent recording
    - **Property 4: Every resolved check-in records exactly one Scan_Event**
    - **Validates: Requirements 5.10**
    - Exactly one `scan_events` row per resolved request under the Active_Company with the correct outcome mapping

  - [ ]* 4.7 Write regression tests proving web scanner behaviour is unchanged
    - Re-run the existing web scan scenarios through the refactored controller and assert identical outcomes
    - _Requirements: 5.3, 5.4, 5.5, 5.6, 5.7, 5.8_

- [ ] 5. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 6. Authentication, device trust, and token issuance
  - [ ] 6.1 Implement DeviceTrustService
    - `isTrusted`, `trust`, and `forget` over the `trusted_devices` table keyed on `(user, device_id)`
    - _Requirements: 2.1, 2.4_

  - [ ] 6.2 Implement AuthController login, two-factor, logout, me
    - `login`: validate with `Auth::validate()` (no session); issue a token when 2FA disabled or device trusted, else respond `2fa_required` with no token
    - `two-factor`: re-validate credentials, verify via `TwoFactorAuthenticationService::verifyTotp()`/`useRecoveryCode()`, trust the device and issue a long-lived token on success, 422 with no token on failure
    - Encode token abilities from `RoleAuthorization::permittedActions($user->role)` plus a `company:{id}` ability
    - `logout`: delete the current access token; `me`: return user, `permitted_actions`, and memberships
    - Audit failed login/2FA via the existing `AuditLogger`; apply `throttle:login`/`throttle:2fa`
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 2.1, 2.2, 2.3, 2.4, 3.1, 3.3_

  - [ ]* 6.3 Write property test for token-issued-iff
    - **Property 7: A token is issued only when authentication fully succeeds**
    - **Validates: Requirements 1.1, 1.2, 1.3, 1.4, 2.2, 2.3**
    - Token issued iff credentials valid AND (2FA disabled OR device trusted OR valid TOTP/recovery supplied); no token in every other case

  - [ ]* 6.4 Write property test for device trust lifecycle
    - **Property 8: Device trust is established only by a passed challenge and then persists**
    - **Validates: Requirements 2.1, 2.4, 1.4**
    - A device becomes trusted only after a successful TOTP challenge; later logins from that device skip the challenge

  - [ ]* 6.5 Write property test for revoked-token rejection
    - **Property 10: Revoked tokens are universally rejected**
    - **Validates: Requirements 3.1, 3.3, 12.5**
    - After logout, every subsequent request presenting the token is rejected with an authentication error

- [ ] 7. Company selection and switching
  - [ ] 7.1 Implement CompanyController list and select
    - `GET /api/companies` lists the user's memberships from `company_user`
    - `POST /api/companies/{id}/select` enforces membership, sets `current_company_id`, and re-mints the token's `company:{id}` ability; non-member → 403 with Active_Company unchanged
    - _Requirements: 4.2, 4.3, 4.4, 4.5, 4.6_

  - [ ]* 7.2 Write property test for membership-respecting selection
    - **Property 9: Company selection respects membership**
    - **Validates: Requirements 4.3, 4.4, 4.5**
    - Member selection sets the Active_Company and re-scopes; non-member selection is rejected and leaves it unchanged

- [ ] 8. Scan endpoint
  - [ ] 8.1 Implement ScanApiController
    - `POST /api/scan` gated with `Gate::authorize(RoleAuthorization::ACTION_CHECK_IN)`; accepts `payload` or `reference`, funnels both through `TicketCheckInService::resolve`, serialises `ScanResult` to the Scan_Outcome JSON contract
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 5.9, 5.10, 6.1, 6.2_

  - [ ]* 8.2 Write feature tests mapping every outcome to the JSON contract
    - Assert each status (`checked_in` with breakdown, `already_scanned` with scanned_at/operator, `rejected`, `failed`, `invalid`, `unreadable`) returns 200 with the contract shape
    - _Requirements: 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 6.2_

  - [ ]* 8.3 Write feature test for role gating on scan
    - **Property 6: Role gating is total and side-effect-free on denial**
    - **Validates: Requirements 5.1, 5.2, 7.1, 7.5, 8.1, 8.3**
    - A role without `check_in` is denied 403 and no data changes

- [ ] 9. Live dashboard endpoint
  - [ ] 9.1 Extract EventSnapshotService and implement DashboardApiController
    - Extract the snapshot logic from `EventLiveController::snapshot()` into `App\Services\EventSnapshotService`; refactor the web controller to use it
    - `GET /api/dashboard/live` gated on `ACTION_CHECK_IN`, resolves the Active_Event tenant-scoped, returns scanned/sold/remaining/rate + recent `ScanEvent`s and omits sales/revenue figures
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

  - [ ]* 9.2 Write feature tests for the snapshot contract and gating
    - Assert the snapshot is Active_Event-only, excludes revenue, and that a role without `check_in` is denied 403
    - _Requirements: 7.2, 7.3, 7.4, 7.5_

- [ ] 10. Customer search endpoint
  - [ ] 10.1 Implement CustomerApiController
    - `GET /api/customers?q=term` gated on `ACTION_MANAGE_ORDERS`, reusing `CustomerController::index`'s aggregate query (name/email search, order count, confirmed spend) under the tenant scope
    - _Requirements: 8.1, 8.2, 8.3_

  - [ ]* 10.2 Write feature tests for customer search and gating
    - Assert matching rows carry order count and confirmed spend; a role without `orders` is denied 403; no cross-company rows leak
    - _Requirements: 8.1, 8.2, 8.3, 12.3_

- [ ] 11. Backend checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 12. iOS app scaffold and networking core
  - [ ] 12.1 Create the Xcode project and app state
    - Scaffold `EventsScanner` with an iOS 16.0 deployment target, `EventsScannerApp.swift`, and an `@Observable` `AppState`
    - _Requirements: 11.1_

  - [ ] 12.2 Implement APIClient, APIError, Endpoints, and DTOs
    - `APIClient` sets `Authorization: Bearer <token>` and `X-Device-Id`, enforces an HTTPS base URL, decodes `Codable` DTOs, and maps non-2xx to `APIError` (offline, unauthorized, forbidden, serverMessage)
    - Define Codable DTOs for every Scan_Outcome, the live snapshot, auth responses, and memberships
    - _Requirements: 3.2, 3.3, 9.3, 12.2_

  - [ ]* 12.3 Write XCTest for Scan_Outcome DTO decoding
    - Round-trip each Scan_Outcome JSON into its model and assert the status mapping
    - _Requirements: 5.3, 5.4, 5.5, 5.6, 5.7, 5.8_

- [ ] 13. iOS secure storage and device identity
  - [ ] 13.1 Implement KeychainStore and DeviceIdentity
    - `KeychainStore` persists only the Access_Token (`kSecClassGenericPassword`, device-only accessibility); never stores the password
    - `DeviceIdentity` generates a UUID once, stores it in the Keychain, and returns it stably across launches/reinstalls
    - _Requirements: 12.1, 12.4, 2.1, 2.4_

  - [ ]* 13.2 Write XCTest for KeychainStore and DeviceIdentity
    - Store/read/delete the token (absent after logout); assert the device id is stable across calls and a reinstall simulation
    - _Requirements: 3.2, 12.1, 2.1, 2.4_

- [ ] 14. iOS sign-in, two-factor, and company selection flow
  - [ ] 14.1 Implement SignIn, TwoFactor, and CompanyPicker
    - `SignInView` posts credentials + `device_id`; on `2fa_required` route to `TwoFactorView`; on token, store it and continue
    - `CompanyPickerView` shows only when memberships > 1; auto-select and skip the picker for a single membership
    - On 401 the client clears the Keychain token and routes back to sign-in
    - _Requirements: 1.1, 1.3, 2.2, 3.2, 4.2, 4.6_

  - [ ]* 14.2 Write XCTest for single-vs-multi membership routing and 401 handling
    - Assert single membership auto-selects without a picker; multi shows the picker; a 401 clears the token and returns to sign-in
    - _Requirements: 4.2, 4.6, 3.2_

- [ ] 15. iOS scan feature
  - [ ] 15.1 Implement CameraScanner, manual entry, and scan submission
    - `CameraScanner` requests camera permission; on denial shows "camera access is required" and does not scan; while granted runs `AVCaptureSession` feeding `VNDetectBarcodesRequest` (`.qr`)
    - Submit a decoded payload to `/api/scan` and the always-available `ManualEntryView` reference as a fallback; render the returned Scan_Outcome
    - No HMAC/verification on device
    - _Requirements: 6.3, 9.2, 9.3, 10.1, 10.2, 10.3, 10.4_

  - [ ]* 15.2 Manual/device test for camera permission and Vision QR decode
    - Verify permission prompt, denial messaging, and live QR decode on a physical device (not unit-testable without hardware)
    - _Requirements: 10.1, 10.2, 10.3_

- [ ] 16. iOS dashboard feature
  - [ ] 16.1 Implement DashboardView polling the live snapshot
    - `DashboardView` + ViewModel polls `/api/dashboard/live` and renders scanned/sold/remaining/rate and recent scan events for the Active_Event
    - _Requirements: 7.1, 7.2_

- [ ] 17. iOS customer search with server-driven role visibility
  - [ ] 17.1 Implement CustomerSearchView gated by permitted actions
    - Drive tab visibility from `/api/auth/me` `permitted_actions`: show Scan + Dashboard when `check_in` is present, show Customer Search only when `orders` is present and hide it otherwise
    - `CustomerSearchView` + ViewModel calls `/api/customers?q=`
    - _Requirements: 8.4, 8.5_

  - [ ]* 17.2 Write XCTest for role-driven tab visibility
    - Given `permitted_actions`, assert the tab set shows/hides Customer Search correctly
    - _Requirements: 8.4, 8.5_

- [ ] 18. iOS offline handling and distribution
  - [ ] 18.1 Implement offline detection and error messaging
    - `Reachability` + a failed request surface an offline message and never report a successful check-in; scans are not queued
    - Map `APIError` cases to user messaging (offline, unauthorized → sign-in, forbidden → hide/deny, serverMessage)
    - _Requirements: 9.1, 9.2, 3.2_

  - [ ]* 18.2 Write XCTest for offline mapping
    - A network failure maps to `.offline` and reports no success
    - _Requirements: 9.1, 9.2_

  - [ ]* 18.3 Wire App Store / TestFlight distribution (manual)
    - Configure signing and submit via App Store / TestFlight; requires an Apple Developer account and the Xcode GUI (cannot run headless in CI)
    - _Requirements: 11.2_

- [ ] 19. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP; they include unit, feature, property, and manual/device tests.
- The backend (tasks 1–11) ships first and fully unblocks the iOS client (tasks 12–19).
- Each task references specific granular requirements for traceability, and checkpoints ensure incremental validation.
- Property tests cover the ten design properties: Property 1 (4.3), Property 2 (4.4), Property 3 (4.5), Property 4 (4.6), Property 5 (3.2), Property 6 (8.3), Property 7 (6.3), Property 8 (6.4), Property 9 (7.2), Property 10 (6.5).
- iOS camera/Vision verification (15.2) and distribution (18.3) require a physical device, an Apple Developer account, or the Xcode GUI and are marked manual/optional since they cannot run headless in CI.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2", "2.1", "2.3", "2.4"] },
    { "id": 2, "tasks": ["1.3", "2.2", "3.1", "4.1"] },
    { "id": 3, "tasks": ["3.2", "4.2", "6.1"] },
    { "id": 4, "tasks": ["4.3", "4.4", "4.5", "4.6", "4.7", "6.2"] },
    { "id": 5, "tasks": ["6.3", "6.4", "6.5", "7.1", "8.1", "9.1", "10.1"] },
    { "id": 6, "tasks": ["7.2", "8.2", "8.3", "9.2", "10.2"] },
    { "id": 7, "tasks": ["12.1"] },
    { "id": 8, "tasks": ["12.2", "13.1"] },
    { "id": 9, "tasks": ["12.3", "13.2", "14.1"] },
    { "id": 10, "tasks": ["14.2", "15.1", "16.1", "17.1", "18.1"] },
    { "id": 11, "tasks": ["15.2", "17.2", "18.2", "18.3"] }
  ]
}
```
