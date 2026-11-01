{{--
    Reusable, dependency-free inline-SVG line chart. Draws one or more series as
    polylines on a shared axis with light gridlines, "nice" rounded Y-axis
    labels, thinned X-axis labels and an accessible text summary. No JavaScript,
    no chart library — just SVG computed in Blade, so it renders in the PDF
    print view too.

    The SVG uses a real (undistorted) coordinate space and
    preserveAspectRatio="xMidYMid meet", so strokes, dots and text keep a
    consistent, correct size at any container width — unlike a stretched
    viewBox, which squashes the plot and bloats the line/dots/text.

    Expects:
      $series   — list of series, each:
                  [
                    'name'   => string,                       // legend label
                    'color'  => string,                       // CSS colour / var()
                    'points' => list<['label' => string, 'value' => int|float]>,
                  ]
                  Every series shares the same ordered labels (the caller aligns
                  them).
      $formatY  — optional callable(float): string to format Y-axis / summary
                  values (e.g. money). Defaults to a thousands-separated number.
      $height   — optional px height of the plot (default 240).
      $yLabel   — optional axis caption used in the accessible summary.

    Degrades to an empty-state line when there is no data to plot.
--}}
@php
    $series = collect($series ?? [])
        ->filter(fn ($s) => ! empty($s['points']))
        ->values();

    $formatY = $formatY ?? fn ($v) => number_format((float) $v);
    $plotH = (int) ($height ?? 240);
    $yLabel = $yLabel ?? null;

    // Shared label axis from the first series (all series share labels).
    $labels = $series->isNotEmpty() ? array_column($series[0]['points'], 'label') : [];
    $count = count($labels);

    // Peak across every series so they share one vertical scale.
    $rawMax = 0.0;
    foreach ($series as $s) {
        foreach ($s['points'] as $p) {
            $rawMax = max($rawMax, (float) $p['value']);
        }
    }
    $hasData = $series->isNotEmpty() && $count > 0 && $rawMax > 0;

    // Round the axis top up to a "nice" number so the gridline labels read
    // cleanly (e.g. 1,294.70 -> 1,500) and the grid divides into even bands.
    $niceCeil = function (float $v): float {
        if ($v <= 0) return 1.0;
        $exp = floor(log10($v));
        $pow = 10 ** $exp;
        $frac = $v / $pow;
        $nice = $frac <= 1 ? 1 : ($frac <= 2 ? 2 : ($frac <= 2.5 ? 2.5 : ($frac <= 5 ? 5 : 10)));
        return $nice * $pow;
    };
    $niceMax = $niceCeil($rawMax);

    // A real pixel-space viewBox (undistorted). Width scales with the number of
    // points so a 24h/30d series has room to breathe; the SVG itself is sized
    // responsively by CSS (width:100%) with this viewBox preserving aspect.
    $vbH = 320.0;
    $vbW = max(760.0, 40.0 + $count * 26.0);
    $padL = 64.0; $padR = 24.0; $padT = 16.0; $padB = 40.0;
    $plotW = $vbW - $padL - $padR;
    $plotArea = $vbH - $padT - $padB;
    $stepX = $count > 1 ? $plotW / ($count - 1) : 0.0;

    $xAt = fn (int $i) => $padL + $stepX * $i;
    $yAt = fn (float $v) => $padT + $plotArea - (($v / $niceMax) * $plotArea);

    // Four horizontal grid bands (five lines incl. 0 and max).
    $gridLines = 4;
    $grid = [];
    for ($g = 0; $g <= $gridLines; $g++) {
        $grid[] = $niceMax * ($g / $gridLines);
    }

    // Thin X tick labels so they never collide, always keeping first + last.
    $maxTicks = 8;
    $tickEvery = $count > $maxTicks ? (int) ceil($count / $maxTicks) : 1;

    // Only draw point markers when the series is short enough that they read as
    // markers rather than a beaded string.
    $showDots = $count > 1 && $count <= 16;
@endphp

@if (! $hasData)
    <p class="chart-empty">No data to chart yet.</p>
@else
    <figure class="line-chart">
        @if ($series->count() > 1)
            <figcaption class="line-chart__legend">
                @foreach ($series as $s)
                    <span class="line-chart__legend-item">
                        <span class="line-chart__swatch" style="background: {{ $s['color'] }}"></span>
                        {{ $s['name'] }}
                    </span>
                @endforeach
            </figcaption>
        @endif

        {{-- Accessible equivalent: each series' total and peak in words. --}}
        <p class="sr-only">
            @foreach ($series as $s)
                @php
                    $vals = array_map(fn ($p) => (float) $p['value'], $s['points']);
                    $sum = array_sum($vals);
                    $peakIdx = array_keys($vals, max($vals))[0] ?? 0;
                @endphp
                {{ $s['name'] }}: total {{ $formatY($sum) }}, peaking at {{ $formatY(max($vals)) }}
                on {{ $labels[$peakIdx] ?? '' }}.
            @endforeach
        </p>

        <svg class="line-chart__svg" viewBox="0 0 {{ round($vbW) }} {{ round($vbH) }}"
             preserveAspectRatio="xMidYMid meet" role="img" style="max-height: {{ $plotH }}px;"
             aria-label="Line chart{{ $yLabel ? ' of '.$yLabel : '' }} across {{ $count }} points.">
            {{-- Gridlines + Y labels. --}}
            @foreach ($grid as $gv)
                @php $gy = $yAt($gv); @endphp
                <line x1="{{ round($padL, 1) }}" y1="{{ round($gy, 1) }}"
                      x2="{{ round($vbW - $padR, 1) }}" y2="{{ round($gy, 1) }}"
                      stroke="currentColor" stroke-width="1" opacity="{{ $gv == 0 ? '0.35' : '0.12' }}" />
                <text x="{{ round($padL - 10, 1) }}" y="{{ round($gy + 4, 1) }}"
                      font-size="13" text-anchor="end" fill="currentColor" opacity="0.6">{{ $formatY($gv) }}</text>
            @endforeach

            {{-- Each series: a soft area fill under a solid line, plus dots. --}}
            @foreach ($series as $s)
                @php
                    $pts = $s['points'];
                    $coords = [];
                    foreach ($pts as $i => $p) {
                        $coords[] = round($xAt($i), 1).','.round($yAt((float) $p['value']), 1);
                    }
                    $line = implode(' ', $coords);
                    $baseY = round($yAt(0), 1);
                    $area = $line.' '.round($xAt($count - 1), 1).','.$baseY.' '.round($xAt(0), 1).','.$baseY;
                @endphp
                @if ($series->count() === 1)
                    <polygon points="{{ $area }}" fill="{{ $s['color'] }}" opacity="0.1" />
                @endif
                <polyline points="{{ $line }}" fill="none" stroke="{{ $s['color'] }}"
                          stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
                @if ($showDots)
                    @foreach ($pts as $i => $p)
                        <circle cx="{{ round($xAt($i), 1) }}" cy="{{ round($yAt((float) $p['value']), 1) }}"
                                r="3.5" fill="#fff" stroke="{{ $s['color'] }}" stroke-width="2">
                            <title>{{ $labels[$i] ?? '' }}: {{ $formatY((float) $p['value']) }}</title>
                        </circle>
                    @endforeach
                @endif
            @endforeach

            {{-- X tick labels. --}}
            @foreach ($labels as $i => $label)
                @if ($i % $tickEvery === 0 || $i === $count - 1)
                    <text x="{{ round($xAt($i), 1) }}" y="{{ round($vbH - 14, 1) }}"
                          font-size="13" text-anchor="middle" fill="currentColor" opacity="0.65">{{ $label }}</text>
                @endif
            @endforeach
        </svg>
    </figure>
@endif
