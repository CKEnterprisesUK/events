@extends('layouts.dashboard')

@section('title', 'Super-Admin — Error reports')

@push('head')
<style>
    .err-filter { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin: 1rem 0 1.25rem; }
    .err-filter .field { margin: 0; }
    .err-filter input, .err-filter select { padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 0.5rem; }
    .err-filter label { display: block; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 0.25rem; }
    .err-filter .check { display: flex; align-items: center; gap: 0.4rem; }
    .err-ref { font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; font-weight: 700; }
    .err-status { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
    .err-status.is-open { color: #b45309; }
    .err-status.is-resolved { color: #047857; }
    .err-exc { color: var(--muted); font-size: 0.82rem; }
    .err-msg { display: block; max-width: 42ch; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pager { display: flex; justify-content: center; padding: 1rem; }
</style>
@endpush

@section('content')
    <div class="admin-head">
        <div>
            <h1>Error reports</h1>
            <p>Uncaught server errors captured across every company. Paste a customer's reference (e.g. <code>ERR-3F9A2B7C</code>) to find the full trace. {{ number_format($unresolvedCount) }} unresolved.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    @php
        $colors = [403 => '#d97706', 404 => '#2563eb', 500 => '#dc2626'];
        $labels = [403 => '403 Forbidden', 404 => '404 Not found', 500 => '500 Server error'];
        $dayCount = count($stats['days']);
        $totals = [];
        for ($i = 0; $i < $dayCount; $i++) {
            $totals[$i] = array_sum(array_map(fn ($s) => $s[$i], $stats['series']));
        }
        $maxTotal = max(1, max($totals ?: [0]));
        $baselineTotal = array_sum($stats['baseline']);
        $maxY = max($maxTotal, $baselineTotal, 1);
        $w = 720; $h = 220; $padL = 36; $padB = 24; $padT = 10;
        $plotW = $w - $padL - 8; $plotH = $h - $padB - $padT;
        $slot = $plotW / max(1, $dayCount);
        $barW = max(2, $slot * 0.7);
        $yBase = $padT + $plotH;
        $yOf = fn ($v) => $yBase - ($v / $maxY) * $plotH;
        $hasData = array_sum($totals) > 0;
    @endphp

    <div class="admin-panel" style="margin-bottom:1.25rem;">
        <div class="admin-panel__head"><h2>Errors by day (last 30 days)</h2></div>
        <div style="padding:1rem;">
            @if (! $hasData)
                <div class="admin-empty">No 403, 404 or 500 responses recorded yet. Counting starts once this version is deployed.</div>
            @else
                <svg viewBox="0 0 {{ $w }} {{ $h }}" role="img" style="width:100%;height:auto;max-width:{{ $w }}px;"
                     aria-label="Stacked bar chart of 403, 404 and 500 responses per day, with a dashed line at the usual daily total of {{ round($baselineTotal, 1) }}.">
                    @foreach ([0, 0.5, 1] as $t)
                        @php $gy = $yOf($maxY * $t); @endphp
                        <line x1="{{ $padL }}" x2="{{ $w - 8 }}" y1="{{ $gy }}" y2="{{ $gy }}" stroke="#e5e7eb" stroke-width="1"/>
                        <text x="{{ $padL - 4 }}" y="{{ $gy + 3 }}" font-size="10" text-anchor="end" fill="#6b7280">{{ number_format($maxY * $t) }}</text>
                    @endforeach
                    @for ($i = 0; $i < $dayCount; $i++)
                        @php
                            $x = $padL + $i * $slot + ($slot - $barW) / 2;
                            $stack = 0;
                            $day = \Illuminate\Support\Carbon::parse($stats['days'][$i]);
                        @endphp
                        @foreach ([500, 403, 404] as $code)
                            @php
                                $v = $stats['series'][$code][$i];
                                $barH = ($v / $maxY) * $plotH;
                                $y = $yBase - (($stack + $v) / $maxY) * $plotH;
                                $stack += $v;
                            @endphp
                            @if ($v > 0)
                                <rect x="{{ round($x, 1) }}" y="{{ round($y, 1) }}" width="{{ round($barW, 1) }}" height="{{ round($barH, 1) }}" fill="{{ $colors[$code] }}">
                                    <title>{{ $day->format('j M') }}: {{ $v }} × {{ $labels[$code] }}</title>
                                </rect>
                            @endif
                        @endforeach
                        @if ($i % 5 === 0 || $i === $dayCount - 1)
                            <text x="{{ round($x + $barW / 2, 1) }}" y="{{ $h - 8 }}" font-size="10" text-anchor="middle" fill="#6b7280">{{ $day->format('j M') }}</text>
                        @endif
                    @endfor
                    @if ($baselineTotal > 0)
                        <line x1="{{ $padL }}" x2="{{ $w - 8 }}" y1="{{ $yOf($baselineTotal) }}" y2="{{ $yOf($baselineTotal) }}" stroke="#111827" stroke-width="1.5" stroke-dasharray="5 4"/>
                    @endif
                </svg>

                <p style="display:flex;gap:1rem;flex-wrap:wrap;font-size:0.82rem;margin:0.5rem 0 0;">
                    @foreach ($labels as $code => $label)
                        <span><span aria-hidden="true" style="display:inline-block;width:0.7rem;height:0.7rem;background:{{ $colors[$code] }};border-radius:2px;margin-right:0.3rem;"></span>{{ $label }}</span>
                    @endforeach
                    <span><span aria-hidden="true" style="display:inline-block;width:1rem;border-top:2px dashed #111827;margin-right:0.3rem;vertical-align:middle;"></span>Usual daily total</span>
                </p>

                <table class="admin-table" style="margin-top:1rem;">
                    <thead>
                        <tr>
                            <th scope="col">Type</th>
                            <th scope="col">Today</th>
                            <th scope="col">Usual per day</th>
                            <th scope="col">Last 30 days</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($labels as $code => $label)
                            @php $today = $stats['series'][$code][$dayCount - 1]; $usual = $stats['baseline'][$code]; @endphp
                            <tr>
                                <td>{{ $label }}</td>
                                <td>
                                    {{ number_format($today) }}
                                    @if ($today > 0 && $today >= max(10, 2 * $usual))
                                        <strong style="color:#b91c1c;">above usual</strong>
                                    @endif
                                </td>
                                <td>{{ number_format($usual, 1) }}</td>
                                <td>{{ number_format(array_sum($stats['series'][$code])) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p style="color:var(--muted);font-size:0.82rem;margin:0.75rem 0 0;">Super_Admins are emailed when an hour runs well above its usual level for any of these.</p>
            @endif
        </div>
    </div>

    <form method="GET" action="{{ route('admin.errors.index') }}" class="err-filter">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" name="q" id="q" value="{{ $search }}" placeholder="Reference, URL or message">
        </div>
        <div class="field">
            <label for="company_id">Company</label>
            <select name="company_id" id="company_id">
                <option value="0">All companies</option>
                @foreach ($companies as $id => $name)
                    <option value="{{ $id }}" @selected($companyId === (int) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field check">
            <input type="checkbox" name="unresolved" id="unresolved" value="1" @checked($unresolvedOnly)>
            <label for="unresolved" style="margin:0;text-transform:none;letter-spacing:0;">Unresolved only</label>
        </div>
        <button type="submit" class="btn">Filter</button>
        <a class="btn btn-outline" href="{{ route('admin.errors.index') }}">Clear</a>
    </form>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Captured errors</h2></div>
        @if ($reports->isEmpty())
            <div class="admin-empty">No errors for the selected filters.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Reference</th>
                        <th scope="col">Error</th>
                        <th scope="col">Company</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reports as $report)
                        <tr>
                            <td title="{{ optional($report->created_at)->format('j M Y, H:i:s') }}">{{ optional($report->created_at)->format('j M Y, H:i') }}</td>
                            <td>
                                <a href="{{ route('admin.errors.show', $report->id) }}" class="err-ref">{{ $report->reference }}</a>
                            </td>
                            <td>
                                <span class="err-exc">{{ $report->shortExceptionClass() }}</span>
                                <span class="err-msg" title="{{ $report->message }}">{{ $report->message ?: '—' }}</span>
                            </td>
                            <td>{{ $report->company?->name ?? '—' }}</td>
                            <td>
                                <span class="err-status {{ $report->isResolved() ? 'is-resolved' : 'is-open' }}">
                                    {{ $report->isResolved() ? 'Resolved' : 'Open' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="pager">{{ $reports->links() }}</div>
        @endif
    </div>
@endsection
