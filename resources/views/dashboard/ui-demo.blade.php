@extends('layouts.dashboard')

@section('title', 'UI redesign demo')
@section('page_title', 'UI redesign demo')

{{--
    UI REDESIGN DEMO / MOCKUP
    =========================
    A throwaway page to review a proposed admin UI refresh BEFORE any rollout.
    It renders inside the real dashboard shell (same header, same sidebar) so
    the proposal can be judged in context. Everything here is self-contained:

      * All data on this page is hard-coded demo data. No DB, no controllers.
      * All styling is scoped under `.ui-demo` so it CANNOT affect any other
        screen. Deleting this file + its route reverts everything.

    What the refresh changes vs. today (all keeping the CK brand blue + sharp
    corners as the identity):
      * Cards/panels gain a soft header band + a hairline + a faint shadow, so
        each section reads as its own surface instead of floating on grey.
      * Filter inputs are taller, wider, and grouped into one bordered toolbar
        card with an icon in the search field — fixes "search fields too short
        and narrow".
      * Table rows are taller with zebra + hover, a left accent on hover, and
        avatar initials, so the list feels alive rather than a flat grid.
      * Status badges get a dot + subtle ring for quick scanning.
      * A thin brand accent strip on the page header + metric tiles add the
        "character" without going full gradient-vibe.

    Accessibility held to the app's existing bar:
      * Keeps native <label for> on every control, real <th scope>, semantic
        <table>, aria-current, and the global :focus-visible ring.
      * Colour is never the only signal (badges carry text + a dot; hover accent
        is decorative on top of existing row semantics).
      * Honors prefers-reduced-motion. Contrast targets WCAG AA on text/badges.
      * Decorative avatars/accents are aria-hidden.
--}}

@push('head')
<style>
    /* ======================================================================
       SCOPED REDESIGN TOKENS  (everything below only applies inside .ui-demo)
       ====================================================================== */
    .ui-demo {
        /* Reuse the brand blue, but introduce a soft tint + a warm accent so
           the UI has a bit of life. These are LOCAL only. */
        --d-brand: var(--brand, #0f16c4);
        --d-brand-tint: color-mix(in srgb, var(--d-brand) 8%, #fff);
        --d-brand-tint-2: color-mix(in srgb, var(--d-brand) 14%, #fff);
        --d-accent: #0ea5a4;              /* teal, echoes the "Events" wordmark */
        --d-surface: #ffffff;
        --d-surface-sunk: #f6f7fb;
        --d-border: #e4e7ef;
        --d-border-strong: #d4d8e3;
        --d-shadow: 0 1px 2px rgba(17,24,39,.04), 0 6px 18px -8px rgba(17,24,39,.12);
        --d-radius: 10px;
    }

    /* ---- Page header with a thin brand accent + description ---- */
    .ui-demo .demo-head {
        position: relative; padding: 1.1rem 1.25rem 1.15rem 1.4rem;
        background: linear-gradient(180deg, var(--d-brand-tint), #fff);
        border: 1px solid var(--d-border); border-radius: var(--d-radius);
        box-shadow: var(--d-shadow); margin-bottom: 1.5rem; overflow: hidden;
    }
    .ui-demo .demo-head::before {
        content: ""; position: absolute; inset: 0 auto 0 0; width: 4px;
        background: linear-gradient(var(--d-brand), var(--d-accent));
    }
    .ui-demo .demo-head h2 { margin: 0; font-size: 1.15rem; }
    .ui-demo .demo-head p { margin: .35rem 0 0; color: var(--muted); max-width: 70ch; font-size: .92rem; }
    .ui-demo .demo-note {
        display: inline-flex; align-items: center; gap: .4rem; margin-top: .75rem;
        font-size: .78rem; font-weight: 600; color: var(--d-brand);
        background: var(--d-brand-tint-2); border: 1px solid color-mix(in srgb, var(--d-brand) 22%, transparent);
        padding: .25rem .6rem; border-radius: 999px;
    }

    /* ---- Metric tiles with a subtle top accent + hover lift ---- */
    .ui-demo .demo-metrics {
        display: grid; gap: 1rem; margin-bottom: 1.5rem;
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
    @media (max-width: 900px) { .ui-demo .demo-metrics { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 480px) { .ui-demo .demo-metrics { grid-template-columns: 1fr; } }
    .ui-demo .demo-metric {
        position: relative; background: var(--d-surface); border: 1px solid var(--d-border);
        border-radius: var(--d-radius); padding: 1rem 1.1rem; box-shadow: var(--d-shadow);
        transition: transform .14s ease, box-shadow .14s ease;
    }
    .ui-demo .demo-metric::before {
        content: ""; position: absolute; inset: 0 0 auto 0; height: 3px;
        background: var(--d-brand); border-radius: var(--d-radius) var(--d-radius) 0 0; opacity: .85;
    }
    .ui-demo .demo-metric:nth-child(2)::before { background: var(--d-accent); }
    .ui-demo .demo-metric:nth-child(3)::before { background: #7c3aed; }
    .ui-demo .demo-metric:nth-child(4)::before { background: #f59e0b; }
    .ui-demo .demo-metric:hover { transform: translateY(-2px); box-shadow: 0 8px 24px -10px rgba(17,24,39,.22); }
    .ui-demo .demo-metric__label { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); font-weight: 700; }
    .ui-demo .demo-metric__value { font-size: 1.65rem; font-weight: 800; color: var(--ink); line-height: 1.1; margin-top: .3rem; font-variant-numeric: tabular-nums; }
    .ui-demo .demo-metric__delta { display: inline-flex; align-items: center; gap: .25rem; margin-top: .35rem; font-size: .8rem; font-weight: 600; }
    .ui-demo .demo-metric__delta--up { color: #047857; }
    .ui-demo .demo-metric__delta--down { color: #b91c1c; }

    /* ---- Card surface (header band + body) ---- */
    .ui-demo .demo-card {
        background: var(--d-surface); border: 1px solid var(--d-border);
        border-radius: var(--d-radius); box-shadow: var(--d-shadow);
        overflow: hidden; margin-bottom: 1.5rem;
    }
    .ui-demo .demo-card__head {
        display: flex; align-items: center; justify-content: space-between; gap: .75rem;
        padding: .85rem 1.15rem; background: var(--d-surface-sunk);
        border-bottom: 1px solid var(--d-border);
    }
    .ui-demo .demo-card__head h3 { margin: 0; font-size: 1rem; display: flex; align-items: center; gap: .5rem; }
    .ui-demo .demo-card__head .icon { color: var(--d-brand); }
    .ui-demo .demo-card__body { padding: 1.15rem; }

    /* ---- Roomier filter toolbar (the main "search fields" fix) ---- */
    .ui-demo .demo-filters {
        display: grid; gap: .9rem 1rem; align-items: end;
        grid-template-columns: minmax(260px, 2fr) repeat(2, minmax(160px, 1fr)) repeat(2, minmax(150px, 1fr));
    }
    @media (max-width: 960px) { .ui-demo .demo-filters { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 560px) { .ui-demo .demo-filters { grid-template-columns: 1fr; } }
    .ui-demo .demo-field label {
        display: block; font-size: .74rem; text-transform: uppercase; letter-spacing: .05em;
        color: var(--muted); font-weight: 700; margin-bottom: .35rem;
    }
    .ui-demo .demo-field input,
    .ui-demo .demo-field select {
        width: 100%; min-height: 46px; padding: .6rem .8rem;
        border: 1.5px solid var(--d-border-strong); border-radius: 8px;
        font: inherit; color: inherit; background: #fff;
        transition: border-color .12s ease, box-shadow .12s ease;
    }
    .ui-demo .demo-field input:hover,
    .ui-demo .demo-field select:hover { border-color: color-mix(in srgb, var(--d-brand) 40%, var(--d-border-strong)); }
    .ui-demo .demo-field input:focus,
    .ui-demo .demo-field select:focus {
        outline: none; border-color: var(--d-brand);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--d-brand) 18%, transparent);
    }
    /* Search field with a leading icon. */
    .ui-demo .demo-search { position: relative; }
    .ui-demo .demo-search .icon {
        position: absolute; left: .7rem; top: 50%; transform: translateY(-50%);
        color: var(--muted); pointer-events: none;
    }
    .ui-demo .demo-search input { padding-left: 2.4rem; }
    .ui-demo .demo-filters__actions { display: flex; gap: .5rem; grid-column: 1 / -1; }

    /* ---- Buttons (local, so the demo can show the refreshed treatment) ---- */
    .ui-demo .d-btn {
        display: inline-flex; align-items: center; gap: .45rem; justify-content: center;
        min-height: 44px; padding: .55rem 1.1rem; border-radius: 8px; border: none;
        font: inherit; font-weight: 600; font-size: .92rem; cursor: pointer; text-decoration: none;
        background: var(--d-brand); color: #fff;
        box-shadow: 0 1px 2px rgba(15,22,196,.25); transition: background .14s ease, transform .1s ease;
    }
    .ui-demo .d-btn:hover { background: var(--brand-dark, #0a0f99); }
    .ui-demo .d-btn:active { transform: translateY(1px); }
    .ui-demo .d-btn--ghost { background: #fff; color: var(--d-brand); box-shadow: inset 0 0 0 1.5px var(--d-border-strong); }
    .ui-demo .d-btn--ghost:hover { background: var(--d-brand-tint); box-shadow: inset 0 0 0 1.5px var(--d-brand); }
    .ui-demo .d-btn--sm { min-height: 34px; padding: .35rem .7rem; font-size: .83rem; }
    .ui-demo .d-btn--danger { background: #dc2626; box-shadow: 0 1px 2px rgba(220,38,38,.25); }
    .ui-demo .d-btn--danger:hover { background: #b91c1c; }

    /* ---- Export row ---- */
    .ui-demo .demo-export { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; padding: 0 1.15rem 1rem; }
    .ui-demo .demo-export__label { font-size: .85rem; color: var(--muted); }

    /* ---- Refreshed data table ---- */
    .ui-demo .demo-table { width: 100%; border-collapse: collapse; background: transparent; }
    .ui-demo .demo-table th, .ui-demo .demo-table td {
        padding: .85rem 1.15rem; text-align: left; vertical-align: middle;
        border-bottom: 1px solid var(--d-border);
    }
    .ui-demo .demo-table thead th {
        background: var(--d-surface-sunk); font-size: .72rem; text-transform: uppercase;
        letter-spacing: .05em; color: #556; font-weight: 700; border-bottom: 1.5px solid var(--d-border-strong);
        position: sticky; top: 0;
    }
    .ui-demo .demo-table tbody tr { transition: background .1s ease; }
    .ui-demo .demo-table tbody tr:nth-child(even) { background: #fcfcfe; }
    .ui-demo .demo-table tbody tr:hover { background: var(--d-brand-tint); box-shadow: inset 3px 0 0 var(--d-brand); }
    .ui-demo .demo-table tbody tr:last-child td { border-bottom: none; }
    .ui-demo .demo-table .num { text-align: right; font-variant-numeric: tabular-nums; }

    /* Customer cell with avatar initials. */
    .ui-demo .cust { display: flex; align-items: center; gap: .7rem; min-width: 0; }
    .ui-demo .cust__avatar {
        flex: none; width: 36px; height: 36px; border-radius: 999px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: .78rem; font-weight: 700; color: #fff; letter-spacing: .02em;
        background: var(--d-brand);
    }
    .ui-demo .cust__avatar--a { background: #7c3aed; }
    .ui-demo .cust__avatar--b { background: var(--d-accent); }
    .ui-demo .cust__avatar--c { background: #f59e0b; }
    .ui-demo .cust__avatar--d { background: #db2777; }
    .ui-demo .cust__text { min-width: 0; }
    .ui-demo .cust__name { font-weight: 600; color: var(--ink); display: block; }
    .ui-demo .cust__email { font-size: .82rem; color: var(--muted); display: block; }
    .ui-demo .ref { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 600; color: var(--ink); text-decoration: none; }
    .ui-demo .ref:hover { color: var(--d-brand); text-decoration: underline; }
    .ui-demo .view-link { color: var(--d-brand); font-weight: 600; text-decoration: none; }
    .ui-demo .view-link:hover { text-decoration: underline; }

    /* ---- Status badges: dot + ring, text always present ---- */
    .ui-demo .badge {
        display: inline-flex; align-items: center; gap: .4rem; white-space: nowrap;
        font-size: .76rem; font-weight: 700; line-height: 1;
        padding: .32rem .65rem .32rem .55rem; border-radius: 999px;
    }
    .ui-demo .badge::before { content: ""; width: .5rem; height: .5rem; border-radius: 999px; background: currentColor; }
    .ui-demo .badge--paid { color: #047857; background: #ecfdf5; box-shadow: inset 0 0 0 1px #a7f3d0; }
    .ui-demo .badge--refunded { color: #b91c1c; background: #fef2f2; box-shadow: inset 0 0 0 1px #fecaca; }
    .ui-demo .badge--reserved { color: #1d4ed8; background: #eff6ff; box-shadow: inset 0 0 0 1px #bfdbfe; }
    .ui-demo .badge--draft { color: #92400e; background: #fffbeb; box-shadow: inset 0 0 0 1px #fde68a; }

    /* ---- Component gallery grid ---- */
    .ui-demo .gallery { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    @media (max-width: 760px) { .ui-demo .gallery { grid-template-columns: 1fr; } }
    .ui-demo .swatches { display: flex; gap: .6rem; flex-wrap: wrap; }
    .ui-demo .swatch { display: flex; flex-direction: column; gap: .35rem; align-items: center; font-size: .72rem; color: var(--muted); }
    .ui-demo .swatch__chip { width: 56px; height: 40px; border-radius: 8px; border: 1px solid rgba(0,0,0,.08); }
    .ui-demo .btn-row { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; }
    .ui-demo .badge-row { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; }

    /* Respect reduced-motion: kill the lifts/transitions. */
    @media (prefers-reduced-motion: reduce) {
        .ui-demo .demo-metric,
        .ui-demo .demo-table tbody tr,
        .ui-demo .d-btn,
        .ui-demo .demo-field input,
        .ui-demo .demo-field select { transition: none; }
        .ui-demo .demo-metric:hover { transform: none; }
        .ui-demo .d-btn:active { transform: none; }
    }

    /* Table horizontal scroll safety on narrow screens. */
    @media (max-width: 720px) {
        .ui-demo .demo-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .ui-demo .demo-table { min-width: 680px; }
    }
</style>
@endpush

@section('content')
<div class="ui-demo">

    {{-- Intro / framing banner --}}
    <div class="demo-head">
        <h2>Orders — proposed look</h2>
        <p>
            Same data, same structure, same CK brand blue and sharp identity — but with roomier
            search, cards that read as distinct surfaces, livelier tables, and a touch of accent
            colour for character. This is a review mockup; nothing on the live Orders screen has
            changed. If you like the direction, we roll these patterns out section by section.
        </p>
        <span class="demo-note" aria-hidden="true">● Demo page · static sample data</span>
    </div>

    {{-- Metric tiles (new on this screen for context / character) --}}
    <div class="demo-metrics">
        <div class="demo-metric">
            <span class="demo-metric__label">Orders</span>
            <span class="demo-metric__value">1,284</span>
            <span class="demo-metric__delta demo-metric__delta--up">▲ 12% vs last month</span>
        </div>
        <div class="demo-metric">
            <span class="demo-metric__label">Gross sales</span>
            <span class="demo-metric__value">£48,920</span>
            <span class="demo-metric__delta demo-metric__delta--up">▲ 8%</span>
        </div>
        <div class="demo-metric">
            <span class="demo-metric__label">Refunded</span>
            <span class="demo-metric__value">£1,240</span>
            <span class="demo-metric__delta demo-metric__delta--down">▼ 3%</span>
        </div>
        <div class="demo-metric">
            <span class="demo-metric__label">Avg. order</span>
            <span class="demo-metric__value">£38.10</span>
            <span class="demo-metric__delta demo-metric__delta--up">▲ 1.4%</span>
        </div>
    </div>

    {{-- Filter toolbar card (the "search fields too short/narrow" fix) --}}
    <div class="demo-card">
        <div class="demo-card__head">
            <h3><x-icon name="search" /> Find orders</h3>
        </div>
        <div class="demo-card__body">
            {{-- action="#" so Filter does nothing on the demo. --}}
            <form action="#" method="GET" class="demo-filters" onsubmit="return false;">
                <div class="demo-field demo-search">
                    <label for="demo-q">Search</label>
                    <x-icon name="search" />
                    <input type="search" id="demo-q" name="q" value="prof" placeholder="Reference, customer name or email">
                </div>
                <div class="demo-field">
                    <label for="demo-event">Event</label>
                    <select id="demo-event" name="event">
                        <option>All events</option>
                        <option>Beatae reprehenderit iusto facilis</option>
                        <option>Tempora quod magnam cumque</option>
                    </select>
                </div>
                <div class="demo-field">
                    <label for="demo-status">Status</label>
                    <select id="demo-status" name="status">
                        <option>All statuses</option>
                        <option>Paid</option>
                        <option>Reserved</option>
                        <option>Refunded</option>
                    </select>
                </div>
                <div class="demo-field">
                    <label for="demo-from">From</label>
                    <input type="date" id="demo-from" name="from">
                </div>
                <div class="demo-field">
                    <label for="demo-to">To</label>
                    <input type="date" id="demo-to" name="to">
                </div>
                <div class="demo-filters__actions">
                    <button type="submit" class="d-btn"><x-icon name="search" /> Filter</button>
                    <a href="{{ route('dashboard.ui-demo') }}" class="d-btn d-btn--ghost">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Results card: refreshed table --}}
    <div class="demo-card">
        <div class="demo-card__head">
            <h3><x-icon name="orders" /> 6 orders</h3>
            <div class="btn-row">
                <a href="#" class="d-btn d-btn--ghost d-btn--sm" onclick="return false;"><x-icon name="report" /> CSV</a>
                <a href="#" class="d-btn d-btn--ghost d-btn--sm" onclick="return false;"><x-icon name="report" /> Excel</a>
            </div>
        </div>
        <div class="demo-table-wrap">
            <table class="demo-table">
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Event</th>
                        <th scope="col">Placed</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Total</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $rows = [
                            ['ref' => 'U12ODTT9HTK3', 'name' => 'Prof. Bryon Gaylord', 'email' => 'wisozk.gene@example.net', 'init' => 'BG', 'av' => '', 'event' => 'Beatae reprehenderit iusto facilis.', 'placed' => '12 Sep 2026, 14:04', 'status' => 'paid', 'label' => 'Paid', 'total' => '43.62'],
                            ['ref' => '5AB7MOK7KZHU', 'name' => 'Prof. Alysa Conroy DDS', 'email' => 'kara.williamson@example.net', 'init' => 'AC', 'av' => 'a', 'event' => 'Beatae reprehenderit iusto facilis.', 'placed' => '12 Sep 2026, 14:04', 'status' => 'paid', 'label' => 'Paid', 'total' => '231.58'],
                            ['ref' => '6VVRTN8CBMGP', 'name' => 'Prof. Margarete Trantow Jr.', 'email' => 'linnie.skiles@example.net', 'init' => 'MT', 'av' => 'b', 'event' => 'Tempora quod magnam cumque.', 'placed' => '12 Sep 2026, 14:04', 'status' => 'paid', 'label' => 'Paid', 'total' => '278.59'],
                            ['ref' => 'ZNIKPFPCQPVR', 'name' => 'Prof. Domenico Medhurst DVM', 'email' => 'jeremy70@example.com', 'init' => 'DM', 'av' => 'c', 'event' => 'Tempora quod magnam cumque.', 'placed' => '12 Sep 2026, 14:04', 'status' => 'paid', 'label' => 'Paid', 'total' => '439.00'],
                            ['ref' => 'N0VJWJUOX2OQ', 'name' => 'Prof. Darrell Wehner PhD', 'email' => 'wendy.mann@example.net', 'init' => 'DW', 'av' => 'd', 'event' => 'Tempora quod magnam cumque.', 'placed' => '12 Sep 2026, 14:04', 'status' => 'reserved', 'label' => 'Reserved', 'total' => '242.34'],
                            ['ref' => 'PHNN72RWRWG1', 'name' => 'Prof. Izaiah Thiel', 'email' => 'dejuan.hoppe@example.net', 'init' => 'IT', 'av' => 'a', 'event' => 'Tempora quod magnam cumque.', 'placed' => '12 Sep 2026, 14:04', 'status' => 'refunded', 'label' => 'Refunded', 'total' => '400.09'],
                        ];
                    @endphp
                    @foreach ($rows as $r)
                        <tr>
                            <td><a href="#" class="ref" onclick="return false;">{{ $r['ref'] }}</a></td>
                            <td>
                                <span class="cust">
                                    <span class="cust__avatar {{ $r['av'] ? 'cust__avatar--'.$r['av'] : '' }}" aria-hidden="true">{{ $r['init'] }}</span>
                                    <span class="cust__text">
                                        <span class="cust__name">{{ $r['name'] }}</span>
                                        <span class="cust__email">{{ $r['email'] }}</span>
                                    </span>
                                </span>
                            </td>
                            <td>{{ $r['event'] }}</td>
                            <td>{{ $r['placed'] }}</td>
                            <td><span class="badge badge--{{ $r['status'] }}">{{ $r['label'] }}</span></td>
                            <td class="num">£{{ $r['total'] }}</td>
                            <td class="num"><a href="#" class="view-link" onclick="return false;">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="demo-export">
            <span class="demo-export__label">Showing 6 of 1,284 orders</span>
        </div>
    </div>

    {{-- Component gallery: colours, buttons, badges, inputs, a card ---------- --}}
    <div class="demo-head" style="margin-top:.5rem;">
        <h2>Component gallery</h2>
        <p>The building blocks the rollout would reuse everywhere. Judge the colours, spacing and states here.</p>
    </div>

    <div class="gallery">
        <div class="demo-card">
            <div class="demo-card__head"><h3>Palette</h3></div>
            <div class="demo-card__body">
                <div class="swatches">
                    <span class="swatch"><span class="swatch__chip" style="background:var(--d-brand)"></span>Brand</span>
                    <span class="swatch"><span class="swatch__chip" style="background:var(--brand-dark,#0a0f99)"></span>Brand dark</span>
                    <span class="swatch"><span class="swatch__chip" style="background:var(--d-accent)"></span>Accent</span>
                    <span class="swatch"><span class="swatch__chip" style="background:#7c3aed"></span>Violet</span>
                    <span class="swatch"><span class="swatch__chip" style="background:#f59e0b"></span>Amber</span>
                    <span class="swatch"><span class="swatch__chip" style="background:var(--d-surface-sunk)"></span>Sunk</span>
                    <span class="swatch"><span class="swatch__chip" style="background:var(--ink)"></span>Ink</span>
                </div>
            </div>
        </div>

        <div class="demo-card">
            <div class="demo-card__head"><h3>Buttons</h3></div>
            <div class="demo-card__body">
                <div class="btn-row">
                    <button class="d-btn" type="button">Primary</button>
                    <button class="d-btn d-btn--ghost" type="button">Secondary</button>
                    <button class="d-btn d-btn--danger" type="button">Danger</button>
                    <button class="d-btn d-btn--sm" type="button">Small</button>
                    <button class="d-btn d-btn--ghost d-btn--sm" type="button"><x-icon name="plus" /> With icon</button>
                </div>
            </div>
        </div>

        <div class="demo-card">
            <div class="demo-card__head"><h3>Status badges</h3></div>
            <div class="demo-card__body">
                <div class="badge-row">
                    <span class="badge badge--paid">Paid</span>
                    <span class="badge badge--reserved">Reserved</span>
                    <span class="badge badge--refunded">Refunded</span>
                    <span class="badge badge--draft">Draft</span>
                </div>
            </div>
        </div>

        <div class="demo-card">
            <div class="demo-card__head"><h3>Inputs</h3></div>
            <div class="demo-card__body">
                <div class="demo-field" style="margin-bottom:.9rem;">
                    <label for="g-text">Text field</label>
                    <input type="text" id="g-text" placeholder="Taller, wider, clear focus ring">
                </div>
                <div class="demo-field demo-search">
                    <label for="g-search">Search field</label>
                    <x-icon name="search" />
                    <input type="search" id="g-search" placeholder="With leading icon">
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
