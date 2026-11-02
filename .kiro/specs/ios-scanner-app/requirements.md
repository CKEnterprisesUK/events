# Requirements Document

## Introduction

This feature delivers two coordinated deliverables: a new Laravel JSON API and a native Swift/SwiftUI iOS app that together let event staff sign in, scan tickets, view a high-level live check-in dashboard for the active event, and search customer records. The API is added to the existing Laravel platform (Laravel ^13.17, PHP ^8.3) which today serves only session-based web auth and has no `routes/api.php` and no Sanctum. The API introduces Laravel Sanctum personal access tokens with a per-device TOTP two-factor challenge, scopes every request to a selected company exactly as the web `dashboard.tenant` middleware and `company_id` global scope do, and reuses the existing server-side behaviours: the `ScanController` resolve/check-in decision tree, the `QrService` HMAC verification, the `EventLiveController::feed()` live snapshot, the `CustomerController::index` tenant-scoped customer search, and the `RoleAuthorization` permission matrix. Scanning is online-only: every scan reaches the API in real time and all verification and atomic check-in logic stays server-side. The iOS app owns camera access, on-device QR decoding via the Vision framework, secure token storage, and a manual order-reference fallback.

## Glossary

- **API**: The new Laravel JSON HTTP interface added under a dedicated route file for the iOS app.
- **iOS_App**: The native Swift/SwiftUI client application for iOS.
- **Staff_User**: An authenticated user record in the existing `users` table holding one of the company roles (Owner, Admin, Box_Office, Accountant, Scanner).
- **Access_Token**: A Laravel Sanctum personal access token issued to the iOS_App and used to authenticate subsequent API requests.
- **Device**: A single physical iOS device, identified to the API by a stable client-supplied device identifier associated with issued tokens.
- **Trusted_Device**: A Device that has previously completed a TOTP challenge for a Staff_User and is recorded as trusted, so later sign-ins from that Device skip the TOTP challenge.
- **TOTP_Challenge**: A time-based one-time password verification step using the existing pragmarx/google2fa-qrcode integration, required for Staff_Users who have two-factor authentication enabled.
- **Company**: A tenant record in the `companies` table that owns events, orders, ticket types, customers and scan events.
- **Active_Company**: The single Company a given Access_Token and its requests are scoped to at a point in time.
- **Tenant_Scope**: The server-side enforcement equivalent to the web `dashboard.tenant` middleware plus the `company_id` global scope, confining every query and mutation to the Active_Company.
- **Active_Event**: The event the iOS_App dashboard reports on; the event currently selected or in progress for the Active_Company.
- **Order_Reference**: A 12-character uppercase `[A-Z0-9]` identifier for an order that never contains a `.`.
- **QR_Payload**: The scannable `reference.token` string produced by `QrService`, where `token` is the HMAC-SHA256 of the Order_Reference under the platform `qr.hmac_secret`.
- **Scan_Outcome**: One of the server-determined results of a check-in attempt: checked_in, already_scanned, rejected, failed, invalid, or unreadable.
- **Scan_Event**: A row recorded in the `scan_events` table capturing an operator's check-in attempt and its Scan_Outcome.
- **ACTION_CHECK_IN**: The RoleAuthorization action permitting ticket check-in (held by Owner, Admin, Box_Office, Scanner).
- **ACTION_MANAGE_ORDERS**: The RoleAuthorization action permitting order and customer management (held by Owner, Admin, Box_Office, Accountant).
- **ACTION_MANAGE_USERS**: The RoleAuthorization action permitting team membership management (held by Owner and Admin) used to gate team-level Access_Token revocation.
- **Keychain**: The iOS secure credential store used by the iOS_App to persist the Access_Token.
- **Token_Lifetime**: The configured maximum duration for which an Access_Token remains valid after issuance, after which the Access_Token is treated as expired.
- **Session**: A pairing of a single Access_Token with the Device it was issued to, carrying a device label and a last-used timestamp, representing one active sign-in a Staff_User can view and revoke.
- **Owner**: The Staff_User holding the Owner company role within a Company.
- **Admin**: A Staff_User holding the Admin company role within a Company.
- **Super_Admin**: A platform-level administrator who operates across all Companies and bypasses the per-Company RoleAuthorization matrix.
- **AuditLogger**: The existing server-side service that records security-, access-, and privacy-sensitive actions as PII-minimised audit entries, reused by the API.
- **Audit_Entry**: A single record written by the AuditLogger capturing an action, the acting actor, the affected Company, and a PII-minimised context payload.
- **TOTP_Submission_Endpoint**: The API endpoint that accepts a TOTP code for verification during the TOTP_Challenge.
- **Scan_Check_In_Endpoint**: The API endpoint that accepts scan and manual check-in requests and returns a Scan_Outcome.

## Requirements

### Requirement 1: Email and password sign-in

**User Story:** As an event staff member, I want to sign in to the iOS app with my email and password, so that I can obtain an authenticated session on my device.

#### Acceptance Criteria

1. WHEN the iOS_App submits a valid email and password to the sign-in endpoint for a Device that is not a Trusted_Device and whose Staff_User has two-factor authentication disabled, THE API SHALL issue an Access_Token and return it to the iOS_App.
2. IF the iOS_App submits an email and password that do not match an active Staff_User, THEN THE API SHALL reject the request with an authentication error and SHALL NOT issue an Access_Token.
3. WHEN the iOS_App submits valid credentials for a Staff_User whose two-factor authentication is enabled from a Device that is not a Trusted_Device, THE API SHALL respond that a TOTP_Challenge is required and SHALL NOT issue an Access_Token until the TOTP_Challenge succeeds.
4. WHILE a Device is a Trusted_Device for the authenticating Staff_User, THE API SHALL issue an Access_Token on valid credentials without requiring a TOTP_Challenge.

### Requirement 2: Per-device TOTP two-factor challenge

**User Story:** As an event staff member with two-factor authentication enabled, I want to complete a one-time TOTP challenge on first sign-in per device, so that I am not re-prompted every shift.

#### Acceptance Criteria

1. WHEN the iOS_App submits a TOTP code that the API verifies as valid for the Staff_User against the pragmarx/google2fa-qrcode integration, THE API SHALL record the Device as a Trusted_Device for that Staff_User.
2. WHEN the API records a Device as a Trusted_Device, THE API SHALL issue a long-lived Access_Token and return it to the iOS_App.
3. IF the iOS_App submits a TOTP code that the API determines is invalid or expired, THEN THE API SHALL reject the challenge and SHALL NOT issue an Access_Token.
4. WHILE a Device is recorded as a Trusted_Device, THE API SHALL skip the TOTP_Challenge on subsequent sign-ins from that Device for that Staff_User.

### Requirement 3: Logout and token revocation

**User Story:** As an event staff member, I want to sign out from the app, so that my device no longer holds a usable session.

#### Acceptance Criteria

1. WHEN the iOS_App calls the logout endpoint with a valid Access_Token, THE API SHALL revoke that Access_Token.
2. WHEN the API revokes an Access_Token, THE iOS_App SHALL remove the Access_Token from the Keychain.
3. IF a request presents a revoked, expired, or missing Access_Token, THEN THE API SHALL reject the request with an authentication error.

### Requirement 4: Company scoping and switching

**User Story:** As an event staff member who belongs to one or more companies, I want the app to scope all data to a selected company, so that I only ever see and act on that company's records.

#### Acceptance Criteria

1. THE API SHALL enforce the Tenant_Scope on every data request so that queries and mutations are confined to the Active_Company.
2. WHEN a Staff_User belongs to more than one Company, THE iOS_App SHALL present a company picker after sign-in before showing scanning or dashboard data.
3. WHEN the iOS_App selects a Company that the Staff_User belongs to, THE API SHALL set that Company as the Active_Company for the Access_Token.
4. WHEN the iOS_App switches to a different Company that the Staff_User belongs to, THE API SHALL re-scope all subsequent data to the newly selected Active_Company.
5. IF the iOS_App requests a Company that the Staff_User does not belong to, THEN THE API SHALL reject the request with an authorization error and SHALL NOT change the Active_Company.
6. WHEN a Staff_User belongs to exactly one Company, THE iOS_App SHALL set that Company as the Active_Company without presenting a company picker.

### Requirement 5: Ticket scanning and check-in outcomes

**User Story:** As an event staff member with check-in permission, I want to scan a ticket QR code and have the server verify and check it in, so that attendees are admitted correctly.

#### Acceptance Criteria

1. WHERE the authenticated Staff_User's role holds ACTION_CHECK_IN, THE API SHALL accept check-in requests.
2. IF the authenticated Staff_User's role does not hold ACTION_CHECK_IN, THEN THE API SHALL reject the check-in request with an authorization error and SHALL leave all data unchanged.
3. IF the submitted QR_Payload cannot be decoded into an Order_Reference and token, THEN THE API SHALL return an unreadable Scan_Outcome.
4. IF the submitted QR_Payload decodes but the recomputed HMAC-SHA256 does not match the supplied token, THEN THE API SHALL return an invalid Scan_Outcome.
5. IF the resolved Order_Reference matches no Order within the Tenant_Scope, THEN THE API SHALL return a rejected Scan_Outcome.
6. IF the resolved Order is not confirmed, THEN THE API SHALL return a failed Scan_Outcome.
7. IF the resolved Order has already been checked in, THEN THE API SHALL return an already_scanned Scan_Outcome carrying the original scanned_at timestamp and the name of the operator who checked it in.
8. WHEN the resolved Order is confirmed and not yet checked in, THE API SHALL perform an atomic single check-in and SHALL return a checked_in Scan_Outcome with the order's ticket-type and quantity breakdown.
9. IF two concurrent check-in requests target the same unscanned Order, THEN THE API SHALL check in exactly one request and SHALL return an already_scanned Scan_Outcome for the losing request carrying the winner's scanned_at timestamp.
10. WHEN the API resolves any check-in request, THE API SHALL record a Scan_Event capturing the Scan_Outcome, the operator, and the Order_Reference under the Active_Company.

### Requirement 6: Manual order-reference fallback

**User Story:** As an event staff member, I want to type an order reference when a QR code will not scan, so that I can still check attendees in from damaged or unreadable tickets.

#### Acceptance Criteria

1. WHEN the iOS_App submits a manually entered Order_Reference to the check-in endpoint, THE API SHALL normalise the Order_Reference to uppercase and resolve it through the same tenant-scoped check-in path used for scanned payloads.
2. IF a manually entered Order_Reference is empty after normalisation, THEN THE API SHALL return an unreadable Scan_Outcome.
3. THE iOS_App SHALL provide a manual order-reference entry control as a fallback to camera scanning.

### Requirement 7: Live active-event check-in dashboard

**User Story:** As an event staff member with check-in permission, I want a live dashboard of check-in progress for the active event, so that I can monitor attendance at the door.

#### Acceptance Criteria

1. WHERE the authenticated Staff_User's role holds ACTION_CHECK_IN, THE API SHALL return the live check-in snapshot for the Active_Event scoped to the Active_Company.
2. THE API SHALL include in the snapshot the scanned ticket count, the total tickets sold, the remaining count, the check-in rate, and the most recent Scan_Events for the Active_Event.
3. THE API SHALL exclude sales and revenue figures from the snapshot.
4. THE API SHALL restrict the snapshot to the Active_Event only and SHALL NOT return company-wide figures.
5. IF the authenticated Staff_User's role does not hold ACTION_CHECK_IN, THEN THE API SHALL reject the dashboard request with an authorization error.

### Requirement 8: Tenant-scoped customer search with role gating

**User Story:** As an event staff member with order-management permission, I want to search customer records, so that I can look up attendees by name or email.

#### Acceptance Criteria

1. WHERE the authenticated Staff_User's role holds ACTION_MANAGE_ORDERS, THE API SHALL accept customer search requests scoped to the Active_Company.
2. WHEN the iOS_App submits a free-text search term, THE API SHALL return matching customers by name or email with each customer's order count and confirmed spend.
3. IF the authenticated Staff_User's role does not hold ACTION_MANAGE_ORDERS, THEN THE API SHALL reject the customer search request with an authorization error.
4. WHERE the authenticated Staff_User's role does not hold ACTION_MANAGE_ORDERS, THE iOS_App SHALL hide the customer search feature.
5. WHERE the authenticated Staff_User's role holds ACTION_CHECK_IN without ACTION_MANAGE_ORDERS, THE iOS_App SHALL present the scanning and dashboard features and SHALL hide the customer search feature.

### Requirement 9: Online-only operation and offline error handling

**User Story:** As an event staff member, I want a clear message when my device is offline, so that I understand why a scan did not complete.

#### Acceptance Criteria

1. IF the iOS_App cannot reach the API because the Device has no network connectivity, THEN THE iOS_App SHALL display an offline error message and SHALL NOT report a successful check-in.
2. THE iOS_App SHALL send every scan to the API in real time and SHALL NOT queue scans for later submission.
3. THE iOS_App SHALL NOT store the QR HMAC secret on the Device and SHALL NOT verify QR_Payloads on the Device.

### Requirement 10: Camera permission and Vision QR decode

**User Story:** As an event staff member, I want the app to use my device camera to read ticket QR codes, so that scanning is fast and hands-free.

#### Acceptance Criteria

1. WHEN the iOS_App first needs the camera, THE iOS_App SHALL request camera permission from the operating system.
2. IF camera permission is denied, THEN THE iOS_App SHALL display a message that camera access is required and SHALL NOT attempt to scan.
3. WHILE camera permission is granted, THE iOS_App SHALL decode QR codes using the Vision framework and extract the QR_Payload for submission to the API.
4. WHEN the iOS_App decodes a QR_Payload, THE iOS_App SHALL submit the decoded QR_Payload to the API check-in endpoint.

### Requirement 11: iOS platform and distribution constraints

**User Story:** As a platform operator, I want the iOS app to meet defined platform and distribution constraints, so that it can be installed and run on supported devices.

#### Acceptance Criteria

1. THE iOS_App SHALL target a minimum iOS version of iOS 16.
2. THE iOS_App SHALL be distributed through Apple's App Store or TestFlight.

### Requirement 12: Security and tenant isolation (non-functional)

**User Story:** As a security owner, I want the API and app to protect credentials, tokens, and tenant boundaries, so that attendee and company data stay secure.

#### Acceptance Criteria

1. THE iOS_App SHALL store the Access_Token in the Keychain.
2. THE API SHALL require all requests to use TLS and SHALL reject non-TLS requests.
3. THE API SHALL enforce the Tenant_Scope on every scan, dashboard, and customer-search request so that no response includes another Company's records.
4. THE iOS_App SHALL hold the Staff_User's password only in memory for the duration of the sign-in request and SHALL NOT write the Staff_User's password to the Keychain or to any other on-device store.
5. IF an Access_Token is revoked, THEN THE API SHALL reject every subsequent request presenting that Access_Token.

### Requirement 13: Access_Token lifetime and expiry

**User Story:** As a security owner, I want tokens to expire on their own in addition to explicit logout, so that an unattended or lost device loses access even if it is never signed out.

#### Acceptance Criteria

1. WHEN the API issues an Access_Token, THE API SHALL set the Access_Token to expire after the configured Token_Lifetime.
2. IF a request presents an Access_Token whose Token_Lifetime has elapsed, THEN THE API SHALL reject the request with an authentication error in the same manner as a revoked Access_Token.
3. WHEN the API issues an Access_Token for a Device, THE API SHALL bind the Access_Token to that Device's device identifier.
4. IF a request presents an Access_Token whose bound device identifier does not match the device identifier supplied on the request, THEN THE API SHALL reject the request with an authentication error and SHALL leave all data unchanged.

### Requirement 14: Session visibility and multi-level token revocation

**User Story:** As an event staff member, I want to see and revoke my own active sessions, and as a company Owner or Admin I want to revoke a team member's sessions, so that lost or compromised devices can be cut off at the right level of authority.

#### Acceptance Criteria

1. WHEN a Staff_User requests their active Sessions, THE API SHALL return each Session the Staff_User holds with its device label and last-used timestamp scoped to that Staff_User.
2. WHEN a Staff_User revokes one of their own Sessions, THE API SHALL revoke the Access_Token for that Session.
3. WHERE the authenticated Staff_User's role holds ACTION_MANAGE_USERS, THE API SHALL return the active Access_Tokens of any Staff_User who belongs to the Active_Company and SHALL revoke any such Access_Token on request.
4. IF a Staff_User without ACTION_MANAGE_USERS requests to view or revoke another Staff_User's Access_Tokens, THEN THE API SHALL reject the request with an authorization error and SHALL leave all Access_Tokens unchanged.
5. IF a Staff_User holding ACTION_MANAGE_USERS requests to revoke an Access_Token of a Staff_User who does not belong to the Active_Company, THEN THE API SHALL reject the request with an authorization error and SHALL leave all Access_Tokens unchanged.
6. WHERE the authenticated actor is a Super_Admin, THE API SHALL revoke any Staff_User's Access_Tokens regardless of Company.
7. WHEN any actor revokes an Access_Token, THE API SHALL reject every subsequent request presenting that Access_Token with an authentication error.

### Requirement 15: Rate limiting on sensitive endpoints

**User Story:** As a security owner, I want sensitive endpoints to be rate-limited, so that code-guessing and scan abuse are blunted.

#### Acceptance Criteria

1. WHEN the number of requests to the TOTP_Submission_Endpoint from a given source within the configured window exceeds the configured limit, THE API SHALL reject further TOTP_Submission_Endpoint requests from that source with a rate-limit error until the window resets.
2. WHEN the number of requests to the Scan_Check_In_Endpoint from a given authenticated operator within the configured window exceeds the configured limit, THE API SHALL reject further Scan_Check_In_Endpoint requests from that operator with a rate-limit error until the window resets.
3. THE API SHALL continue to rate-limit the sign-in endpoint.

### Requirement 16: Audit logging of sensitive access events

**User Story:** As a security owner, I want the API to record sensitive access events through the existing AuditLogger, so that sign-ins, revocations, and company switches are accountable without storing secrets.

#### Acceptance Criteria

1. WHEN the API issues an Access_Token on a successful sign-in or Device trust, THE AuditLogger SHALL write an Audit_Entry recording the token-issuance action, the Staff_User, and the Active_Company.
2. WHEN an Access_Token is revoked by self-revoke, by an ACTION_MANAGE_USERS actor, by a Super_Admin, or by logout, THE AuditLogger SHALL write an Audit_Entry recording the revoking actor and the Staff_User whose Session was revoked.
3. WHEN the iOS_App selects or switches the Active_Company, THE AuditLogger SHALL write an Audit_Entry recording the company-selection action, the Staff_User, and the selected Company.
4. WHEN a sign-in attempt fails or a TOTP_Challenge fails on the API, THE AuditLogger SHALL write an Audit_Entry recording the failed-authentication action to match the existing web-controller behaviour.
5. THE AuditLogger SHALL exclude passwords, Access_Tokens, TOTP codes, and the QR HMAC secret from every Audit_Entry it writes for the API.
