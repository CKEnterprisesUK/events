@extends('layouts.dashboard')

@section('title', 'UI redesign demo')
@section('page_title', 'UI redesign demo')

{{--
    CK EVENTS — ADMIN UI REDESIGN DEMO (v3, composition + table polish)
    ===================================================================
    A throwaway review page rendered inside the real dashboard shell so the
    proposed design system can be judged in context before any rollout.

    This revision addresses the designer's refinement notes:
      1. Page composition — stronger hierarchy between title, a *secondary*
         metric summary strip (not another panel), and the dominant table.
      2. Metric presentation — fewer, clearer figures; money belongs mostly on
         the Dashboard, so Orders shows a light contextual strip + one honest
         sparkline from sample points. No invented metrics.
      3. Table polish — tighter rows, no alternating fills, lighter status,
         clear differentiation of ref / name / event / date, aligned money,
         and a restrained row action instead of repeated bright-blue links.
      4. Subtle CK brand character — blue reserved for primary action, active
         controls, focus and link-hover only; identity via type + spacing.

    Self-contained + reversible: static sample data, all CSS scoped to `.ck`,
    live Orders screen untouched. Deleting this file + its route reverts all.

    Accessibility (WCAG 2.2 AA target): native <label for>, real <th scope>,
    status is text + dot (never colour alone), explicit focus rings, the
    filters + row menus are real aria-expanded buttons, prefers-reduced-motion
    honoured, decorative glyphs aria-hidden.
--}}

@push('head')
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    /* =====================================================================
       DESIGN TOKENS  (scoped to .ck — local only, nothing global changes)
       ===================================================================== */
    .ck {
        --nav:        #101827;
        --bg:         #f6f7f9;
        --surface:    #ffffff;
        --text:       #141c2b;
        --text-2:     #667085;
        --text-3:     #8b93a3;   /* faint metadata (dates) */
        --brand:      #2349e8;
        --brand-ink:  #1b3bc0;
        --line:       #e4e8ef;
        --line-soft:  #eef1f5;   /* even softer row separators */
        --hover:      #f6f7f9;
        --success:    #138568;
        --warning:    #b7791f;
        --error:      #c93434;

        --r-ctrl: 7px;
        --r-card: 9px;
        --r-pop:  10px;

        --sp-1: 4px; --sp-2: 8px; --sp-3: 12px; --sp-4: 16px;
        --sp-6: 24px; --sp-8: 32px; --sp-10: 40px;

        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        color: var(--text);
        -webkit-font-smoothing: antialiased;
        font-feature-settings: 'cv11', 'ss01';
        max-width: 1040px;
    }
    .ck .tnum { font-variant-numeric: tabular-nums; }

    /* ---- Typography scale ---- */
    .ck .page-title { font-size: 1.75rem; line-height: 1.15; font-weight: 600; letter-spacing: -0.02em; margin: 0; color: var(--text); }
    .ck .page-desc  { font-size: 0.875rem; color: var(--text-2); margin: 0.2rem 0 0; }
    .ck .section-title { font-size: 1.1875rem; font-weight: 600; letter-spacing: -0.01em; margin: 0; }
    .ck .section-desc { font-size: 0.8125rem; color: var(--text-2); margin: 0.2rem 0 0; }
    .ck .card-title { font-size: 0.9375rem; font-weight: 600; margin: 0; letter-spacing: -0.005em; }
    .ck .eyebrow { font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-3); }

    /* =====================================================================
       PAGE HEADER — the clear primary (spec note 1)
       Generous space below separates it from secondary context.
       ===================================================================== */
    .ck .ck-pagehead {
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: var(--sp-4) var(--sp-6); flex-wrap: wrap; margin-bottom: var(--sp-6);
    }
    .ck .ck-pagehead__count { color: var(--text-2); font-weight: 400; font-size: 1rem; }
    .ck .ck-pagehead__actions { display: flex; gap: var(--sp-2); padding-top: 0.2rem; }

    /* =====================================================================
       BUTTONS
       ===================================================================== */
    .ck .btn {
        display: inline-flex; align-items: center; gap: 0.4rem; justify-content: center;
        height: 36px; padding: 0 0.85rem; border-radius: var(--r-ctrl);
        font: inherit; font-size: 0.875rem; font-weight: 500; line-height: 1;
        cursor: pointer; text-decoration: none; white-space: nowrap;
        border: 1px solid transparent; transition: background 0.12s ease, border-color 0.12s ease, color 0.12s ease;
    }
    .ck .btn--primary { background: var(--brand); color: #fff; }
    .ck .btn--primary:hover { background: var(--brand-ink); }
    .ck .btn--secondary { background: var(--surface); color: var(--text); border-color: var(--line); }
    .ck .btn--secondary:hover { background: var(--hover); border-color: #d4d8e3; }
    .ck .btn--subtle { background: transparent; color: var(--text-2); }
    .ck .btn--subtle:hover { background: var(--hover); color: var(--text); }
    .ck .btn--danger { background: var(--surface); color: var(--error); border-color: #edc9c9; }
    .ck .btn--danger:hover { background: #fcf3f3; }
    .ck .btn[disabled] { opacity: 0.5; cursor: not-allowed; }
    .ck .btn .icon { width: 16px; height: 16px; }
    .ck .btn--sm { height: 30px; font-size: 0.8125rem; padding: 0 0.6rem; }

    /* =====================================================================
       METRIC SUMMARY STRIP — secondary, NOT a panel (spec notes 1 & 2)
       Borderless inline figures separated by hairlines, so it reads as
       lightweight context sitting under the title rather than a card row.
       ===================================================================== */
    .ck .ck-summary {
        display: flex; align-items: stretch; flex-wrap: wrap;
        gap: 0 var(--sp-8); margin-bottom: var(--sp-6);
        padding-bottom: var(--sp-6); border-bottom: 1px solid var(--line);
    }
    .ck .ck-metric { display: flex; flex-direction: column; gap: 2px; padding: 2px 0; }
    .ck .ck-metric__label { font-size: 0.8125rem; color: var(--text-2); }
    .ck .ck-metric__value { font-size: 1.5rem; font-weight: 600; letter-spacing: -0.02em; line-height: 1.1; color: var(--text); }
    .ck .ck-metric__meta { font-size: 0.75rem; color: var(--text-3); }
    .ck .ck-metric__meta--up { color: var(--success); }
    .ck .ck-metric__meta--down { color: var(--error); }
    .ck .ck-summary__spark { margin-left: auto; display: flex; flex-direction: column; justify-content: center; gap: 4px; }
    .ck .ck-summary__spark .eyebrow { align-self: flex-end; }
    .ck .spark { display: block; }
    .ck .spark path.line { fill: none; stroke: var(--brand); stroke-width: 1.5; }
    .ck .spark path.area { fill: color-mix(in srgb, var(--brand) 8%, transparent); stroke: none; }
    .ck .ck-summary__note { flex-basis: 100%; margin: var(--sp-3) 0 0; font-size: 0.75rem; color: var(--text-3); }
    .ck .ck-summary__note a { color: var(--brand); text-decoration: none; }
    .ck .ck-summary__note a:hover { text-decoration: underline; }
    @media (max-width: 680px) {
        .ck .ck-summary { gap: var(--sp-4) var(--sp-6); }
        .ck .ck-summary__spark { display: none; }
    }

    /* =====================================================================
       FILTER TOOLBAR  (compact) + advanced disclosure
       ===================================================================== */
    .ck .ck-toolbar { display: flex; align-items: center; gap: var(--sp-2); flex-wrap: wrap; margin-bottom: var(--sp-3); }
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
    .ck .ck-toolbar__right { margin-left: auto; display: flex; gap: var(--sp-2); }
    /* Active-filter affordance on the Filters button (brand only when engaged) */
    .ck .ck-adv__btn[aria-expanded="true"] { border-color: var(--brand); color: var(--brand); }
    .ck .ck-adv__btn .chev { transition: transform 0.12s ease; }
    .ck .ck-adv__btn[aria-expanded="true"] .chev { transform: rotate(180deg); }

    .ck .ck-adv__panel {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.85rem;
        padding: 0.9rem 1rem; background: var(--surface);
        border: 1px solid var(--line); border-radius: var(--r-card); margin-bottom: var(--sp-3);
    }
    .ck .ck-adv__panel[hidden] { display: none; }
    .ck .ck-field label { display: block; font-size: 0.8125rem; font-weight: 500; color: var(--text); margin-bottom: 0.3rem; }

    /* =====================================================================
       TABLE — dominant, dense, calm (spec note 3)
       ===================================================================== */
    .ck .ck-table-card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-card); overflow: hidden; }
    .ck .ck-table { width: 100%; border-collapse: collapse; }
    /* tighter rows: 0.55rem vertical vs the old 0.7 */
    .ck .ck-table th, .ck .ck-table td { padding: 0.55rem 1rem; text-align: left; vertical-align: middle; }
    .ck .ck-table thead th {
        font-size: 0.75rem; font-weight: 500; color: var(--text-2);
        border-bottom: 1px solid var(--line); background: var(--surface); white-space: nowrap;
        padding-top: 0.65rem; padding-bottom: 0.65rem;
    }
    .ck .ck-table tbody td { border-bottom: 1px solid var(--line-soft); font-size: 0.875rem; }
    .ck .ck-table tbody tr:last-child td { border-bottom: none; }
    .ck .ck-table tbody tr { transition: background 0.1s ease; }
    .ck .ck-table tbody tr:hover { background: var(--hover); }
    /* let the event column absorb slack so other columns hug their data */
    .ck .ck-table .col-event { width: 100%; }
    .ck .ck-table .num { text-align: right; }
    .ck .ck-table__actions { width: 1%; white-space: nowrap; text-align: right; padding-left: 0; }

    /* Four clearly differentiated text treatments in the row */
    .ck .ref { font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace; font-size: 0.8125rem; font-weight: 500; color: var(--text-2); text-decoration: none; letter-spacing: -0.01em; }
    .ck .ref:hover { color: var(--brand); }
    .ck .cust__name { font-weight: 500; color: var(--text); display: block; line-height: 1.3; }
    .ck .cust__email { font-size: 0.8125rem; color: var(--text-2); display: block; line-height: 1.3; }
    .ck .event-cell { color: var(--text); max-width: 26ch; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }
    .ck .date-cell { color: var(--text-3); font-size: 0.8125rem; }

    /* Status: quiet dot + regular-weight muted text (lighter than before) */
    .ck .status { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.8125rem; font-weight: 400; color: var(--text); white-space: nowrap; }
    .ck .status::before { content: ""; width: 6px; height: 6px; border-radius: 999px; background: currentColor; flex: none; }
    .ck .status--paid { color: var(--success); }
    .ck .status--reserved { color: var(--brand); }
    .ck .status--refunded { color: var(--error); }
    .ck .status--draft { color: var(--warning); }

    /* Row action: a quiet chevron that only gains brand colour on hover/focus,
       replacing the repeated bright-blue "View" links. The whole row is also
       clickable via the ref link; this is the explicit affordance. */
    .ck .row-go {
        display: inline-flex; align-items: center; justify-content: center;
        width: 28px; height: 28px; border-radius: var(--r-ctrl);
        color: var(--text-3); text-decoration: none; transition: background 0.1s ease, color 0.1s ease;
    }
    .ck .row-go .icon { width: 16px; height: 16px; transform: rotate(-90deg); }
    .ck tr:hover .row-go, .ck .row-go:hover, .ck .row-go:focus-visible { color: var(--brand); }
    .ck .row-go:hover { background: color-mix(in srgb, var(--brand) 8%, transparent); }

    /* =====================================================================
       TABLE FOOTER + PAGINATION
       ===================================================================== */
    .ck .ck-tablefoot { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.7rem 1rem; border-top: 1px solid var(--line); color: var(--text-2); font-size: 0.8125rem; flex-wrap: wrap; }
    .ck .ck-pager { display: inline-flex; gap: 0.2rem; }
    .ck .ck-pager a, .ck .ck-pager span {
        min-width: 30px; height: 30px; padding: 0 0.45rem; border-radius: var(--r-ctrl);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 0.8125rem; font-weight: 500; text-decoration: none; color: var(--text);
        border: 1px solid transparent;
    }
    .ck .ck-pager a:hover { background: var(--hover); }
    .ck .ck-pager .is-current { background: color-mix(in srgb, var(--brand) 8%, transparent); color: var(--brand); }
    .ck .ck-pager .is-disabled { color: #b7bdc9; }

    /* =====================================================================
       COMPONENT GALLERY
       ===================================================================== */
    .ck .ck-card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-card); overflow: hidden; }
    .ck .ck-card__head { padding: 0.8rem 1rem; border-bottom: 1px solid var(--line); }
    .ck .gallery { display: grid; gap: var(--sp-4); grid-template-columns: repeat(2, minmax(0, 1fr)); }
    @media (max-width: 760px) { .ck .gallery { grid-template-columns: 1fr; } }
    .ck .gallery__body { padding: 1rem; }
    .ck .row { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; }
    .ck .swatch { display: flex; align-items: center; gap: 0.55rem; font-size: 0.8125rem; color: var(--text-2); }
    .ck .swatch__chip { width: 26px; height: 26px; border-radius: 6px; border: 1px solid rgba(0,0,0,0.08); flex: none; }
    .ck .swatch__grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.6rem 1rem; }
    .ck .divider { height: 1px; background: var(--line); margin: 1rem 0; }
    .ck .label-sm { font-size: 0.75rem; font-weight: 500; color: var(--text-2); margin: 0 0 0.5rem; }

    /* Active nav item pattern (spec note 4 + §7): subtle lighter fill + a
       small CK blue indicator + stronger label, NOT a bright filled block. */
    .ck .navdemo { background: var(--nav); border-radius: var(--r-card); padding: 0.5rem; max-width: 240px; }
    .ck .navdemo__item { display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.7rem; border-radius: 6px; color: #aeb6c6; font-size: 0.875rem; font-weight: 500; text-decoration: none; position: relative; }
    .ck .navdemo__item .icon { width: 18px; height: 18px; }
    .ck .navdemo__item:hover { background: rgba(255,255,255,0.05); color: #dfe4ee; }
    .ck .navdemo__item.is-active { background: rgba(255,255,255,0.08); color: #fff; font-weight: 600; }
    .ck .navdemo__item.is-active::before { content: ""; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 3px; height: 1.1rem; border-radius: 999px; background: var(--brand); }

    /* Toast + tooltip + empty state + skeleton demos */
    .ck .toast { display: flex; align-items: center; gap: 0.6rem; padding: 0.65rem 0.85rem; background: var(--text); color: #fff; border-radius: var(--r-ctrl); font-size: 0.8125rem; box-shadow: 0 6px 20px -8px rgba(17,24,39,0.4); }
    .ck .toast .dot { width: 7px; height: 7px; border-radius: 999px; background: #34d399; flex: none; }
    .ck .empty { text-align: center; padding: 1.5rem 1rem; color: var(--text-2); }
    .ck .empty__title { color: var(--text); font-weight: 600; font-size: 0.9375rem; margin: 0 0 0.25rem; }
    .ck .empty p { margin: 0 0 0.75rem; font-size: 0.8125rem; }
    .ck .skel { height: 10px; border-radius: 4px; background: linear-gradient(90deg, #eef1f5 25%, #e4e8ef 37%, #eef1f5 63%); background-size: 400% 100%; animation: cksh 1.3s ease infinite; }
    @keyframes cksh { 0% { background-position: 100% 0; } 100% { background-position: 0 0; } }
    .ck .avatar-xs { width: 28px; height: 28px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.72rem; font-weight: 600; color: var(--brand); background: color-mix(in srgb, var(--brand) 10%, #fff); border: 1px solid color-mix(in srgb, var(--brand) 18%, transparent); }
    .ck .thumb { width: 36px; height: 36px; border-radius: 6px; background: linear-gradient(135deg, #1b3bc0, #2349e8); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.75rem; letter-spacing: 0.02em; }

    @media (prefers-reduced-motion: reduce) { .ck * { transition: none !important; } .ck .skel { animation: none; } }
    @media (max-width: 720px) {
        .ck .ck-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .ck .ck-table { min-width: 700px; }
        .ck .ck-toolbar__right { margin-left: 0; width: 100%; }
    }
</style>
@endpush

@section('content')
<div class="ck">

    {{-- ============================================================ --}}
    {{-- PRIMARY: page title + main action                            --}}
    {{-- ============================================================ --}}
    <div class="ck-pagehead">
        <div>
            <h1 class="page-title">Orders <span class="ck-pagehead__count tnum">1,284</span></h1>
            <p class="page-desc">Every order across your events. Search or filter to narrow the list.</p>
        </div>
        <div class="ck-pagehead__actions">
            <button type="button" class="btn btn--secondary"><x-icon name="report" /> Export</button>
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- SECONDARY: light contextual metric strip (spec notes 1 & 2)  --}}
    {{-- Only the two figures that belong on Orders; the full finance  --}}
    {{-- set lives on the Dashboard. One honest sparkline.            --}}
    {{-- ============================================================ --}}
    <div class="ck-summary">
        <div class="ck-metric">
            <span class="ck-metric__label">Gross sales · last 30 days</span>
            <span class="ck-metric__value tnum">£48,920</span>
            <span class="ck-metric__meta ck-metric__meta--up">+8.2% on previous period</span>
        </div>
        <div class="ck-metric">
            <span class="ck-metric__label">Refunded · last 30 days</span>
            <span class="ck-metric__value tnum">£1,240</span>
            <span class="ck-metric__meta">8 orders · 2.5% of sales</span>
        </div>

        {{-- Honest sparkline: a trend of daily sales from static sample points.
             Decorative framing is aria-hidden; the figure above carries meaning. --}}
        <div class="ck-summary__spark" aria-hidden="true">
            <span class="eyebrow">Daily sales</span>
            <svg class="spark" width="168" height="40" viewBox="0 0 168 40" role="img">
                <path class="area" d="M0,32 L12,30 L24,31 L36,26 L48,28 L60,22 L72,24 L84,18 L96,20 L108,14 L120,16 L132,11 L144,13 L156,8 L168,10 L168,40 L0,40 Z"/>
                <path class="line" d="M0,32 L12,30 L24,31 L36,26 L48,28 L60,22 L72,24 L84,18 L96,20 L108,14 L120,16 L132,11 L144,13 L156,8 L168,10"/>
            </svg>
        </div>

        <p class="ck-summary__note">Looking for payouts, fees and net revenue? Those live on the <a href="#" onclick="return false;">Dashboard</a>.</p>
    </div>

    {{-- ============================================================ --}}
    {{-- Compact filter toolbar + advanced disclosure                 --}}
    {{-- ============================================================ --}}
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

            <div class="ck-toolbar__right">
                <button type="button" class="btn btn--secondary ck-adv__btn" id="ckAdvBtn" aria-expanded="false" aria-controls="ckAdvPanel">
                    <x-icon name="settings" /> Filters
                    <x-icon name="chevron" class="chev" />
                </button>
                <button type="submit" class="btn btn--primary">Apply</button>
            </div>
        </div>

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
    </form>

    {{-- ============================================================ --}}
    {{-- DOMINANT: the orders table                                   --}}
    {{-- ============================================================ --}}
    <div class="ck-table-card">
        <div class="ck-table-wrap">
            <table class="ck-table">
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Customer</th>
                        <th scope="col" class="col-event">Event</th>
                        <th scope="col">Placed</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Total</th>
                        <th scope="col" class="ck-table__actions"><span class="sr-only">Open order</span></th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $rows = [
                            ['ref' => 'U12ODTT9HTK3', 'name' => 'Prof. Bryon Gaylord', 'email' => 'wisozk.gene@example.net', 'event' => 'Beatae reprehenderit iusto facilis', 'placed' => '12 Sep 2026, 14:04', 'status' => 'paid', 'label' => 'Paid', 'total' => '43.62'],
                            ['ref' => '5AB7MOK7KZHU', 'name' => 'Prof. Alysa Conroy DDS', 'email' => 'kara.williamson@example.net', 'event' => 'Beatae reprehenderit iusto facilis', 'placed' => '12 Sep 2026, 14:04', 'status' => 'paid', 'label' => 'Paid', 'total' => '231.58'],
                            ['ref' => '6VVRTN8CBMGP', 'name' => 'Prof. Margarete Trantow Jr.', 'email' => 'linnie.skiles@example.net', 'event' => 'Tempora quod magnam cumque', 'placed' => '12 Sep 2026, 14:04', 'status' => 'paid', 'label' => 'Paid', 'total' => '278.59'],
                            ['ref' => 'ZNIKPFPCQPVR', 'name' => 'Prof. Domenico Medhurst DVM', 'email' => 'jeremy70@example.com', 'event' => 'Tempora quod magnam cumque', 'placed' => '12 Sep 2026, 14:04', 'status' => 'paid', 'label' => 'Paid', 'total' => '439.00'],
                            ['ref' => 'N0VJWJUOX2OQ', 'name' => 'Prof. Darrell Wehner PhD', 'email' => 'wendy.mann@example.net', 'event' => 'Tempora quod magnam cumque', 'placed' => '12 Sep 2026, 14:04', 'status' => 'reserved', 'label' => 'Reserved', 'total' => '242.34'],
                            ['ref' => 'PHNN72RWRWG1', 'name' => 'Prof. Izaiah Thiel', 'email' => 'dejuan.hoppe@example.net', 'event' => 'Tempora quod magnam cumque', 'placed' => '12 Sep 2026, 14:04', 'status' => 'refunded', 'label' => 'Refunded', 'total' => '400.09'],
                        ];
                    @endphp
                    @foreach ($rows as $r)
                        <tr>
                            <td><a href="#" class="ref" onclick="return false;">{{ $r['ref'] }}</a></td>
                            <td>
                                <span class="cust__name">{{ $r['name'] }}</span>
                                <span class="cust__email">{{ $r['email'] }}</span>
                            </td>
                            <td class="col-event"><span class="event-cell">{{ $r['event'] }}</span></td>
                            <td><span class="date-cell tnum">{{ $r['placed'] }}</span></td>
                            <td><span class="status status--{{ $r['status'] }}">{{ $r['label'] }}</span></td>
                            <td class="num tnum">£{{ $r['total'] }}</td>
                            <td class="ck-table__actions">
                                <a href="#" class="row-go" onclick="return false;" aria-label="Open order {{ $r['ref'] }}">
                                    <x-icon name="chevron" />
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="ck-tablefoot">
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

    {{-- ============================================================ --}}
    {{-- COMPONENT SYSTEM — demo of every reusable piece              --}}
    {{-- ============================================================ --}}
    <div style="margin:2.5rem 0 1.25rem;">
        <h2 class="section-title">Component system</h2>
        <p class="section-desc">The shared building blocks a rollout would reuse. Judge colour, type, spacing and states here.</p>
    </div>

    <div class="gallery">
        {{-- Palette --}}
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

        {{-- Typography --}}
        <div class="ck-card">
            <div class="ck-card__head"><h3 class="card-title">Typography</h3></div>
            <div class="gallery__body">
                <div class="page-title" style="margin-bottom:0.3rem;">Page title</div>
                <div class="section-title" style="margin-bottom:0.3rem;">Section title</div>
                <div class="card-title" style="margin-bottom:0.3rem;">Card heading</div>
                <div style="font-size:0.875rem; margin-bottom:0.3rem;">Body text — 14px, the workhorse size.</div>
                <div style="font-size:0.8125rem; color:var(--text-2); margin-bottom:0.3rem;">Supporting text — labels and metadata.</div>
                <div class="tnum" style="font-size:1.25rem; font-weight:600;">£12,480.50 · 1,284</div>
                <div style="font-size:0.72rem; color:var(--text-3); margin-top:0.2rem;">Tabular numerals for money &amp; counts</div>
            </div>
        </div>

        {{-- Buttons --}}
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
                <p class="label-sm">With icon · small · disabled</p>
                <div class="row">
                    <button type="button" class="btn btn--primary"><x-icon name="plus" /> New event</button>
                    <button type="button" class="btn btn--secondary btn--sm"><x-icon name="report" /> Export</button>
                    <button type="button" class="btn btn--secondary" disabled>Disabled</button>
                </div>
            </div>
        </div>

        {{-- Status indicators --}}
        <div class="ck-card">
            <div class="ck-card__head"><h3 class="card-title">Status indicators</h3></div>
            <div class="gallery__body">
                <p class="label-sm">Dot + text, never colour alone</p>
                <div class="row" style="gap:1.25rem;">
                    <span class="status status--paid">Paid</span>
                    <span class="status status--reserved">Reserved</span>
                    <span class="status status--draft">Pending</span>
                    <span class="status status--refunded">Refunded</span>
                </div>
            </div>
        </div>

        {{-- Inputs --}}
        <div class="ck-card">
            <div class="ck-card__head"><h3 class="card-title">Inputs &amp; selects</h3></div>
            <div class="gallery__body">
                <div class="ck-field" style="margin-bottom:0.85rem;">
                    <label for="g-text">Text field</label>
                    <input type="text" id="g-text" class="ck-input" placeholder="Compact, clear focus ring" style="width:100%;">
                </div>
                <div class="ck-field" style="margin-bottom:0.85rem;">
                    <label for="g-search">Search</label>
                    <div class="ck-search">
                        <x-icon name="search" />
                        <input type="search" id="g-search" class="ck-input" placeholder="With leading icon">
                    </div>
                </div>
                <div class="ck-field">
                    <label for="g-select">Select</label>
                    <select id="g-select" class="ck-select" style="width:100%; flex:none;">
                        <option>Choose an option</option>
                        <option>Option A</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- Active navigation pattern --}}
        <div class="ck-card">
            <div class="ck-card__head"><h3 class="card-title">Active navigation</h3></div>
            <div class="gallery__body">
                <p class="label-sm">Subtle fill + CK indicator, not a bright block</p>
                <div class="navdemo">
                    <a href="#" class="navdemo__item" onclick="return false;"><x-icon name="dashboard" /> Dashboard</a>
                    <a href="#" class="navdemo__item is-active" onclick="return false;" aria-current="page"><x-icon name="orders" /> Orders</a>
                    <a href="#" class="navdemo__item" onclick="return false;"><x-icon name="customers" /> Customers</a>
                </div>
            </div>
        </div>

        {{-- Avatars, thumbnails & pagination --}}
        <div class="ck-card">
            <div class="ck-card__head"><h3 class="card-title">Identity &amp; pagination</h3></div>
            <div class="gallery__body">
                <p class="label-sm">Initials avatar · event thumbnail fallback</p>
                <div class="row" style="margin-bottom:1rem;">
                    <span class="avatar-xs" aria-hidden="true">BG</span>
                    <span class="avatar-xs" aria-hidden="true">AC</span>
                    <span class="thumb" aria-hidden="true">EV</span>
                </div>
                <p class="label-sm">Pagination</p>
                <nav class="ck-pager" aria-label="Example pagination">
                    <span class="is-disabled">Prev</span>
                    <span class="is-current" aria-current="page">1</span>
                    <a href="#" onclick="return false;">2</a>
                    <a href="#" onclick="return false;">3</a>
                    <a href="#" onclick="return false;">Next</a>
                </nav>
            </div>
        </div>

        {{-- Feedback: toast + empty + skeleton --}}
        <div class="ck-card">
            <div class="ck-card__head"><h3 class="card-title">Feedback &amp; states</h3></div>
            <div class="gallery__body">
                <p class="label-sm">Toast</p>
                <div class="toast" role="status" style="margin-bottom:1rem;"><span class="dot" aria-hidden="true"></span> Order refunded successfully</div>

                <p class="label-sm">Loading skeleton</p>
                <div style="display:grid; gap:0.5rem; margin-bottom:1rem;">
                    <span class="skel" style="width:70%;"></span>
                    <span class="skel" style="width:90%;"></span>
                    <span class="skel" style="width:55%;"></span>
                </div>

                <p class="label-sm">Empty state</p>
                <div class="empty">
                    <p class="empty__title">No orders match your filters</p>
                    <p>Try widening the date range or clearing the status filter.</p>
                    <button type="button" class="btn btn--secondary btn--sm">Clear filters</button>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
    // Advanced-filters disclosure — progressive enhancement, real aria-expanded.
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
