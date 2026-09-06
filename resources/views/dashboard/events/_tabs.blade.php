{{--
  Accessible tab strip for the manage-event page (Requirements 1.1, 1.2, 1.3, 1.8).

  This partial renders ONLY the tab strip (the tablist of buttons). The panels
  themselves are rendered as <section role="tabpanel"> elements in
  show.blade.php. The tablist is `hidden` by default so no-JS users are not
  shown dead controls (Requirement 1.3); the JS controller reveals it on init
  and adds `hidden` to the inactive panels (which the server renders visible).

  Expects:
    $tabs — ordered associative array of key => label, e.g.
      ['overview' => 'Overview', 'ticket-types' => 'Ticket types', ...]
--}}
<div class="tabs" data-tabs>
    <div class="tablist" role="tablist" aria-label="Manage event sections" hidden>
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" id="tab-{{ $key }}"
                    class="tab" data-tab="{{ $key }}"
                    aria-controls="panel-{{ $key }}"
                    aria-selected="false"
                    tabindex="-1">{{ $label }}</button>
        @endforeach
    </div>
</div>

@push('scripts')
<script>
    // Manage-event tab controller (Requirements 1.2, 1.3, 1.8).
    // Vanilla JS, no framework. Progressive enhancement: with JS disabled every
    // panel stays visible and the tab strip stays hidden. On init we reveal the
    // strip and hide the inactive panels, following the WAI-ARIA tabs pattern.
    (function () {
        var root = document.querySelector('[data-tabs]');
        if (!root) return;

        var tablist = root.querySelector('[role="tablist"]');
        var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
        if (!tablist || tabs.length === 0) return;

        // Resolve each tab to its panel via aria-controls (id panel-{key}).
        var panelFor = function (tab) {
            var id = tab.getAttribute('aria-controls');
            return id ? document.getElementById(id) : null;
        };

        // Reveal the strip now that JS is running.
        tablist.hidden = false;

        function activate(key, focusTab) {
            tabs.forEach(function (tab) {
                var isActive = tab.getAttribute('data-tab') === key;
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                tab.tabIndex = isActive ? 0 : -1;

                var panel = panelFor(tab);
                if (panel) panel.hidden = !isActive;

                if (isActive && focusTab) tab.focus();
            });

            // Deep-link without scrolling the page.
            var hash = '#tab-' + key;
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', hash);
            } else {
                var x = window.pageXOffset, y = window.pageYOffset;
                window.location.hash = hash;
                window.scrollTo(x, y);
            }
        }

        var keys = tabs.map(function (tab) { return tab.getAttribute('data-tab'); });

        function hasKey(key) {
            return key !== null && keys.indexOf(key) !== -1;
        }

        // Determine the initial tab: ?tab=KEY (task 7.1 redirect carries a
        // query, not a fragment) first, then #tab-KEY, else the first tab.
        function initialKey() {
            try {
                var qp = new URLSearchParams(window.location.search).get('tab');
                if (hasKey(qp)) return qp;
            } catch (e) { /* URLSearchParams unavailable — fall through */ }

            var hash = window.location.hash || '';
            if (hash.indexOf('#tab-') === 0) {
                var fromHash = hash.slice('#tab-'.length);
                if (hasKey(fromHash)) return fromHash;
            }

            return keys[0];
        }

        // Click activates the clicked tab.
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                activate(tab.getAttribute('data-tab'), false);
            });
        });

        // Any element with [data-tab-link="KEY"] (e.g. the "setup checklist"
        // pointers near the Publish control) activates that tab and moves focus
        // to it, so those links behave like tab navigation rather than jumping
        // to a hidden panel.
        document.querySelectorAll('[data-tab-link]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                var key = link.getAttribute('data-tab-link');
                if (!hasKey(key)) return;
                event.preventDefault();
                activate(key, true);
            });
        });

        // Keyboard nav on the tablist (roving tabindex): Left/Right move and
        // activate (wrapping), Home/End jump to first/last, Enter/Space activate
        // the focused tab.
        tablist.addEventListener('keydown', function (event) {
            var current = tabs.indexOf(document.activeElement);
            if (current === -1) return;

            var next = -1;
            switch (event.key) {
                case 'ArrowLeft':
                    next = (current - 1 + tabs.length) % tabs.length;
                    break;
                case 'ArrowRight':
                    next = (current + 1) % tabs.length;
                    break;
                case 'Home':
                    next = 0;
                    break;
                case 'End':
                    next = tabs.length - 1;
                    break;
                case 'Enter':
                case ' ':
                case 'Spacebar':
                    event.preventDefault();
                    activate(tabs[current].getAttribute('data-tab'), true);
                    return;
                default:
                    return;
            }

            event.preventDefault();
            activate(tabs[next].getAttribute('data-tab'), true);
        });

        activate(initialKey(), false);
    })();
</script>
@endpush
