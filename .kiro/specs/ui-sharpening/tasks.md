# Implementation Plan: UI Sharpening

## Overview

This is a presentation-and-copy refactor confined to `public/css/app.css`, Blade view markup, and user-facing copy. The core mechanism is a `--radius` / `--radius-chip` token pair added to each `:root` block (shared stylesheet and the storefront inline `<style>`), so "square everywhere" becomes a centralised switch while status chips stay rounded. Most component sharpening lands in CSS because views already consume shared classes; per-view markup edits are limited to the new mobile "Book now" bar and copy cleanup. No routes, controllers, request validation, models, or migrations are touched.

Tasks are ordered to build incrementally: shared tokens/borders/spacing first, then accessibility (focus + contrast), then the storefront layout, then the public event page (corners → sticky bar), authoring-view verification, copy cleanup, and a final verification pass. Each code task references the files it edits and the requirement/property numbers it satisfies.

## Tasks

- [x] 1. Shared design-system tokens, corners, borders, weights, and spacing (`public/css/app.css`)
  - [x] 1.1 Add radius tokens and square all structural corners
    - Add `--radius: 0;` and `--radius-chip: 999px;` to the existing `:root` block (keep `--brand: #0f16c4`, `--brand-dark: #0a0f99`, and all other tokens unchanged)
    - Replace the structural `border-radius` value with `var(--radius)` on: `.btn`, `.promo-footer__cta`, `.field input/select/textarea`, `.status`, `.alert-error`, `table`, `.card`, `.feature-card`, `.auth-card`, `.slug-input .slug-prefix` / `.slug-input input`, `.wizard-step`, `.app-header .logout-btn`, `.app-sidebar .nav-link`, `.role-form select`, `.tab-btn` (→ `var(--radius) var(--radius) 0 0`), branding/sponsor selectors and their image thumbs, `.event-hero`, `.event-hero__logo`, `.checkout-card`, `.checkout-return`, `.ticket-type`, `.qty-stepper`, `.checkout-return .order-summary`, `.checkout-seller`, `.checkout-help`, `.event-support__resend/__contact/__notice`, `.terms-modal__panel`, `.event-sponsors__img`, `.store-sponsors__img`, `.store-sponsor__info`
    - Replace `border-radius: 999px` with `var(--radius-chip)` on `.pill`, `.admin-pill`, `.role-badge`, `.app-header .role-badge`, `.ticket-type-state-pill` (keep chips rounded)
    - Leave all `border-radius: 50%` circle rules (avatars, icon bubbles, `.tab-flag`, `.wizard-step-num`, `.checkout-return__icon`, `.checkout-help__ico`, `.event-support__ico`) unchanged
    - _Requirements: 1.1, 1.2, 1.7_

  - [x] 1.2 Reinforce borders and heading weights
    - Bump `h1`/`h2` to `font-weight: 800` with `letter-spacing: -0.01em`; raise `h3`, `.wizard-panel-title`, `legend` one weight step where not already bold
    - Promote key container borders from `1px` to `1.5px solid var(--border)` (or `#d1d5db` where inputs already use it) on `.card`, `.checkout-card`, `.auth-card`, `.ticket-type`, `.field input/select/textarea`, panel/tab containers, and the `table` outer container
    - Keep hairline `1px` on internal table row dividers (`th`/`td` bottom border) and dashed separators; leave the `.tab-btn[aria-selected="true"]` `2px` underline unchanged
    - _Requirements: 1.3_

  - [x] 1.3 Tighten the spacing scale while preserving tap-friendly control height
    - `.btn` padding `0.55rem 1.1rem` → `0.5rem 1rem`; `.card`/`.checkout-card` padding `1.5rem` → `1.25rem`; `.auth-card` `2rem` → `1.5rem`
    - `.field-label` top margin `1rem` → `0.75rem` (keep `.checkout-fields` gap `0.5rem`); `.card-grid`/`.features` gap `1.25rem` → `1rem`; `.tab-panel fieldset` bottom margin `1.5rem` → `1.25rem`; `legend` bottom margin `0.85rem` → `0.65rem`
    - Verify buttons/inputs retain a minimum ~38–40px control height after the change
    - _Requirements: 1.4_

- [x] 2. Accessibility: focus-visible ring and contrast fix (`public/css/app.css`)
  - [x] 2.1 Add a global keyboard-only focus-visible ring
    - Add a single `:where(a, button, input, select, textarea, summary, [tabindex]):focus-visible` rule with `outline: 2px solid var(--brand); outline-offset: 2px;` plus a `box-shadow: 0 0 0 4px rgba(17, 24, 39, 0.35)` dark fallback halo
    - Keep the existing pointer `:focus` glow rules; the `:focus-visible` ring is the accessibility guarantee and must use `outline` so Windows High Contrast Mode renders it
    - _Requirements: 1.5, 2.5, 4.6_

  - [x] 2.2 Fix the flagged muted-on-grey contrast pair
    - Darken the muted text used on grey fills (`thead th`, `.ticket-type-state-pill`, `.pill` text over `#f3f4f6`) to `#5b616e` (~5.3:1 on `#f3f4f6`) without changing layout; keep base `--muted` for text on white
    - _Requirements: 1.6_

- [x] 3. Storefront inline-style tokenisation (`resources/views/layouts/storefront.blade.php`)
  - [x] 3.1 Add tokens and square storefront corners, preserving the per-company brand override
    - Add `--radius: 0;` and `--radius-chip: 999px;` to the inline `<style>` `:root` (keep `--brand: #674df3` default and all other inline tokens)
    - Replace inline radii with `var(--radius)`: `.store-about__text`, `.store-header--poster .store-logo`, `.event-list-item` (`14px`), `.store-sponsors__img`, `.store-sponsor__info` (`12px`), `.empty` (`10px`), `.store-footer .cta` (`6px`); replace `.store-links__list a` `999px` chip with `var(--radius-chip)`
    - Do not touch the `@yield('brand-style')` / `@stack('head')` per-company `--brand` override mechanism
    - _Requirements: 3.1, 3.2, 3.3_

- [ ] 4. Event authoring views verification (no structural markup edits)
  - [ ]* 4.1 Confirm authoring views inherit sharpening from central CSS
    - Visually confirm `resources/views/dashboard/events/**` and `resources/views/dashboard/ticket-types/**` render square corners, tighter spacing, and the focus ring purely via the shared classes changed in tasks 1–2 (no per-view corner/spacing markup edits)
    - Confirm the manage-event tab set is exactly Overview, Ticket types, Location, Share & QR, Report, Orders in `_nav.blade.php` (labels and `data-*` hooks untouched)
    - Confirm the readiness checklist aside in `_publish_card.blade.php` is intact and that field labels keep their `for`/`id` pairing
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6_

- [x] 5. Public event page: corners and mobile sticky "Book now" bar (`resources/views/events/show.blade.php`)
  - [x] 5.1 Confirm public event page corners are covered and layout preserved
    - Confirm `.checkout-card`, `.ticket-type`, `.qty-stepper`, `.btn`/`.checkout-submit` render square via the task 1 token pass (no per-view corner edits needed in `show.blade.php` / `_ticket_row.blade.php`)
    - Confirm the `.event-layout` grid, `.event-checkout { order: -1 }` mobile ordering, and the `@media (min-width: 860px)` sticky rule are left exactly as-is
    - _Requirements: 3.4, 3.5_

  - [x] 5.2 Add the "Book now" bar markup with empty/not-on-sale suppression
    - In `show.blade.php`, add an `@php` block computing `$bookNowFromMinor` as the lowest `price_minor` across on-sale, non-sold-out ticket types (verify the price key against `_ticket_row.blade.php`; adjust the expression to the confirmed key with no data-source change)
    - Render the `.book-now-bar[data-book-now-bar]` element (price "From {amount}" via existing `$money`, and a real `<button data-book-now aria-label="Book now, jump to ticket selection">`) as a sibling of `.event-layout`, not inside the sticky aside
    - Wrap the bar in `@if ($hasOnSale && $bookNowFromMinor !== null)` so it is suppressed entirely when `$ticketTypes->isEmpty()` or `! $hasOnSale`
    - _Requirements: 4.1, 4.2, 4.5, 6.1, 6.2, 6.5, 6.6_

  - [x] 5.3 Add the "Book now" bar CSS (`public/css/app.css` event section)
    - Add `.book-now-bar` fixed-to-bottom styles using `var(--radius)`, `1.5px solid var(--border)` top border, `env(safe-area-inset-bottom)` padding, and `.book-now-bar[hidden] { display: none; }`
    - Style `.book-now-bar__cta` with `min-height: 44px; min-width: 44px` for the touch target; hide the whole bar under `@media (min-width: 860px)`
    - Rely on the global `:focus-visible` ring from task 2.1 for the CTA focus state
    - _Requirements: 4.1, 4.6, 4.7, 4.8_

  - [x] 5.4 Add the "Book now" bar JavaScript (existing `@push('scripts')` region in `show.blade.php`)
    - Add a second self-contained IIFE, guarded by `if (!bar) return;` so the suppressed case is a no-op
    - On CTA click, smooth-scroll to `.checkout-card` and move keyboard focus to it (set `tabindex="-1"` if absent, then `focus({ preventScroll: true })`)
    - Toggle `bar.hidden` via an `IntersectionObserver` on the submit control (`[data-checkout-submit]`, falling back to the card); add a `scroll`/`resize` fallback using `getBoundingClientRect()` when `IntersectionObserver` is unavailable
    - _Requirements: 4.3, 4.4, 6.4_

- [x] 6. User-facing copy cleanup (targeted rendered Blade views)
  - [x] 6.1 Grep and rewrite em dashes, filler, hedging, and AI references
    - Grep `resources/views/dashboard/events`, `resources/views/dashboard/ticket-types`, `resources/views/events`, `resources/views/checkout`, and `resources/views/landing.blade.php` for `—` and case-insensitive `in order to|simply|seamlessly|\bAI\b`
    - Exclude matches inside `{{-- --}}`, `<!-- -->`, and `/* */` comments (not user-facing); rewrite remaining hits: em dashes → comma/full stop/"to"/colon, drop filler ("in order to" → "to"; remove "simply"/"seamlessly"), remove promotional "AI" references, and rewrite hedging/marketing-speak into short active-voice statements
    - Confirm the likely files from the scan (`landing.blade.php`, `dashboard/events/_location.blade.php`, `events/show.blade.php`, and other `dashboard/*` copy) are addressed; edit copy only, never structure
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_

- [ ] 7. Verification and scope confirmation (no code changes)
  - [ ]* 7.1 Run the copy grep check
    - Assert no `—` and no `in order to|seamlessly|\bsimply\b` remain in the targeted rendered views (grep exits non-zero on any match)
    - Validates Property 1 and Property 2
    - _Requirements: 5.1, 5.2, 5.3_

  - [ ]* 7.2 Compile Blade templates and run the existing test suite
    - Run `php artisan view:cache` then `php artisan view:clear` to confirm no Blade template breaks
    - Run the existing PHPUnit suite via `php artisan test` (or `vendor/bin/phpunit`) to confirm no behavioural regression; no new PHPUnit tests are required
    - Validates Property 11 support
    - _Requirements: 6.1, 6.2, 6.3_

  - [ ]* 7.3 Manual keyboard, mobile, brand, and contrast checks
    - Corners/chips: confirm structural corners are square and chips stay rounded (Property 3, 4)
    - Focus: tab through fields, tabs, buttons, links, the Book now button, and checkout submit and confirm a visible ring everywhere including dark headers/hero (Property 6)
    - Book now bar: at < 860px confirm fixed-bottom, "From {price}" + "Book now", activate → smooth scroll + focus on checkout card, submit-in-view hides the bar, ≥ 860px hides the bar, CTA ≥ 44×44 and keyboard-reachable, and suppression when tickets empty/not on sale (Property 8, 9, 10)
    - Brand: confirm configured company brand colour drives accents and `#674df3` when unset (Property 5)
    - Contrast: check `--muted` on grey fills and hero text on the brand gradient (Property 7)
    - _Requirements: 1.5, 1.6, 2.5, 3.6, 4.4, 4.6, 4.7, 4.8_

  - [ ]* 7.4 Confirm scope and append a verification note
    - Run `git diff --stat` and confirm changes are limited to `public/css/app.css` and `resources/views/**/*.blade.php`, with nothing under `app/`, `routes/`, `database/`, or `config/` (Property 11)
    - Append a short verification note (e.g. `verification.md`) recording the grep result, test-suite result, the flagged muted-on-grey contrast pair and its `#5b616e` fix, and the per-tenant brand-contrast caveat for operators
    - _Requirements: 6.1, 6.2, 6.3, 6.5, 6.6_

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP. Here they are the verification-only tasks (task 4 view verification and all of task 7); the core CSS/markup/copy work in tasks 1, 2, 3, 5, and 6 is required.
- This is a CSS/markup/copy refactor, so there is no property-based test harness. The design's Correctness Properties are validated by the grep check, Blade compile, existing PHPUnit suite, and manual/visual inspection captured in task 7.
- Each task references specific requirements for traceability; corner/spacing/focus work is centralised in `app.css` so authoring views (task 4) require no structural markup edits.
- Checkpoints are folded into task 7, which runs after implementation to confirm no behavioural regression and correct scope.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2"] },
    { "id": 2, "tasks": ["1.3", "2.1", "2.2"] },
    { "id": 3, "tasks": ["3.1", "5.2", "6.1"] },
    { "id": 4, "tasks": ["5.3", "5.4"] },
    { "id": 5, "tasks": ["4.1", "5.1", "7.1"] },
    { "id": 6, "tasks": ["7.2", "7.3"] },
    { "id": 7, "tasks": ["7.4"] }
  ]
}
```
