{{-- Publish-readiness checklist (Requirements 2.1, 2.2, 2.3, 2.4).
     Presentational only: iterates $readiness->items(), each a
     App\Services\Events\ChecklistItem with ->key, ->label, ->satisfied, ->blocking. --}}
<div class="panel">
    <div class="panel__head"><h2>Setup checklist</h2></div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Item</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($readiness->items() as $item)
                <tr>
                    <td><span class="cell-strong">{{ $item->label }}</span></td>
                    <td>
                        @if ($item->satisfied)
                            <span class="pill pill--live">&check; Done</span>
                        @else
                            <span class="pill pill--draft">&mdash; Not yet</span>
                        @endif
                    </td>
                    <td class="num">
                        @if ($item->blocking)
                            <span class="pill pill--draft">Required to publish</span>
                        @else
                            <span class="cell-dim">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="hint">Items marked “Required to publish” must be satisfied before this event can go live.</p>
</div>
