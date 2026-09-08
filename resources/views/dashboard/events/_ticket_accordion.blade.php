{{--
    Ticket_Accordion (Req 6) — the list wrapper for the inline ticket-type
    accordion, replacing the old show/hide table. Renders each existing type as
    an accordion row (summary + inline edit form), an "Add ticket type" control,
    and a hidden <template> holding a blank new row.

    In scope:
      $event          — the Event being managed
      $ticketTypes    — Collection<TicketType>
      $eventRemaining — ?int overall event remaining (null = unlimited / no cap)

    The currency symbol is derived from the event's company currency, matching
    the convention used across the dashboard. Each row derives its summary
    strings server-side (see _ticket_accordion_row). The Add control clones the
    <template> client-side (JS in task 6.3); each cloned/existing row is a plain
    form POST/PUT to the existing store/update routes, so the accordion works as
    progressive enhancement. (Req 6.1, 6.2, 6.3, 10.5)
--}}
@php
    $eventRemaining = $eventRemaining ?? null;

    $currency = $event->company?->currency ?? auth()->user()->company?->currency ?? 'GBP';
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? '';
@endphp

<div class="tab-head">
    <h2>Ticket types</h2>
</div>

@if (session('status'))
    <p class="status">{{ session('status') }}</p>
@endif

<div class="panel ticket-acc" data-ticket-accordion>
    <div class="ticket-acc__list" data-acc-list>
        @forelse ($ticketTypes as $ticketType)
            @include('dashboard.events._ticket_accordion_row', [
                'event' => $event,
                'existing' => true,
                'ticketType' => $ticketType,
                'symbol' => $symbol,
                'eventRemaining' => $eventRemaining,
            ])
        @empty
            <p class="empty" data-acc-empty>No ticket types yet. Add your first one below.</p>
        @endforelse
    </div>

    <div class="ticket-acc__add">
        <button type="button" class="btn btn-sm" data-acc-add>
            <x-icon name="plus" /> Add ticket type
        </button>
    </div>

    {{-- Hidden blank new-row template — cloned by the Add control (task 6.3),
         appended expanded, and its name field focused. Posts to the store
         route. --}}
    <template data-ticket-new>
        @include('dashboard.events._ticket_accordion_row', [
            'event' => $event,
            'existing' => false,
            'ticketType' => null,
            'symbol' => $symbol,
            'eventRemaining' => $eventRemaining,
        ])
    </template>
</div>

@push('scripts')
<script>
    (function () {
        // Ticket_Accordion behaviour (Req 6.4, 6.5, 6.8, 6.11). Progressive
        // enhancement: without JS every row is a plain form POST/PUT and the
        // summary <button> simply does nothing. Guarded on the root existing.
        var root = document.querySelector('[data-ticket-accordion]');
        if (!root) return;

        // Collapse every open row except `except` (single-open, Req 6.5).
        function collapseAll(except) {
            root.querySelectorAll('[data-acc-toggle]').forEach(function (btn) {
                if (btn === except) return;
                var body = document.getElementById(btn.getAttribute('aria-controls'));
                btn.setAttribute('aria-expanded', 'false');
                if (body) body.hidden = true;
            });
        }

        // Click-anywhere on the summary toggles (Req 6.4). The summary is one
        // <button>, so Enter/Space and focus work natively (Req 6.11); the
        // edit-form controls live in a sibling body element below the summary,
        // never inside it, so they never trigger a toggle. Cancel discards edits
        // by resetting the form to its server-rendered values (Req 6.8).
        root.addEventListener('click', function (e) {
            var toggle = e.target.closest('[data-acc-toggle]');
            if (toggle && root.contains(toggle)) {
                var body = document.getElementById(toggle.getAttribute('aria-controls'));
                if (!body) return;
                var open = toggle.getAttribute('aria-expanded') === 'true';
                if (open) {
                    toggle.setAttribute('aria-expanded', 'false');
                    body.hidden = true;
                } else {
                    collapseAll(toggle);
                    toggle.setAttribute('aria-expanded', 'true');
                    body.hidden = false;
                }
                return;
            }

            var cancel = e.target.closest('[data-acc-cancel]');
            if (cancel && root.contains(cancel)) {
                var b = cancel.closest('.ticket-acc__body');
                if (!b) return;
                var t = root.querySelector('[aria-controls="' + b.id + '"]');
                var form = b.querySelector('form');
                if (form) {
                    // Discard edits: restore the server-rendered values, then
                    // re-sync the conditional availability/sales inputs so their
                    // hidden/disabled state matches the reset selection.
                    form.reset();
                    if (window.__ticketSyncControls) window.__ticketSyncControls(b);
                }
                if (t) {
                    t.setAttribute('aria-expanded', 'false');
                    t.focus();
                }
                b.hidden = true;
            }
        });

        // "Add ticket type": clone the blank <template>, append it expanded,
        // collapse the others, and focus the name field (Req 6.6). Each cloned
        // row is a plain form POSTing to the store route — no AJAX/draft state.
        var addBtn = root.querySelector('[data-acc-add]');
        var tpl = root.querySelector('[data-ticket-new]');
        if (addBtn && tpl && 'content' in tpl) {
            addBtn.addEventListener('click', function () {
                var list = root.querySelector('[data-acc-list]');
                if (!list) return;

                // Drop the "no ticket types yet" placeholder on first add.
                var empty = list.querySelector('[data-acc-empty]');
                if (empty) empty.remove();

                list.appendChild(tpl.content.cloneNode(true));

                var last = list.querySelector('.ticket-acc__item:last-child');
                if (!last) return;
                var t = last.querySelector('[data-acc-toggle]');
                var body = last.querySelector('.ticket-acc__body');
                collapseAll(t);
                if (t) t.setAttribute('aria-expanded', 'true');
                if (body) {
                    body.hidden = false;
                    if (window.__ticketSyncControls) window.__ticketSyncControls(body);
                }
                var nameInput = last.querySelector('input[name="name"]');
                if (nameInput) nameInput.focus();
            });
        }

        // ---- Availability + Sales-period controls (Req 7, 8) ---------------
        // These live here (rather than in the control partials) so the cloned
        // template rows are wired the same way as server-rendered rows. A single
        // delegated `change` listener plus a re-sync helper keep every row —
        // existing or freshly cloned — consistent.

        // Availability (Req 7.2–7.5): show the quantity input only for `capped`,
        // and disable it otherwise so a hidden quantity is never submitted for
        // shared_pool / unlimited.
        function syncAvailability(fieldset) {
            var qtyField = fieldset.querySelector('[data-capacity-field]');
            if (!qtyField) return;
            var qtyInput = qtyField.querySelector('input[name="capacity"]');
            var selected = fieldset.querySelector('[data-availability]:checked');
            var capped = selected && selected.value === 'capped';
            qtyField.hidden = !capped;
            if (qtyInput) {
                qtyInput.disabled = !capped;
                if (capped) {
                    qtyInput.setAttribute('required', 'required');
                } else {
                    qtyInput.removeAttribute('required');
                }
            }
        }

        // Sales period (Req 8.3–8.6, 8.9): a checked "use default" checkbox
        // hides AND disables its datetime input so it isn't submitted (the
        // controller then persists null); unchecking reveals + enables it.
        function bindSales(chk, box) {
            if (!chk || !box) return;
            var input = box.querySelector('input');
            function sync() {
                box.hidden = chk.checked;
                if (input) input.disabled = chk.checked;
            }
            // Avoid double-binding when re-syncing a cloned/reset row.
            if (!chk.__salesBound) {
                chk.addEventListener('change', sync);
                chk.__salesBound = true;
            }
            sync();
        }

        // Re-sync all availability + sales controls within a scope (whole root,
        // or a single row body after clone/reset). Exposed for the cancel/add
        // handlers above.
        function syncControls(scope) {
            (scope || root).querySelectorAll('.ticket-form__availability').forEach(syncAvailability);
            (scope || root).querySelectorAll('[data-sales-start-default]').forEach(function (chk) {
                bindSales(chk, chk.closest('fieldset').querySelector('[data-sales-start]'));
            });
            (scope || root).querySelectorAll('[data-sales-end-default]').forEach(function (chk) {
                bindSales(chk, chk.closest('fieldset').querySelector('[data-sales-end]'));
            });
        }
        window.__ticketSyncControls = syncControls;

        // Delegated change handler covers existing rows and any cloned later.
        root.addEventListener('change', function (e) {
            if (e.target.matches('[data-availability]')) {
                var fs = e.target.closest('.ticket-form__availability');
                if (fs) syncAvailability(fs);
            }
        });

        // Initial pass over the server-rendered rows.
        syncControls(root);
    })();
</script>
@endpush
