{{--
    Reusable, dependency-free horizontal bar chart (CSS, no SVG). Good for
    categorical breakdowns — question option counts, a numeric histogram — where
    labels can be long and a horizontal layout reads best. Each bar shows its
    label, a proportional fill and the raw value (plus a share-of-total %).

    Expects:
      $bars  — list of ['label' => string, 'value' => int|float], in display
               order. An empty list renders an empty-state line.
      $color — optional CSS colour / var() for the fill (default: brand).
      $total — optional denominator for the percentage (defaults to the sum of
               values). Pass the response count so "% of respondents" is honest
               even when one respondent picks multiple options.
--}}
@php
    $bars = array_values($bars ?? []);
    $color = $color ?? 'var(--brand)';
    $values = array_map(fn ($b) => (float) $b['value'], $bars);
    $max = $values === [] ? 0 : max($values);
    $sum = array_sum($values);
    $denom = isset($total) && $total > 0 ? (float) $total : $sum;
@endphp

@if ($bars === [] || $max <= 0)
    <p class="chart-empty">No responses to chart yet.</p>
@else
    <ul class="bar-chart" role="img" aria-label="Bar chart of {{ count($bars) }} categories.">
        @foreach ($bars as $bar)
            @php
                $v = (float) $bar['value'];
                $widthPct = $max > 0 ? ($v / $max) * 100 : 0;
                $sharePct = $denom > 0 ? ($v / $denom) * 100 : 0;
            @endphp
            <li class="bar-chart__row">
                <span class="bar-chart__label" title="{{ $bar['label'] }}">{{ $bar['label'] }}</span>
                <span class="bar-chart__track">
                    <span class="bar-chart__fill" style="width: {{ round($widthPct, 1) }}%; background: {{ $color }};"></span>
                </span>
                <span class="bar-chart__value">
                    {{ number_format($v) }}
                    @if ($denom > 0)<span class="bar-chart__pct">({{ number_format($sharePct, 0) }}%)</span>@endif
                </span>
            </li>
        @endforeach
    </ul>
@endif
