{{--
    Interactive sales chart for the dashboard home.

    Expects:
      $chart  — the series built by DashboardController::buildSalesChart().
      $money  — callable(int $minor): string currency formatter.

    Renders server-side inline SVG (this period solid, previous period dashed),
    with a small script adding hover tooltips that show the exact figure for
    both periods. The final point is the current (incomplete) day/month and is
    drawn dashed and labelled so it is not mistaken for a real drop in sales.
--}}
@php
    $isTickets = $chart['metric'] === 'tickets';
    $monthly = $chart['unit'] === 'month';
    $points = $chart['points'];
    $count = count($points);
    $format = fn (int $v): string => $isTickets ? number_format($v) : $money($v);

    $valueOf = fn (array $p): int => $isTickets ? $p['tickets'] : $p['revenue_minor'];
    $previousOf = fn (array $p): int => $isTickets ? $p['previous_tickets'] : $p['previous_revenue_minor'];

    // Dynamic scale: four intervals with a "nice" step, sized to the data plus
    // ~8% headroom so the peak never touches the top edge or floats far below it.
    $gridIntervals = 4;
    $targetMax = max((float) $chart['max_value'], 1.0) * 1.08;
    $rawStep = $targetMax / $gridIntervals;
    $stepPow = 10 ** floor(log10($rawStep));
    $stepFrac = $rawStep / $stepPow;
    $niceStep = max(1.0, ($stepFrac <= 1 ? 1 : ($stepFrac <= 2 ? 2 : ($stepFrac <= 2.5 ? 2.5 : ($stepFrac <= 5 ? 5 : 10)))) * $stepPow);
    $gridIntervals = max(1, (int) ceil($targetMax / $niceStep));
    $niceMax = $niceStep * $gridIntervals;

    // Wide, short-padding plot so the data fills the panel.
    $vbW = 960.0;
    $vbH = 360.0;
    $padL = 56.0;
    $padR = 12.0;
    $padT = 12.0;
    $padB = 30.0;
    $plotW = $vbW - $padL - $padR;
    $plotH = $vbH - $padT - $padB;
    $stepX = $count > 1 ? $plotW / ($count - 1) : 0.0;
    $xAt = fn (int $i): float => $padL + $stepX * $i;
    $yAt = fn (float $v): float => $padT + $plotH - ((min(max($v, 0.0), $niceMax) / $niceMax) * $plotH);

    $grid = [];
    for ($g = 0; $g <= $gridIntervals; $g++) {
        $grid[] = $niceStep * $g;
    }

    $maxTicks = $monthly ? 12 : 8;
    $tickEvery = $count > $maxTicks ? (int) ceil($count / $maxTicks) : 1;

    $currentCoords = [];
    $previousCoords = [];
    $tooltip = [];
    foreach ($points as $i => $p) {
        $x = round($xAt($i), 1);
        $currentCoords[] = $x.','.round($yAt((float) $valueOf($p)), 1);
        $previousCoords[] = $x.','.round($yAt((float) $previousOf($p)), 1);
        $tooltip[] = [
            'x' => $x,
            'y' => round($yAt((float) $valueOf($p)), 1),
            'py' => round($yAt((float) $previousOf($p)), 1),
            'label' => $p['full_label'],
            'previousLabel' => $p['previous_label'],
            'revenue' => $money($p['revenue_minor']),
            'tickets' => number_format($p['tickets']),
            'previousRevenue' => $money($p['previous_revenue_minor']),
            'previousTickets' => number_format($p['previous_tickets']),
        ];
    }

    $solidLine = implode(' ', $currentCoords);
    $baseY = round($yAt(0), 1);
    $area = implode(' ', $currentCoords).' '.round($xAt($count - 1), 1).','.$baseY.' '.round($xAt(0), 1).','.$baseY;
@endphp

<figure class="sales-chart" data-sales-chart data-points='@json($tooltip)' data-metric="{{ $chart['metric'] }}"
        data-pad-left="{{ $padL }}" data-step-x="{{ round($stepX, 3) }}" data-vb-width="{{ $vbW }}">
    <figcaption class="sales-chart__legend">
        <span class="sales-chart__legend-item"><span class="sales-chart__swatch sales-chart__swatch--current"></span>This period</span>
        <span class="sales-chart__legend-item"><span class="sales-chart__swatch sales-chart__swatch--previous"></span>Previous {{ $chart['range_label'] }}</span>
    </figcaption>

    <p class="sr-only">
        {{ $isTickets ? 'Tickets sold' : 'Gross sales' }} over the last {{ $chart['range_label'] }}:
        {{ $format($chart['current_total']) }}, compared with {{ $format($chart['previous_total']) }} in the previous period.
    </p>

    <div class="sales-chart__plot">
        <svg class="sales-chart__svg" viewBox="0 0 {{ (int) $vbW }} {{ (int) $vbH }}" preserveAspectRatio="none" role="img"
             aria-label="{{ $isTickets ? 'Tickets sold' : 'Gross sales' }} over the last {{ $chart['range_label'] }}, compared with the previous period">
            @foreach ($grid as $gv)
                @php $gy = round($yAt($gv), 1); @endphp
                <line x1="{{ $padL }}" y1="{{ $gy }}" x2="{{ $vbW - $padR }}" y2="{{ $gy }}"
                      stroke="currentColor" stroke-width="1" vector-effect="non-scaling-stroke" opacity="{{ $gv == 0 ? '0.35' : '0.12' }}" />
            @endforeach

            <polygon points="{{ $area }}" fill="var(--brand)" opacity="0.08" />
            <polyline points="{{ implode(' ', $previousCoords) }}" fill="none" stroke="#9ca3af" stroke-width="1.75"
                      stroke-dasharray="5 4" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            <polyline points="{{ $solidLine }}" fill="none" stroke="var(--brand)" stroke-width="2.5"
                      stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
        </svg>

        {{-- Axis labels are HTML (not SVG text) so they don't stretch with the plot. --}}
        @foreach ($grid as $gv)
            <span class="sales-chart__ylabel" style="top: {{ round($yAt($gv) / $vbH * 100, 2) }}%">{{ $format((int) round($gv)) }}</span>
        @endforeach
        @foreach ($points as $i => $p)
            @if ($i % $tickEvery === 0 || $i === $count - 1)
                <span class="sales-chart__xlabel" style="left: {{ round($xAt($i) / $vbW * 100, 2) }}%">{{ $p['label'] }}</span>
            @endif
        @endforeach

        <span class="sales-chart__guide" hidden></span>
        <span class="sales-chart__dot sales-chart__dot--previous" hidden></span>
        <span class="sales-chart__dot sales-chart__dot--current" hidden></span>
        <div class="sales-chart__tip" role="status" hidden></div>
    </div>

</figure>

@once
    @push('head')
    <style>
        .sales-chart { margin: 0; }
        .sales-chart__legend { display: flex; flex-wrap: wrap; gap: 1rem; font-size: .78rem; color: var(--muted); margin-bottom: .35rem; }
        .sales-chart__legend-item { display: inline-flex; align-items: center; gap: .4rem; }
        .sales-chart__swatch { display: inline-block; width: 1.4rem; height: 0; border-top: 2.5px solid var(--brand); }
        .sales-chart__swatch--previous { border-top: 2px dashed #9ca3af; }
        .sales-chart__plot { position: relative; overflow: hidden; height: clamp(240px, 30vw, 300px); margin-left: 0; color: var(--ink); cursor: crosshair; touch-action: pan-y; }
        .sales-chart__svg { position: absolute; inset: 0; width: 100%; height: 100%; display: block; overflow: hidden; }
        .sales-chart__ylabel { position: absolute; left: 0; transform: translateY(-50%); font-size: .72rem; color: var(--muted); font-variant-numeric: tabular-nums; pointer-events: none; }
        .sales-chart__xlabel { position: absolute; bottom: 0; transform: translateX(-50%); font-size: .72rem; color: var(--muted); white-space: nowrap; pointer-events: none; }
        .sales-chart__guide { position: absolute; top: 0; bottom: 0; width: 1px; background: currentColor; opacity: .25; pointer-events: none; }
        .sales-chart__dot { position: absolute; width: 10px; height: 10px; margin: -5px 0 0 -5px; border-radius: 999px; background: #fff; border: 2px solid var(--brand); pointer-events: none; }
        .sales-chart__dot--previous { border-color: #9ca3af; width: 8px; height: 8px; margin: -4px 0 0 -4px; }
        .sales-chart__tip { position: absolute; z-index: 5; min-width: 12rem; padding: .6rem .75rem; background: #111827; color: #fff; border-radius: .5rem; font-size: .78rem; line-height: 1.45; pointer-events: none; box-shadow: 0 6px 20px rgba(0,0,0,.2); font-variant-numeric: tabular-nums; }
        .sales-chart__tip strong { display: block; margin-bottom: .25rem; font-size: .8rem; }
        .sales-chart__tip .tip-row { display: flex; justify-content: space-between; gap: 1rem; }
        .sales-chart__tip .tip-row--previous { color: #9ca3af; margin-top: .3rem; }
        .sales-chart__tip .tip-sub { color: #9ca3af; }
    </style>
    @endpush

    @push('scripts')
    <script>
        (function () {
            document.querySelectorAll('[data-sales-chart]').forEach(function (fig) {
                var pts = JSON.parse(fig.getAttribute('data-points') || '[]');
                var isTickets = fig.getAttribute('data-metric') === 'tickets';
                var plot = fig.querySelector('.sales-chart__plot');
                var tip = fig.querySelector('.sales-chart__tip');
                var guide = fig.querySelector('.sales-chart__guide');
                var dotC = fig.querySelector('.sales-chart__dot--current');
                var dotP = fig.querySelector('.sales-chart__dot--previous');
                var vbW = parseFloat(fig.getAttribute('data-vb-width'));
                var vbH = 360;
                if (!pts.length || !plot) { return; }

                function esc(s) {
                    return String(s).replace(/[&<>"']/g, function (c) {
                        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
                    });
                }
                function show(e) {
                    var rect = plot.getBoundingClientRect();
                    var vx = (e.clientX - rect.left) / rect.width * vbW;
                    var best = 0, bestD = Infinity;
                    pts.forEach(function (p, i) { var d = Math.abs(p.x - vx); if (d < bestD) { bestD = d; best = i; } });
                    var p = pts[best];
                    var px = p.x / vbW * rect.width;

                    guide.style.left = px + 'px';
                    dotC.style.left = px + 'px'; dotC.style.top = (p.y / vbH * rect.height) + 'px';
                    dotP.style.left = px + 'px'; dotP.style.top = (p.py / vbH * rect.height) + 'px';

                    var main = isTickets
                        ? '<div class="tip-row"><span>Tickets</span><b>' + esc(p.tickets) + '</b></div><div class="tip-row tip-sub"><span>Revenue</span><span>' + esc(p.revenue) + '</span></div>'
                        : '<div class="tip-row"><span>Gross sales</span><b>' + esc(p.revenue) + '</b></div><div class="tip-row tip-sub"><span>Tickets</span><span>' + esc(p.tickets) + '</span></div>';
                    var prev = isTickets
                        ? esc(p.previousTickets) + ' tickets &middot; ' + esc(p.previousRevenue)
                        : esc(p.previousRevenue) + ' &middot; ' + esc(p.previousTickets) + ' tickets';
                    tip.innerHTML = '<strong>' + esc(p.label) + '</strong>' + main
                        + '<div class="tip-row tip-row--previous"><span>' + esc(p.previousLabel) + '</span></div>'
                        + '<div class="tip-row tip-row--previous"><span>' + prev + '</span></div>'
                        + '';

                    [guide, dotC, dotP, tip].forEach(function (el) { el.hidden = false; });
                    var tw = tip.offsetWidth;
                    var left = px + 14;
                    if (left + tw > rect.width) { left = px - tw - 14; }
                    tip.style.left = Math.max(0, left) + 'px';
                    tip.style.top = Math.max(0, Math.min(p.y / vbH * rect.height - 20, rect.height - tip.offsetHeight)) + 'px';
                }
                function hide() { [guide, dotC, dotP, tip].forEach(function (el) { el.hidden = true; }); }

                plot.addEventListener('pointermove', show);
                plot.addEventListener('pointerdown', show);
                plot.addEventListener('pointerleave', hide);
            });
        })();
    </script>
    @endpush
@endonce
