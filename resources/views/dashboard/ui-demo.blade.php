@extends('layouts.dashboard')

@section('title', 'UI redesign demo')
@section('page_title', 'UI redesign demo')

{{--
    CK EVENTS — ADMIN UI REDESIGN DEMO (v2, to design spec)
    =======================================================
    A throwaway review page rendered inside the real dashboard shell so the
    proposed design system can be judged in context before any rollout.

    Grounded in the designer's spec: Stripe-level restraint, typography and
    spacing doing the work, semantic colour only, minimal shadows, no
    decorative gradients / per-card accent borders / competing accents.

    Self-contained + reversible:
      * All data here is static sample data. No DB, no controllers.
      * All styling is scoped under `.ck` so it cannot affect other screens.
      * Deleting this file + its route reverts everything.

    Accessibility (WCAG 2.2 AA target, matching the app's existing bar):
      * Native <label for> on every control, real <th scope>, semantic table.
      * Status is text + a small dot, never colour alone.
      * Global :focus-visible ring still applies; focus states are explicit.
      * Advanced-filters disclosure is a real <button aria-expanded>.
      * Honours prefers-reduced-motion. Decorative avatars are aria-hidden.
--}}

@push('head')
{{-- Inter: closest to the Stripe aesthetic the spec calls for, with proper
     tabular numerals for money/counts. Loaded the same way the layout already
     loads Cabin Sketch, so no build dependency is added. --}}
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    /* =====================================================================
       DESIGN TOKENS  (scoped to .ck — local only, nothing global changes)
       Values taken directly from the redesign spec §4.
       ===================================================================== */
    .ck {
        --nav:        #101827;  /* sidebar / dark surfaces (reference only here) */
        --bg:         #f6f7f9;  /* workspace */
        --surface:    #ffffff;
        --text:       #141c2b;  /* headings / important */
        --text-2:     #667085;  /* labels / metadata */
        --brand:      #2349e8;  /* primary action / link / active */
        --brand-ink:  #1b3bc0;  /* brand pressed */
        --line:       #e4e8ef;  /* subtle separation */
        --hover:      #f3f5f8;  /* hover / selectable */
        --success:    #138568;
        --warning:    #b7791f;
        --error:      #c93434;

        --r-ctrl: 7px;   /* inputs / buttons */
        --r-card: 9px;   /* containers */
        --r-pop:  10px;  /* popovers */

        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        color: var(--text);
        -webkit-font-smoothing: antialiased;
        font-feature-settings: 'cv11', 'ss01';
    }
    .ck .tnum { font-variant-numeric: tabular-nums; }

    /* ---- Typography scale (spec §5) ---- */
    .ck .page-title { font-size: 1.75rem; line-height: 1.2; font-weight: 600; letter-spacing: -0.02em; margin: 0; color: var(--text); }
    .ck .page-desc  { font-size: 0.875rem; color: var(--text-2); margin: 0.25rem 0 0; }
    .ck .section-title { font-size: 1.1875rem; font-weight: 600; letter-spacing: -0.01em; margin: 0; }
    .ck .card-title { font-size: 0.9375rem; font-weight: 600; margin: 0; letter-spacing: -0.005em; }

    /* =====================================================================
       PAGE HEADER  (no oversized intro panel — spec §9)
       ===================================================================== */
    .ck .ck-pagehead {
        display: flex; align-items: flex-end; justify-content: space-between;
        gap: 1rem 1.5rem; flex-wrap: wrap; margin-bottom: 1.5rem;
    }
    .ck .ck-pagehead__count { color: var(--text-2); font-weight: 400; font-size: 1rem; }
    .ck .ck-pagehead__actions { display: flex; gap: 0.5rem; }

    /* small review banner so it's unmistakably a demo */
    .ck .ck-demochip {
        display: inline-flex; align-items: center; gap: 0.45rem; margin-bottom: 1.25rem;
        font-size: 0.78rem; font-weight: 500; color: var(--text-2);
        background: var(--surface); border: 1px solid var(--line);
        padding: 0.3rem 0.65rem; border-radius: 999px;
    }
    .ck .ck-demochip::before { content: ""; width: 6px; height: 6px; border-radius: 999px; background: var(--brand); }

    /* =====================================================================
       BUTTONS  (compact, comfortable — spec §6/§14)
       ===================================================================== */
    .ck .btn {
        display: inline-flex; align-items: center; gap: 0.4rem; justify-content: center;
        height: 36px; padding: 0 0.85rem; border-radius: var(--r-ctrl);
        font: inherit; font-size: 0.875rem; font-weight: 500; line-height: 1;
        cursor: pointer; text-decoration: none; white-space: nowrap;
        border: 1px solid transparent; transition: background 0.12s ease, border-color 0.12s ease;
    }
    .ck .btn--primary { background: var(--brand); color: #fff; }
    .ck .btn--primary:hover { background: var(--brand-ink); }
    .ck .btn--secondary { background: var(--surface); color: var(--text); border-color: var(--line); }
    .ck .btn--secondary:hover { background: var(--hover); border-color: #d4d8e3; }
    .ck .btn--subtle { background: transparent; color: var(--text-2); }
    .ck .btn--subtle:hover { background: var(--hover); color: var(--text); }
    .ck .btn--danger { background: var(--surface); color: var(--error); border-color: #edc9c9; }
    .ck .btn--danger:hover { background: #fcf3f3; }
    .ck .btn .icon { width: 16px; height: 16px; }

    /* =====================================================================
       FILTER TOOLBAR  (compact, not a big panel — spec §9)
       One row of frequently used controls; the rest behind a disclosure.
       ===================================================================== */
    .ck .ck-toolbar {
        display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;
        padding: 0.6rem; background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--r-card); margin-bottom: 1rem;
    }
    .ck .ck-search { position: relative; flex: 1 1 320px; min-width: 240px; }
    .ck .ck-search .icon { position: absolute; left: 0.6rem; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--text-2); pointer-events: none; }
    .ck .ck-input, .ck .ck-select {
        height: 36px; width: 100%; padding: 0 0.7rem; font: inherit; font-size: 0.875rem;
        color: var(--text); background: var(--surface);
        border: 1px solid #d4d8e3; border-radius: var(--r-ctrl);
        transition: border-color 0.12s ease, box-shadow 0.12s ease;
    }
    .ck .ck-search .ck-input { padding-left: 2.1rem; }
    .ck .ck-input::placeholder { color: #9aa2b1; }
    .ck .ck-input:hover, .ck .ck-select:hover { border-color: #c2c8d6; }
    .ck .ck-input:focus, .ck .ck-select:focus {
        outline: none; border-color: var(--brand);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 16%, transparent);
    }
    .ck .ck-select { flex: 0 1 180px; min-width: 150px; width: auto; }
    .ck .ck-toolbar__sep { width: 1px; align-self: stretch; background: var(--line); margin: 0 0.1rem; }
    .ck .ck-toolbar__right { margin-left: auto; display: flex; gap: 0.5rem; }

    /* Advanced filters disclosure */
    .ck .ck-adv { margin-bottom: 1rem; }
    .ck .ck-adv__panel {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.85rem;
        padding: 0.9rem 1rem; background: var(--surface);
        border: 1px solid var(--line); border-radius: var(--r-card);
    }
    .ck .ck-adv__panel[hidden] { display: none; }
    .ck .ck-field label { display: block; font-size: 0.8125rem; font-weight: 500; color: var(--text); margin-bottom: 0.3rem; }
    .ck .ck-adv__btn .chev { transition: transform 0.12s ease; }
    .ck .ck-adv__btn[aria-expanded="true"] .chev { transform: rotate(180deg); }

    /* =====================================================================
       KPI ROW  (consistent, NO coloured borders — spec §8)
       ===================================================================== */
    .ck .ck-kpis {
        display: grid; gap: 1px; background: var(--line);
        border: 1px solid var(--line); border-radius: var(--r-card); overflow: hidden;
        grid-template-columns: repeat(4, 1fr); margin-bottom: 1.5rem;
    }
    @media (max-width: 820px) { .ck .ck-kpis { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 440px) { .ck .ck-kpis { grid-template-columns: 1fr; } }
    .ck .ck-kpi { background: var(--surface); padding: 1rem 1.1rem; }
    .ck .ck-kpi__label { font-size: 0.8125rem; color: var(--text-2); font-weight: 500; }
    .ck .ck-kpi__value { font-size: 1.75rem; font-weight: 600; letter-spacing: -0.02em; line-height: 1.15; margin-top: 0.3rem; }
    .ck .ck-kpi__meta { font-size: 0.8125rem; color: var(--text-2); margin-top: 0.3rem; }
    .ck .ck-kpi__meta--up { color: var(--success); }
    .ck .ck-kpi__meta--down { color: var(--error); }

    /* =====================================================================
       CARD  (one border, no shadow on static surfaces — spec §6)
       ===================================================================== */
    .ck .ck-card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-card); overflow: hidden; margin-bottom: 1.5rem; }
    .ck .ck-card__head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.85rem 1rem; border-bottom: 1px solid var(--line); }
    .ck .ck-card__foot { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.7rem 1rem; border-top: 1px solid var(--line); color: var(--text-2); font-size: 0.8125rem; flex-wrap: wrap; }

    /* =====================================================================
       TABLE  (the main focus — dense, calm, subtle separators — spec §9)
       ===================================================================== */
    .ck .ck-table { width: 100%; border-collapse: collapse; }
    .ck .ck-table th, .ck .ck-table td { padding: 0.7rem 1rem; text-align: left; vertical-align: middle; }
    .ck .ck-table thead th {
        font-size: 0.75rem; font-weight: 500; color: var(--text-2);
        border-bottom: 1px solid var(--line); background: var(--surface);
        white-space: nowrap;
    }
    .ck .ck-table tbody td { border-bottom: 1px solid var(--line); font-size: 0.875rem; }
    .ck .ck-table tbody tr:last-child td { border-bottom: none; }
    .ck .ck-table tbody tr { transition: background 0.1s ease; }
    .ck .ck-table tbody tr:hover { background: var(--hover); }
    .ck .ck-table .num { text-align: right; }
    .ck .ck-table__actions { width: 1%; white-space: nowrap; text-align: right; }

    .ck .ref { font-family: 'Inter', ui-monospace, monospace; font-weight: 500; color: var(--text); text-decoration: none; font-variant-numeric: tabular-nums; }
    .ck .ref:hover { color: var(--brand); }
    .ck .ck-link { color: var(--brand); font-weight: 500; text-decoration: none; font-size: 0.875rem; }
    .ck .ck-link:hover { text-decoration: underline; }

    /* Customer identity cell with a small initials avatar (spec §11) */
    .ck .cust { display: flex; align-items: center; gap: 0.6rem; min-width: 0; }
    .ck .avatar {
        flex: none; width: 30px; height: 30px; border-radius: 999px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 0.72rem; font-weight: 600; color: var(--brand);
        background: color-mix(in srgb, var(--brand) 10%, #fff);
        border: 1px solid color-mix(in srgb, var(--brand) 18%, transparent);
    }
    .ck .cust__name { font-weight: 500; color: var(--text); display: block; line-height: 1.3; }
    .ck .cust__email { font-size: 0.8125rem; color: var(--text-2); display: block; line-height: 1.3; }
    .ck .muted-cell { color: var(--text-2); }

    /* Status: text + small dot, restrained (spec §4/§9/§19 — not colour alone) */
    .ck .status { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.8125rem; font-weight: 500; white-space: nowrap; }
    .ck .status::before { content: ""; width: 7px; height: 7px; border-radius: 999px; background: currentColor; flex: none; }
    .ck .status--paid { color: var(--success); }
    .ck .status--reserved { color: var(--brand); }
    .ck .status--refunded { color: var(--error); }
    .ck .status--draft { color: var(--warning); }

    /* Row overflow menu trigger (consistent actions — spec §9) */
    .ck .ck-iconbtn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px; border-radius: var(--r-ctrl);
        background: transparent; border: 1px solid transparent; color: var(--text-2); cursor: pointer;
    }
    .ck .ck-iconbtn:hover { background: var(--hover); color: var(--text); border-color: var(--line); }

    /* Pagination */
    .ck .ck-pager { display: inline-flex; gap: 0.25rem; }
    .ck .ck-pager a, .ck .ck-pager span {
        min-width: 32px; height: 32px; padding: 0 0.5rem; border-radius: var(--r-ctrl);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 0.8125rem; font-weight: 500; text-decoration: none; color: var(--text);
        border: 1px solid transparent;
    }
    .ck .ck-pager a:hover { background: var(--hover); }
    .ck .ck-pager .is-current { background: var(--surface); border-color: var(--line); color: var(--brand); }
    .ck .ck-pager .is-disabled { color: #b7bdc9; }

    /* =====================================================================
       COMPONENT GALLERY
       ===================================================================== */
    .ck .gallery { display: grid; gap: 1.25rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    @media (max-width: 760px) { .ck .gallery { grid-template-columns: 1fr; } }
    .ck .gallery .ck-card { margin-bottom: 0; }
    .ck .gallery__body { padding: 1rem; }
    .ck .row { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; }
    .ck .swatch { display: flex; align-items: center; gap: 0.55rem; font-size: 0.8125rem; color: var(--text-2); }
    .ck .swatch__chip { width: 26px; height: 26px; border-radius: 6px; border: 1px solid rgba(0,0,0,0.08); flex: none; }
    .ck .swatch__grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.6rem 1rem; }
    .ck .divider { height: 1px; background: var(--line); margin: 1rem 0; }
    .ck .label-sm { font-size: 0.75rem; font-weight: 500; color: var(--text-2); margin: 0 0 0.5rem; }

    @media (prefers-reduced-motion: reduce) {
        .ck * { transition: none !important; }
    }
    @media (max-width: 720px) {
        .ck .ck-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .ck .ck-table { min-width: 720px; }
        .ck .ck-toolbar__right { margin-left: 0; width: 100%; }
    }
</style>
@endpush

@section('content')
<div class="ck">

    <span class="ck-demochip">Review mockup · static sample data · live Orders screen unchanged</span>

    {{-- ---- Page header: title + count + primary actions (spec §9) -------- --}}
    <div class="ck-pagehead">
        <div>
            <h1 class="page-title">Orders <span class="ck-pagehead__count tnum">1,284</span></h1>
            <p class="page-desc">Every order across your events. Search or filter to narrow the list.</p>
        </div>
        <div class="ck-pagehead__actions">
            <button type="button" class="btn btn--secondary"><x-icon name="report" /> Export</button>
        </div>
    </div>

    {{-- ---- KPI row: consistent, no coloured borders (spec §8) ------------ --}}
    <div class="ck-kpis">
        <div class="ck-kpi">
            <div class="ck-kpi__label">Gross sales</div>
            <div class="ck-kpi__value tnum">£48,920</div>
            <div class="ck-kpi__meta ck-kpi__meta--up">+8.2% vs last 30 days</div>
        </div>
        <div class="ck-kpi">
            <div class="ck-kpi__label">Net payouts</div>
            <div class="ck-kpi__value tnum">£45,110</div>
            <div class="ck-kpi__meta">After fees &amp; refunds</div>
        </div>
        <div class="ck-kpi">
            <div class="ck-kpi__label">Tickets sold</div>
            <div class="ck-kpi__value tnum">3,642</div>
            <div class="ck-kpi__meta ck-kpi__meta--up">+12% vs last 30 days</div>
        </div>
        <div class="ck-kpi">
            <div class="ck-kpi__label">Refunded</div>
            <div class="ck-kpi__value tnum">£1,240</div>
            <div class="ck-kpi__meta">8 orders</div>
        </div>
    </div>

    {{-- ---- Compact filter toolbar + advanced disclosure (spec §9) -------- --}}
    <form action="#" method="GET" onsubmit="return false;">
        <div class="ck-toolbar" role="search">
            <div class="ck-search">
                <label for="ck-q" class="sr-only">Search orders</label>
                <x-icon name="search" />
                <input type="search" id="ck-q" name="q" class="ck-input" value="prof" placeholder="Reference, customer name or email">
            </div>

            <label for="ck-event" class="sr-only">Event</label>
            <select id="ck-event" name="event" class="ck-select">
                <option>All events</option>
                <option>Beatae reprehenderit iusto facilis</option>
                <option>Tempora quod magnam cumque</option>
            </select>

            <label for="ck-status" class="sr-only">Status</label>
            <select id="ck-status" name="status" class="ck-select">
                <option>All statuses</option>
                <option>Paid</option>
                <option>Reserved</option>
                <option>Refunded</option>
            </select>

            <span class="ck-toolbar__sep" aria-hidden="true"></span>

            <div class="ck-toolbar__right">
                <button type="button" class="btn btn--secondary ck-adv__btn" id="ckAdvBtn" aria-expanded="false" aria-controls="ckAdvPanel">
                    <x-icon name="settings" /> Filters
                    <x-icon name="chevron" class="chev" />
                </button>
                <button type="submit" class="btn btn--primary">Apply</button>
            </div>
        </div>

        <div class="ck-adv">
            <div class="ck-adv__panel" id="ckAdvPanel" hidden>
                <div class="ck-field">
                    <label for="ck-from">From date</label>
                    <input type="date" id="ck-from" name="from" class="ck-input">
                </div>
                <div class="ck-field">
                    <label for="ck-to">To date</label>
                    <input type="date" id="ck-to" name="to" class="ck-input">
                </div>
                <div class="ck-field">
                    <label for="ck-min">Minimum total (£)</label>
                    <input type="number" id="ck-min" name="min" class="ck-input" placeholder="0.00" inputmode="decimal">
                </div>
                <div class="ck-field">
                    <label for="ck-channel">Channel</label>
                    <select id="ck-channel" name="channel" class="ck-select" style="width:100%; flex:none;">
                        <option>Any channel</option>
                        <option>Storefront</option>
                        <option>Embed widget</option>
                        <option>Box office</option>
                    </select>
                </div>
            </div>
        </div>
    </form>

    {{-- ---- Orders table: the main focus (spec §9) ------------------------ --}}
    <div class="ck-card">
        <div class="ck-table-wrap">
            <table class="ck-table">
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Event</th>
                        <th scope="col">Placed</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Total</th>
                        <th scope="col" class="ck-table__actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $rows = [
                            ['ref' => 'U12ODTT9HTK3', 'name' => 'Prof. Bryon Gaylord', 'email' => 'wisozk.gene@example.net', 'init' => 'BG', 'event' => 'Beatae reprehenderit iusto facilis', 'placed' => '12 Sep 2026, 14:04', 'status' => 'paid', 'label' => 'Paid', 'total' => '43.62'],
                            ['ref' => '5AB7MOK7KZHU', 'name' => 'Prof. Alysa Conroy DDS', 'email' => 'kara.williamson@example.net', 'init' => 'AC', 'event' => 'Beatae reprehenderit iusto facilis', 'placed' => '12 Sep 2026, 14:04', 'status' => 'paid', 'label' => 'Paid', 'total' => '231.58'],
                            ['ref' => '6VVRTN8CBMGP', 'name' => 'Prof. Margarete Trantow Jr.', 'email' => 'linnie.skiles@example.net', 'init' => 'MT', 'event' => 'Tempora quod magnam cumque', 'placed' => '12 Sep 2026, 14:04', 'status' => 'paid', 'label' => 'Paid', 'total' => '278.59'],
                            ['ref' => 'ZNIKPFPCQPVR', 'name' => 'Prof. Domenico Medhurst DVM', 'email' => 'jeremy70@example.com', 'init' => 'DM', 'event' => 'Tempora quod magnam cumque', 'placed' => '12 Sep 2026, 14:04', 'status' => 'paid', 'label' => 'Paid', 'total' => '439.00'],
                            ['ref' => 'N0VJWJUOX2OQ', 'name' => 'Prof. Darrell Wehner PhD', 'email' => 'wendy.mann@example.net', 'init' => 'DW', 'event' => 'Tempora quod magnam cumque', 'placed' => '12 Sep 2026, 14:04', 'status' => 'reserved', 'label' => 'Reserved', 'total' => '242.34'],
                            ['ref' => 'PHNN72RWRWG1', 'name' => 'Prof. Izaiah Thiel', 'email' => 'dejuan.hoppe@example.net', 'init' => 'IT', 'event' => 'Tempora quod magnam cumque', 'placed' => '12 Sep 2026, 14:04', 'status' => 'refunded', 'label' => 'Refunded', 'total' => '400.09'],
                        ];
                    @endphp
                    @foreach ($rows as $r)
                        <tr>
                            <td><a href="#" class="ref" onclick="return false;">{{ $r['ref'] }}</a></td>
                            <td>
                                <span class="cust">
                                    <span class="avatar" aria-hidden="true">{{ $r['init'] }}</span>
                                    <span>
                                        <span class="cust__name">{{ $r['name'] }}</span>
                                        <span class="cust__email">{{ $r['email'] }}</span>
                                    </span>
                                </span>
                            </td>
                            <td class="muted-cell">{{ $r['event'] }}</td>
                            <td class="muted-cell tnum">{{ $r['placed'] }}</td>
                            <td><span class="status status--{{ $r['status'] }}">{{ $r['label'] }}</span></td>
                            <td class="num tnum">£{{ $r['total'] }}</td>
                            <td class="ck-table__actions">
                                <a href="#" class="ck-link" onclick="return false;">View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="ck-card__foot">
            <span>Showing <span class="tnum">1–6</span> of <span class="tnum">1,284</span></span>
            <nav class="ck-pager" aria-label="Pagination">
                <span class="is-disabled" aria-disabled="true">Prev</span>
                <span class="is-current" aria-current="page">1</span>
                <a href="#" onclick="return false;">2</a>
                <a href="#" onclick="return false;">3</a>
                <span aria-hidden="true">…</span>
                <a href="#" onclick="return false;">52</a>
                <a href="#" onclick="return false;">Next</a>
            </nav>
        </div>
    </div>

    {{-- =================================================================== --}}
    {{-- COMPONENT GALLERY                                                    --}}
    {{-- =================================================================== --}}
    <h2 class="section-title" style="margin:2rem 0 0.25rem;">Component system</h2>
    <p class="page-desc" style="margin-bottom:1.25rem;">The shared building blocks a rollout would reuse. Judge colour, type, spacing and states here.</p>

    <div class="gallery">
        <div class="ck-card">
            <div class="ck-card__head"><h3 class="card-title">Palette</h3></div>
            <div class="gallery__body">
                <div class="swatch__grid">
                    <span class="swatch"><span class="swatch__chip" style="background:#101827"></span>Navigation</span>
                    <span class="swatch"><span class="swatch__chip" style="background:#2349e8"></span>Primary brand</span>
                    <span class="swatch"><span class="swatch__chip" style="background:#138568"></span>Success</span>
                    <span class="swatch"><span class="swatch__chip" style="background:#b7791f"></span>Warning</span>
                    <span class="swatch"><span class="swatch__chip" style="background:#c93434"></span>Error</span>
                    <span class="swatch"><span class="swatch__chip" style="background:#141c2b"></span>Text</span>
                    <span class="swatch"><span class="swatch__chip" style="background:#667085"></span>Secondary text</span>
                    <span class="swatch"><span class="swatch__chip" style="background:#f6f7f9"></span>Background</span>
                </div>
            </div>
        </div>

        <div class="ck-card">
            <div class="ck-card__head"><h3 class="card-title">Buttons</h3></div>
            <div class="gallery__body">
                <p class="label-sm">Hierarchy</p>
                <div class="row">
                    <button type="button" class="btn btn--primary">Primary</button>
                    <button type="button" class="btn btn--secondary">Secondary</button>
                    <button type="button" class="btn btn--subtle">Subtle</button>
                    <button type="button" class="btn btn--danger">Refund</button>
                </div>
                <div class="divider"></div>
                <p class="label-sm">With icon</p>
                <div class="row">
                    <button type="button" class="btn btn--primary"><x-icon name="plus" /> New event</button>
                    <button type="button" class="btn btn--secondary"><x-icon name="report" /> Export</button>
                </div>
            </div>
        </div>

        <div class="ck-card">
            <div class="ck-card__head"><h3 class="card-title">Status indicators</h3></div>
            <div class="gallery__body">
                <p class="label-sm">Text + dot, never colour alone</p>
                <div class="row" style="gap:1.25rem;">
                    <span class="status status--paid">Paid</span>
                    <span class="status status--reserved">Reserved</span>
                    <span class="status status--draft">Pending</span>
                    <span class="status status--refunded">Refunded</span>
                </div>
            </div>
        </div>

        <div class="ck-card">
            <div class="ck-card__head"><h3 class="card-title">Inputs</h3></div>
            <div class="gallery__body">
                <div class="ck-field" style="margin-bottom:0.85rem;">
                    <label for="g-text">Text field</label>
                    <input type="text" id="g-text" class="ck-input" placeholder="Compact, clear focus ring" style="width:100%;">
                </div>
                <div class="ck-field">
                    <label for="g-search">Search</label>
                    <div class="ck-search">
                        <x-icon name="search" />
                        <input type="search" id="g-search" class="ck-input" placeholder="With leading icon">
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
    // Advanced-filters disclosure. Progressive enhancement: the panel is hidden
    // by default and revealed via a real button with aria-expanded. No library.
    (function () {
        var btn = document.getElementById('ckAdvBtn');
        var panel = document.getElementById('ckAdvPanel');
        if (!btn || !panel) return;
        btn.addEventListener('click', function () {
            var open = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
            panel.hidden = open;
        });
    })();
</script>
@endsection
