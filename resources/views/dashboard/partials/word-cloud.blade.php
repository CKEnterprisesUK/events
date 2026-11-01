{{--
    Reusable, dependency-free word cloud for free-text answers. Each word is
    sized between two bounds in proportion to its frequency, so the common
    themes in a free-text question stand out at a glance. No JavaScript.

    Expects:
      $words — list of ['text' => string, 'count' => int], already tallied and
               ordered by the caller (most frequent first reads best). An empty
               list renders an empty-state line.

    Sizing: the most frequent word renders at the max font size, the least at
    the min, linearly scaled. A single distinct word renders at the max size.
--}}
@php
    $words = array_values($words ?? []);
    $counts = array_map(fn ($w) => (int) $w['count'], $words);
    $max = $counts === [] ? 0 : max($counts);
    $min = $counts === [] ? 0 : min($counts);
    $minRem = 0.85; $maxRem = 2.2;
@endphp

@if ($words === [])
    <p class="chart-empty">No free-text answers to summarise yet.</p>
@else
    <div class="word-cloud" role="img" aria-label="Word cloud of the most common terms in the answers.">
        @foreach ($words as $word)
            @php
                $c = (int) $word['count'];
                // Linear scale between min and max; guard the single-value case.
                $size = $max === $min
                    ? $maxRem
                    : $minRem + (($c - $min) / ($max - $min)) * ($maxRem - $minRem);
                // Dim the rarer terms a little so the frequent ones carry.
                $opacity = $max === $min ? 1 : 0.55 + (($c - $min) / ($max - $min)) * 0.45;
            @endphp
            <span class="word-cloud__term" style="font-size: {{ round($size, 2) }}rem; opacity: {{ round($opacity, 2) }};"
                  title="{{ $word['text'] }}: {{ $c }} {{ \Illuminate\Support\Str::plural('mention', $c) }}">{{ $word['text'] }}</span>
        @endforeach
    </div>
@endif
