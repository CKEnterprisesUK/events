{{--
    Reusable, dependency-free inline-SVG line chart. Draws one or two series as
    smooth polylines on a shared axis with light gridlines, Y-axis value labels,
    X-axis tick labels and an accessible text summary. No JavaScript, no chart
    library — just SVG computed in Blade, so it renders in the PDF print view
    too.

    Expects:
      $series   — list of series, each:
                  [
                    'name'   => string,                       // legend label
                    'color'  => string,                       // CSS colour / var()
                    'points' => list<['label' => string, 'value' => int|float]>,
                  ]
                  Every series must share the same number of points and the same
                  ordered labels (the caller aligns them).
      $formatY  — optional callable(float): string to format Y-axis / summary
                  values (e.g. money). Defaults to a thousands-separated number.
      $height   — optional px height of the plot (default 220).
      $yLabel   — optional axis caption shown in the summary.

    Degrades to an empty-state line when there is no data to plot.
--}}
@php
    $series = collect($series ?? [])
        ->filter(fn ($s) => ! empty($s['points']))
        ->values();

    $formatY = $formatY ?? fn ($v) => number_format((float) $v);
    $plotH = (int) ($height ?? 220);
    $yLabel = $yLabel ?? null;

    // Shared label axis from the first series (all series share labels).
    $labels = $series->isNotEmpty() ? array_column($series[0]['points'], 'label') : [];
    $count = count($labels);

    // Peak across every series so they share one vertical scale.
    $max = 0.0;
    foreach ($series as $s) {
        foreach ($s['points'] as $p) {
            $max = max($max, (float) $p['value']);
        }
    }
    $hasData = $series->isNotEmpty() && $count > 0 && $max > 0;

    // viewBox geometry. Generous left gutter for Y labels, bottom gutter for
    // X labels. Coordinates are in viewBox units; the SVG scales responsively.
    $vbW = 100.0;
    $vbH = 56.0;
    $padL = 11.0; $padR = 2.0; $padT = 3.0; $padB = 8.0;
    $plotW = $vbW - $padL - $padR;
    $plotArea = $vbH - $padT - $padB;
    $niceMax = max(1.0, $max);
    $stepX = $count > 1 ? $plotW / ($count - 1) : 0.0;

    $xAt = fn (int $i) => $padL + $stepX * $i;
    $yAt = fn (float $v) => $padT + $plotArea - (($v / $niceMax) * $plotArea);

    // Three horizontal gridlines (0, mid, max) with value labels.
    $grid = [0.0, $niceMax / 2, $niceMax];

    // Choose a handful of X tick labels so they don't collide.
    $maxTicks = 6;
    $tickEvery = $count > $maxTicks ? (int) ceil($count / $maxTicks) : 1;
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

        <svg class="line-chart__svg" viewBox="0 0 {{ $vbW }} {{ $vbH }}" preserveAspectRatio="none"
             role="img" style="height: {{ $plotH }}px;"
             aria-label="Line chart{{ $yLabel ? ' of '.$yLabel : '' }} across {{ $count }} points.">
            {{-- Gridlines + Y labels. --}}
            @foreach ($grid as $gv)
                @php $gy = $yAt($gv); @endphp
                <line x1="{{ round($padL, 2) }}" y1="{{ round($gy, 2) }}"
                      x2="{{ round($vbW - $padR, 2) }}" y2="{{ round($gy, 2) }}"
                      stroke="currentColor" stroke-width="0.15" opacity="0.18" />
                <text x="{{ round($padL - 1, 2) }}" y="{{ round($gy + 0.9, 2) }}"
                      font-size="2.2" text-anchor="end" fill="currentColor" opacity="0.55">{{ $formatY($gv) }}</text>
            @endforeach

            {{-- Each series: a soft area fill under a solid line, plus dots. --}}
            @foreach ($series as $s)
                @php
                    $pts = $s['points'];
                    $coords = [];
                    foreach ($pts as $i => $p) {
                        $coords[] = round($xAt($i), 2).','.round($yAt((float) $p['value']), 2);
                    }
                    $line = implode(' ', $coords);
                    // Area polygon: the line, then back along the baseline.
                    $baseY = round($yAt(0), 2);
                    $area = $line.' '.round($xAt($count - 1), 2).','.$baseY.' '.round($xAt(0), 2).','.$baseY;
                @endphp
                <polygon points="{{ $area }}" fill="{{ $s['color'] }}" opacity="0.08" />
                <polyline points="{{ $line }}" fill="none" stroke="{{ $s['color'] }}"
                          stroke-width="0.7" stroke-linejoin="round" stroke-linecap="round" />
                @if ($count <= 31)
                    @foreach ($pts as $i => $p)
                        <circle cx="{{ round($xAt($i), 2) }}" cy="{{ round($yAt((float) $p['value']), 2) }}"
                                r="0.55" fill="{{ $s['color'] }}">
                            <title>{{ $labels[$i] ?? '' }}: {{ $formatY((float) $p['value']) }}</title>
                        </circle>
                    @endforeach
                @endif
            @endforeach

            {{-- X tick labels. --}}
            @foreach ($labels as $i => $label)
                @if ($i % $tickEvery === 0 || $i === $count - 1)
                    <text x="{{ round($xAt($i), 2) }}" y="{{ round($vbH - 1.5, 2) }}"
                          font-size="2.1" text-anchor="middle" fill="currentColor" opacity="0.6">{{ $label }}</text>
                @endif
            @endforeach
        </svg>
    </figure>
@endif
