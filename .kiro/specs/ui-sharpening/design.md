# Design Document

## Overview

This is a presentation-and-copy refactor of the existing multi-tenant Laravel/Blade event-ticketing platform. The visual direction is "sharp everywhere": square corners, bolder borders and heading weights, tighter spacing, WCAG-informed focus and contrast, plus one net-new UI element (a mobile sticky "Book now" bar) and a copy cleanup pass.

The work is confined to Blade view markup, user-facing copy, and CSS. No routes, controllers, request validation, data models, migrations, authorization, tenancy scoping, or capacity/reservation logic change. The two existing colour systems (shared `--brand: #0f16c4`; storefront per-company `--brand`, default `#674df3`) are preserved.

The central design idea is to **centralise "square" as a token** rather than hand-editing 67 individual `border-radius` declarations. A single custom property drives structural corners across the shared stylesheet, and a second token keeps status chips fully rounded. The storefront inline `<style>` gets the same treatment scoped to its own block. Because most components already consume shared classes (`.btn`, `.card`, `.field`, `.panel`, `.checkout-card`, `.tab-btn`), the majority of the visual change lands in CSS with minimal per-view markup edits.

### Grounding (confirmed by review)

- `public/css/app.css` (1199 lines) declares `:root { --brand: #0f16c4; --brand-dark: #0a0f99; --ink: #111827; --muted: #6b7280; --border: #e5e7eb; --bg: #f9fafb; --surface: #ffffff; ... }` and carries **67** `border-radius` declarations. Representative structural radii: `.btn 0.5rem`, `.card 0.75rem`, `.field input/select/textarea 0.5rem`, `.checkout-card 1rem`, `.auth-card 0.75rem`, `table 0.5rem`, `.tab-btn 0.375rem 0.375rem 0 0`, panels/sponsor cards `0.75rem`, `.qty-stepper 0.6rem`, `.logout-btn 0.4rem`, `.role-form select 0.4rem`, `.event-hero 0 0 1rem 1rem`, `.event-hero__logo 0.6rem`. Chips use `999px` (`.pill`, `.admin-pill`, `.role-badge`, `.ticket-type-state-pill`, `.app-header .role-badge`). Circles use `border-radius: 50%` (avatars, icon bubbles, `.tab-flag`).
- `resources/views/layouts/storefront.blade.php` (235 lines) has its own inline `<style>` with `:root { --brand: #674df3; ... --line: #e6e7ec; --surface: #fff; ... }` and radii of `14px` (`.store-about__text`, `.store-header--poster .store-logo`, `.event-list-item`), `12px` (`.store-sponsors__img`, `.store-sponsor__info`), `10px` (`.empty`), `6px` (`.store-footer .cta`), and `999px` (`.store-links__list a` — a chip). The per-company override arrives via `@yield('brand-style')` and `@stack('head')`.
- `resources/views/events/show.blade.php` (507 lines): `.event-layout` grid, `.event-checkout` aside (`order: -1` mobile, `position: sticky` at `>= 860px`), `.checkout-card` titled "Get tickets", submit button `.checkout-submit[data-checkout-submit]` labelled "Continue to payment". `@php` block computes `$symbol`, `$money = fn($minor) => ...`, and `$hasOnSale`. Three ticket states: `$ticketTypes->isEmpty()`, `! $hasOnSale`, and the purchasable form. An existing `@push('scripts')` block runs an IIFE bound to `#checkout-form`.
- `resources/views/events/_ticket_row.blade.php` (67 lines): renders `.ticket-type` rows.
- Event authoring views live under `resources/views/dashboard/events/` (`create.blade.php`, `show.blade.php`, `_form.blade.php`, `_create_fields.blade.php`, `_location.blade.php`, `_share.blade.php`, `_publish_card.blade.php`, `_nav.blade.php`, tabs, etc.) and `resources/views/dashboard/ticket-types/`. The manage-event page is tab-based (Overview, Ticket types, Location, Share & QR, Report, Orders) with a readiness checklist aside, delivered by the `event-experience-polish` spec.

## Architecture

### Two independent styling systems, one shared strategy

```
┌───────────────────────────────────────────────────────────────┐
│ public/css/app.css  (shared design system)                     │
│   :root { --radius: 0; --radius-chip: 999px; --brand: #0f16c4 } │
│   structural components → border-radius: var(--radius)          │
│   status chips          → border-radius: var(--radius-chip)     │
│   circles/avatars       → border-radius: 50% (unchanged)        │
└───────────────────────────────────────────────────────────────┘
        ▲ consumed by            ▲ consumed by
        │                        │
  dashboard/*  events/show   layouts/app, layouts/dashboard
  (authoring views)          (auth, landing, checkout return)

┌───────────────────────────────────────────────────────────────┐
│ layouts/storefront.blade.php  (inline <style>, own token scope) │
│   :root { --radius: 0; --radius-chip: 999px; --brand: #674df3 } │
│   store components → border-radius: var(--radius)               │
│   per-company override: @yield('brand-style') sets --brand only │
└───────────────────────────────────────────────────────────────┘
```

The two systems stay independent (as today). Each gets its own `--radius` / `--radius-chip` tokens in its own `:root`. The storefront's per-company brand override (which only ever sets `--brand`) is untouched, so squaring corners cannot regress branding.

### Token strategy (the core mechanism)

Introduce two tokens in each `:root` block:

```css
:root {
    --radius: 0;          /* square structural corners everywhere */
    --radius-chip: 999px; /* fully rounded status chips (Req 1.7) */
    /* existing tokens unchanged */
}
```

Then replace **structural** `border-radius` values with `var(--radius)` and **chip** `border-radius: 999px` values with `var(--radius-chip)`. Circles (`50%`) stay literal. This makes "square everywhere" a single-line future-proof switch and guarantees the chip exception is deliberate and centralised.

Rationale for a token over a bulk find-replace to `0`: the requirement explicitly keeps chips rounded (Req 1.7), and a token documents intent, survives future component additions, and lets a reviewer verify the exception set in one place. Split-corner values (e.g. `.slug-input .slug-prefix: 0.5rem 0 0 0.5rem`, `.tab-btn: 0.375rem 0.375rem 0 0`, `.event-hero: 0 0 1rem 1rem`) collapse cleanly to `var(--radius)` (i.e. `0` on all corners), which is the desired square result.

## Components and Interfaces

### 1. Shared design system — `public/css/app.css` (Req 1)

**Tokens.** Add `--radius: 0;` and `--radius-chip: 999px;` to the existing `:root`.

**Structural radius replacement.** Replace the `border-radius` value on these selectors with `var(--radius)` (Req 1.1):

| Selector(s) | Current | New |
|---|---|---|
| `.btn`, `.promo-footer__cta` | `0.5rem` | `var(--radius)` |
| `.field input/select/textarea`, `.field textarea` | `0.5rem` | `var(--radius)` |
| `.status`, `.alert-error` | `0.5rem` | `var(--radius)` |
| `table` | `0.5rem` | `var(--radius)` |
| `.card`, `.feature-card` | `0.75rem` | `var(--radius)` |
| `.auth-card` | `0.75rem` | `var(--radius)` |
| `.slug-input .slug-prefix` / `.slug-input input` | `0.5rem 0 0 0.5rem` / `0 0.5rem 0.5rem 0` | `var(--radius)` |
| `.wizard-step` | `0.625rem` | `var(--radius)` |
| `.app-header .logout-btn` | `0.4rem` | `var(--radius)` |
| `.app-sidebar .nav-link` | `0.5rem` | `var(--radius)` |
| `.role-form select` | `0.4rem` | `var(--radius)` |
| `.tab-btn` | `0.375rem 0.375rem 0 0` | `var(--radius) var(--radius) 0 0` |
| `.branding-preview`, `.sponsor-slot`, `.sponsor-preview`, `.sponsor-preview__ticket`, `.sponsor-list__item`, `.sponsor-add`, `.sponsor-copy` and their image thumbs | `0.75rem` / `0.5rem` | `var(--radius)` |
| `.event-hero` | `0 0 1rem 1rem` | `var(--radius)` |
| `.event-hero__logo` | `0.6rem` | `var(--radius)` |
| `.checkout-card`, `.checkout-return` | `1rem` | `var(--radius)` |
| `.ticket-type` | `0.75rem` | `var(--radius)` |
| `.qty-stepper` | `0.6rem` | `var(--radius)` |
| `.checkout-return .order-summary`, `.checkout-seller`, `.checkout-help`, `.event-support__resend/__contact/__notice`, `.terms-modal__panel` | `0.6rem`–`0.9rem` | `var(--radius)` |
| `.event-sponsors__img`, `.store-sponsors__img`, `.store-sponsor__info` | `8px` | `var(--radius)` |

**Chip exception.** Replace `border-radius: 999px` with `var(--radius-chip)` on `.pill`, `.admin-pill`, `.role-badge`, `.app-header .role-badge`, `.ticket-type-state-pill` (Req 1.7). Circles (`border-radius: 50%` on `.wizard-step-num`, icon bubbles, `.tab-flag`, `.checkout-return__icon`, `.checkout-help__ico`, `.event-support__ico`) stay `50%`; a coin-shaped avatar is not a structural corner.

**Borders and weights (Req 1.3).** Reinforce the sharpened direction without changing layout:
- Headings: bump `h1`/`h2`/`h3` and `.wizard-panel-title`/`legend` weights up one step (e.g. `700 → 800` for `h1`/`h2`, keep `700` where already bold). Set `h1`/`h2` to `font-weight: 800` and `letter-spacing: -0.01em` for a crisper display feel.
- Structural borders: promote key container borders from `1px` to `1.5px` where a stronger edge reads well with square corners: `.card`, `.checkout-card`, `.auth-card`, `.ticket-type`, `.field input/select/textarea`, `.panel`/tab containers, `table` outer container. Keep hairline `1px` for internal table row dividers (`th/td` bottom border) and dashed separators so density does not become noisy. Use `1.5px solid var(--border)` (or `#d1d5db` where inputs already use it).
- `.tab-btn[aria-selected="true"]` active underline stays `2px` (already bold); no change needed.

**Spacing (Req 1.4).** Tighten the scale while preserving a tap-friendly control height (min ~40px for buttons/inputs; the `.qty-btn` at `2.1rem ≈ 33.6px` is bumped in Req 4 targets where it is the primary control, but existing steppers keep current size as they are secondary). Concretely:
- `.btn` padding `0.55rem 1.1rem → 0.5rem 1rem` (height stays ≥ ~38px with line-height).
- `.card`, `.auth-card`, `.checkout-card` padding `1.5rem → 1.25rem` (auth `2rem → 1.5rem`).
- `.field` block spacing: reduce `.field-label` top margin `1rem → 0.75rem`; `.checkout-fields` gap stays `0.5rem`.
- `.card-grid`, `.features` gap `1.25rem → 1rem`.
- `.tab-panel fieldset` bottom margin `1.5rem → 1.25rem`; `legend` bottom margin `0.85rem → 0.65rem`.
- These are the systematic knobs; per-view markup is not touched for spacing.

**Focus-visible (Req 1.5, WCAG 2.4.7 / 1.4.11).** Today focus relies on `:focus` with a soft `rgba(...,0.15)` glow that is below 3:1. Introduce a single strong, keyboard-only focus ring applied globally and reused everywhere:

```css
:where(a, button, input, select, textarea, summary, [tabindex]):focus-visible {
    outline: 2px solid var(--brand);
    outline-offset: 2px;
    /* dark fallback ring guarantees ≥3:1 even over brand-coloured surfaces */
    box-shadow: 0 0 0 4px rgba(17, 24, 39, 0.35);
}
```

`var(--brand)` (`#0f16c4`) against white gives ~13:1; against the light brand-tint surfaces used in the app it stays well above 3:1. The dark `box-shadow` halo guarantees the indicator is perceivable even on dark headers/hero backgrounds. Existing `:focus` glows on inputs are kept for pointer users but the `:focus-visible` ring is the accessibility guarantee. Use `outline` (not just `box-shadow`) so Windows High Contrast Mode renders it.

**Contrast audit (Req 1.6).** Squaring corners does not affect contrast; this is a verification task on the retained palette. Body/control text and their backgrounds:

| Foreground | Background | Ratio | Result |
|---|---|---|---|
| `--ink #111827` | `#fff` / `--bg #f9fafb` | ~16:1 | Pass (body + large) |
| `--muted #6b7280` | `#fff` | ~4.83:1 | Pass 4.5:1 (body) |
| `--muted #6b7280` | `--bg #f9fafb` | ~4.6:1 | Pass 4.5:1 (marginal) |
| `--muted #6b7280` | `#f3f4f6` (table head, chips) | ~4.3:1 | **Below 4.5:1 — flag** |
| `--brand #0f16c4` | `#fff` | ~13:1 | Pass (links) |
| `#fff` | `--brand #0f16c4` (buttons) | ~13:1 | Pass |
| `.error #b91c1c` | `#fff` | ~5.9:1 | Pass |

Action: darken `--muted` used **on grey fills** (table `thead th`, `.ticket-type-state-pill`, `.pill` text over `#f3f4f6`) to `#5b616e` (~5.3:1 on `#f3f4f6`), or lift those specific fills to `#fff`. Prefer darkening the muted token usage on grey to avoid touching layout. Keep the base `--muted` for text on white. Document the one flagged pair and its fix in `verification.md` during implementation.

### 2. Event authoring views (Req 2)

Because these views consume the shared classes above, **most of Req 2 is satisfied by the app.css token/border/spacing work** — no per-view markup edits for corners or spacing. Files under `resources/views/dashboard/events/` and `resources/views/dashboard/ticket-types/` that render structural chrome:

- `create.blade.php`, `show.blade.php` (manage tabs host), `_form.blade.php`, `_create_fields.blade.php`, `_location.blade.php`, `_share.blade.php`, `_publish_card.blade.php`, `_nav.blade.php`, `_ticket_types.blade.php`, `_orders.blade.php`, `_summary.blade.php`, `orders.blade.php`, `report.blade.php`, `location.blade.php`, `share.blade.php`, `tickets.blade.php`, `index.blade.php`, `history.blade.php`, `sponsors.blade.php`; and `ticket-types/_form.blade.php`, `ticket-types/index.blade.php`.

Guarantees:
- Tab set preserved exactly: Overview, Ticket types, Location, Share & QR, Report, Orders (Req 2.2) — no markup change to `_nav.blade.php` tab labels or `data-*` hooks.
- Readiness checklist aside preserved (`_publish_card.blade.php`) (Req 2.3).
- Field labels stay bound to controls; spacing tightening is via `.field`/`.tab-panel`/`.card` central rules, labels keep `for`/`id` pairing (Req 2.4).
- Focus ring from the global `:focus-visible` rule covers fields, tabs (`.tab-btn`), and buttons (Req 2.5).
- No `name`, submit `action`, or route target changes (Req 2.6) — verified via git diff scope (no controller/route edits).

Any per-view markup edit here is limited to copy (Req 5), never structure.

### 3. Storefront + public event page (Req 3)

**Storefront layout inline `<style>` (`layouts/storefront.blade.php`).** Add tokens to its `:root`:

```css
:root {
    --brand: #674df3;   /* unchanged default; per-company override intact */
    --radius: 0;
    --radius-chip: 999px;
    /* ...existing tokens... */
}
```

Replace inline radii (Req 3.1):

| Selector | Current | New |
|---|---|---|
| `.store-about__text`, `.store-header--poster .store-logo`, `.event-list-item` | `14px` | `var(--radius)` |
| `.store-sponsors__img`, `.store-sponsor__info` | `12px` | `var(--radius)` |
| `.empty` | `10px` | `var(--radius)` |
| `.store-footer .cta` | `6px` | `var(--radius)` |
| `.store-links__list a` | `999px` (chip) | `var(--radius-chip)` |

The per-company override mechanism (`@yield('brand-style')` + `@stack('head')` setting `--brand`) is not touched, so the default `#674df3` and configured colours both still drive accents (Req 3.2, 3.3). The brand override never set radii, so no interaction risk.

**Public event page (`events/show.blade.php`, `_ticket_row.blade.php`, app.css event/checkout sections).**
- `.checkout-card`, `.ticket-type`, `.qty-stepper`, `.btn`/`.checkout-submit` already covered by the app.css token pass (Req 3.4).
- `.event-layout` grid, `.event-checkout { order: -1 }`, and the `@media (min-width: 860px)` sticky rule are left exactly as-is (Req 3.5).
- The per-event `--brand` injected via `@push('head')` `<style>:root{--brand: {{ $branding->primaryColour }}}</style>` still governs hero gradient, selected ticket highlight, and links. Contrast on brand-derived backgrounds: white text on the hero gradient (`linear-gradient(135deg, var(--brand), var(--brand-dark))`) is ~13:1 for the default and stays high for typical dark brand colours; `.checkout-note`/`.muted` over white stay ≥4.5:1 (Req 3.6). Note in `verification.md`: very light configured brand colours could reduce white-on-brand contrast; the hero already layers a dark gradient stop, and the design keeps hero text white with a `text-shadow`, which the audit confirms for the shipped palette. This is a per-tenant data concern outside this refactor's scope, flagged for operators.

### 4. Mobile sticky "Book now" bar (Req 4)

A new element inside `events/show.blade.php`, plus CSS in the app.css event section, plus JS reusing the existing `@push('scripts')` pattern.

**Markup** (placed at the end of `<article>` inside `@section` content, after `.event-layout`, so it is a sibling of the layout, not inside the sticky aside):

```blade
@php
    // Lowest purchasable price across on-sale, non-sold-out ticket types.
    $bookNowFromMinor = $hasOnSale
        ? $ticketTypes->filter(fn ($t) => $t['on_sale'] && ! $t['sold_out'])
            ->min(fn ($t) => $t['price_minor'])   // key name confirmed against _ticket_row usage
        : null;
@endphp

@if ($hasOnSale && $bookNowFromMinor !== null)
<div class="book-now-bar" data-book-now-bar>
    <div class="book-now-bar__price">
        <span class="book-now-bar__label">From</span>
        <span class="book-now-bar__amount">{{ $money($bookNowFromMinor) }}</span>
    </div>
    <button type="button" class="btn book-now-bar__cta"
            data-book-now
            aria-label="Book now, jump to ticket selection">
        Book now
    </button>
</div>
@endif
```

**Empty / not-on-sale handling (Req 4 edge case).** The bar is **suppressed entirely** when `$ticketTypes->isEmpty()` or `! $hasOnSale` (no purchasable price to advertise). This matches the two non-form branches already in the checkout card and avoids a "Book now" control that leads to nothing buyable. When suppressed, no bar markup renders and the JS no-ops (guarded by `if (!bar) return;`). The exact price-key (`price_minor`) is verified against `_ticket_row.blade.php` during implementation; if the shaped array differs, the `$money`/min expression is adjusted to the confirmed key — no data source or controller change.

**CSS** (app.css, event section; uses tokens and the global focus ring):

```css
.book-now-bar {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 50;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom));
    background: var(--surface);
    border-top: 1.5px solid var(--border);
    box-shadow: 0 -6px 24px rgba(17, 24, 39, 0.12);
    border-radius: var(--radius); /* 0 */
}
.book-now-bar[hidden] { display: none; }
.book-now-bar__label { font-size: 0.8rem; color: var(--muted); display: block; }
.book-now-bar__amount { font-size: 1.15rem; font-weight: 800; color: var(--ink); }
.book-now-bar__cta {
    min-height: 44px; min-width: 44px;      /* 44×44 target (Req 4.7) */
    padding: 0.65rem 1.25rem; font-size: 1rem;
}
/* Hidden on desktop / tablet (Req 4.8) */
@media (min-width: 860px) {
    .book-now-bar { display: none; }
}
```

The `.book-now-bar__cta` inherits the global `:focus-visible` outline (Req 4.6). `aria-label` supplies the accessible name (Req 4.5). It is a real `<button>`, so keyboard-operable by default (Req 4.6).

**JavaScript** (appended to the existing `@push('scripts')` IIFE region in `show.blade.php`, as a second self-contained IIFE so it does not depend on the checkout-form script):

```js
(function () {
    var bar = document.querySelector('[data-book-now-bar]');
    if (!bar) return;                               // suppressed cases: no-op
    var cta = bar.querySelector('[data-book-now]');
    var card = document.querySelector('.checkout-card');
    var submit = document.querySelector('[data-checkout-submit]');

    // (a) Book now → smooth-scroll + move focus to the checkout card.
    if (cta && card) {
        cta.addEventListener('click', function () {
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            // Make the card programmatically focusable, then focus for AT/keyboard.
            if (!card.hasAttribute('tabindex')) card.setAttribute('tabindex', '-1');
            card.focus({ preventScroll: true });
        });
    }

    // (b) Hide the bar while the submit control (or card) is in view (Req 4.4).
    var target = submit || card;
    if (target && 'IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) { bar.hidden = e.isIntersecting; });
        }, { threshold: 0.01 });
        io.observe(target);
    } else if (target) {
        // Scroll fallback for browsers without IntersectionObserver.
        var onScroll = function () {
            var r = target.getBoundingClientRect();
            bar.hidden = r.top < window.innerHeight && r.bottom > 0;
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        onScroll();
    }
})();
```

Notes:
- Focus move uses `tabindex="-1"` on the card so `.focus()` works without adding it to tab order (Req 4.3).
- The observer targets the submit button when present (checkout form branch) and falls back to the card otherwise; `bar.hidden` toggling reuses the `[hidden]` CSS (Req 4.4).
- No new route/controller; price comes from the already-loaded `$ticketTypes` (Req 6).

### 5. User-facing copy cleanup (Req 5)

**Methodology (rendered copy only; CSS/code comments excluded — Req 5.5).**

1. Grep the targeted rendered Blade views for em dashes and banned phrases:
   ```sh
   grep -rn "—" resources/views/dashboard/events resources/views/ticket-types \
       resources/views/events resources/views/checkout resources/views/landing.blade.php
   grep -rniE "in order to|simply|seamlessly|\bAI\b" <same paths>
   ```
2. Exclude matches inside `{{-- ... --}}` Blade comments, `<!-- -->` HTML comments, and `/* */` CSS comments (these are not user-facing). Review each remaining hit in the rendered text nodes / attribute copy.
3. Rewrite em dashes to commas, full stops, or "to"/colon as the sentence needs. Remove filler ("in order to" → "to"; drop "simply"/"seamlessly"). Rewrite hedging/marketing-speak into short, active-voice statements. Remove "AI" promotional references (Req 5.3).
4. Scope: `Event_Authoring_Views` + `Public_Event_Page` and the booking/checkout + landing/help copy within the client-platform booking scope. Confirmed likely files from the scan: `landing.blade.php`, `dashboard/events/_location.blade.php`, plus other `dashboard/*` and `events/show.blade.php` copy strings.

**Representative before/after (illustrative; implementation confirms exact strings):**

| Location | Before | After |
|---|---|---|
| Landing hero | "Sell tickets seamlessly — no code required" | "Sell tickets. No code required." |
| Event form hint | "Fill this in in order to publish" | "Fill this in to publish." |
| Checkout note | "You won't be charged until the next step" | (already clean — keep) |
| Manage empty state | "Simply add your first ticket type" | "Add your first ticket type." |
| Marketing blurb | "Our AI-powered platform seamlessly handles everything" | "Our platform handles ticketing and payouts." |
| Any "flexible, powerful, best-in-class" hedging | marketing adjectives | concrete, direct statement of what it does |

The final before/after table is produced during implementation from the actual grep hits; the table above defines the rewrite style.

### 6. Preserved behaviour (Req 6)

- Edits confined to Blade markup, user-facing copy, and CSS (Req 6.6).
- No route, controller, validation, or model files touched (Req 6.1, 6.2). The Book now bar reads existing `$ticketTypes`; it adds a client-side scroll/focus affordance only.
- Authorization, tenancy, capacity/reservation logic untouched (Req 6.3).
- Keyboard operability preserved and improved (real `<button>`, visible `:focus-visible` ring) (Req 6.4).
- Any change that would alter behaviour beyond presentation/copy is excluded (Req 6.5) — e.g. the price-key lookup adapts to existing data rather than reshaping it.

## Data Models

No data model changes. The Book now bar derives its displayed price from the already-loaded `$ticketTypes` collection (lowest on-sale, non-sold-out `price_minor`). No new fields, tables, or migrations.

## Error Handling

- **No purchasable price / empty tickets:** the bar is not rendered and the JS returns early. No error state, no dangling control (Req 4 edge case).
- **`IntersectionObserver` unavailable:** scroll/resize fallback keeps hide-on-view behaviour (Req 4.4).
- **Missing checkout card or submit in DOM:** JS guards (`if (!bar) return;`, null checks) prevent runtime errors; the bar simply stays visible if it cannot find a target.
- **CSS token fallback:** `var(--radius)` resolves to `0` from `:root`; if a browser cannot resolve a custom property, the declaration is ignored and the element renders with default (unrounded) corners — acceptable for the sharp direction. Chips carry `var(--radius-chip)` with the `999px` value in `:root`.
- **Per-tenant brand contrast:** flagged in `verification.md` as a data/operator concern; the hero retains white text + dark gradient stop + text-shadow so the shipped palette passes.

## Correctness Properties

*A property is a characteristic or behaviour that should hold true across all valid executions of the system. Because this feature is a CSS/markup/copy refactor, verification is primarily manual/visual plus lightweight automated grep checks (see Testing Strategy). The properties below capture the invariants the change must preserve; they are validated by inspection and the checks described in Testing Strategy rather than by a property-based test harness.*

### Property 1: No em dashes in targeted rendered copy

For any targeted Blade view in `Event_Authoring_Views` or `Public_Event_Page`, the rendered user-facing text (excluding Blade/HTML/CSS comments) contains no `—` (em dash) character.

**Validates: Requirements 5.1**

### Property 2: No banned filler in targeted rendered copy

For any targeted Blade view in `Event_Authoring_Views` or `Public_Event_Page`, the rendered user-facing text (excluding comments) contains none of the banned phrases "in order to", "simply", "seamlessly", or promotional "AI" references.

**Validates: Requirements 5.2, 5.3**

### Property 3: Structural components render square

For any structural component styled by the shared design system or storefront layout (`.btn`, `.card`, `.field` controls, `.checkout-card`, `.auth-card`, tables, tabs, panels, ticket rows, storefront cards), its computed `border-radius` is `0`.

**Validates: Requirements 1.1, 2.1, 3.1, 3.4**

### Property 4: Status chips stay fully rounded

For any `Status_Chip` (`.pill`, `.admin-pill`, `.role-badge`, `.ticket-type-state-pill`), its computed `border-radius` remains `999px`.

**Validates: Requirements 1.7**

### Property 5: Brand tokens preserved

The shared design system retains `--brand: #0f16c4` and `--brand-dark: #0a0f99`, and the storefront retains its per-company `--brand` override with default `#674df3`; brand accents render using the configured colour when set.

**Validates: Requirements 1.2, 3.2, 3.3**

### Property 6: Visible focus meets contrast

For any interactive element that receives keyboard focus, a focus indicator renders with a colour contrast ratio of at least 3:1 against adjacent colours.

**Validates: Requirements 1.5, 2.5, 4.6**

### Property 7: Text contrast meets thresholds

For any body/control text and its background in the retained palette (including brand-derived backgrounds on the public event page), contrast is at least 4.5:1 for body text and at least 3:1 for large text.

**Validates: Requirements 1.6, 3.6**

### Property 8: Book now bar visibility follows viewport and checkout position

For any mobile-width viewport, the `Mobile_Book_Now_Bar` is fixed to the bottom while the checkout submit control is out of view, and is hidden whenever that control is within the viewport; for any viewport width of at least 860px, the bar is hidden.

**Validates: Requirements 4.1, 4.4, 4.8**

### Property 9: Book now activation moves scroll and focus to checkout

For any activation of the "Book now" control, scroll position and keyboard focus move to the `Checkout_Card`.

**Validates: Requirements 4.3**

### Property 10: Book now control is accessible and adequately sized

The "Book now" control exposes an accessible name, is keyboard-operable with a visible focus state, and renders with a touch target of at least 44×44 CSS pixels.

**Validates: Requirements 4.2, 4.5, 4.6, 4.7**

### Property 11: Behaviour and structure preserved

The refactor leaves routes, controllers, validation, data model, authorization, tenancy, and capacity/reservation logic unchanged; preserves the manage-event tab set and readiness checklist; preserves every form field name, submit action, and route target; and preserves the `.event-layout` grid, `.event-checkout` mobile ordering, and `>= 860px` sticky behaviour.

**Validates: Requirements 2.2, 2.3, 2.6, 3.5, 6.1, 6.2, 6.3, 6.4, 6.5, 6.6**

## Testing Strategy

This is a presentation-and-copy change, so the strategy emphasises manual/visual verification plus a lightweight automated grep check. New automated tests are optional given the presentational nature; the existing test suite is run to confirm no behavioural regression.

### Automated (lightweight)

1. **Copy check (Property 1, 2).** A documented grep (or small shell script) asserting no em dashes and no banned filler remain in the targeted rendered Blade views:
   ```sh
   ! grep -rn "—" resources/views/dashboard/events resources/views/ticket-types \
        resources/views/events resources/views/checkout resources/views/landing.blade.php
   ! grep -rniE "in order to|seamlessly|\bsimply\b" <same paths>
   ```
   Exit non-zero on any match. This can live as a `composer`/CI step or a manual pre-merge check.
2. **Blade compiles (Property 11 support).** Run `php artisan view:cache` (then `php artisan view:clear`) to confirm no Blade template breaks; optionally smoke a few routes.
3. **Existing suite.** Run the existing PHPUnit suite (`.phpunit.result.cache` is present) via `php artisan test` (or `vendor/bin/phpunit`) to confirm no behavioural regression. No new PHPUnit tests are required.

### Manual / visual

1. **Corners (Property 3, 4).** Load dashboard event create/edit/manage, storefront, and public event page; confirm structural corners are square and chips remain rounded (spot-check computed `border-radius` in devtools).
2. **Keyboard focus (Property 6).** Tab through fields, tabs, buttons, links, the Book now button, and checkout submit; confirm a clearly visible focus ring on every interactive element, including on dark headers/hero.
3. **Mobile Book now bar (Property 8, 9, 10).** At a mobile viewport (< 860px): confirm the bar is fixed at the bottom, shows "From {price}" and "Book now"; activate it and confirm smooth scroll + focus lands on the checkout card; scroll so the submit is visible and confirm the bar hides; resize to ≥ 860px and confirm the bar disappears; confirm the button is ≥ 44×44 and reachable by keyboard. Verify suppression when tickets are empty / not on sale.
4. **Brand override (Property 5).** View a storefront/event with a configured company brand colour and confirm accents use it and default `#674df3` when unset.
5. **Contrast (Property 7).** Use a contrast checker on `--muted` on grey fills (the flagged pair) and hero text on the brand gradient; confirm the darkened muted-on-grey value passes and record results in `verification.md`.

### Scope confirmation (Property 11)

Run `git diff --stat` before merge and confirm changes are limited to `public/css/app.css`, `resources/views/**/*.blade.php`, and no files under `app/`, `routes/`, `database/`, or `config/` are modified.
