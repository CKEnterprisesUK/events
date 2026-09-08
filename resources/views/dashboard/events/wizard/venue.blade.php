{{--
    Wizard step 3: Venue (Req 4.4). Reuses the existing `_location` partial
    verbatim — venue name, in-person/online toggle, address + draggable Leaflet
    mini-map, and the live "is this pinned?" status line. The partial already
    provides the online option and pushes its own Leaflet head/scripts.

    Posts to `events.wizard.save` step `venue`; the enclosing form is
    multipart-free (no uploads here) but must carry the location fields the
    partial renders. Optional step, so Skip is shown. Persistence/geocode is
    task 7.3.
--}}
@extends('dashboard.events.wizard._layout')

@section('wizard_skip', true)

@section('wizard_form_open')
    <form method="POST"
          action="{{ route('dashboard.events.wizard.save', ['event' => $event, 'step' => $currentStep]) }}"
          class="stack">
@endsection

@section('wizard_panel')
    {{-- The partial renders its own `.panel` with all location fields + map and
         expects $event in scope, which the wizard provides. --}}
    @include('dashboard.events._location', ['event' => $event])
@endsection

@section('wizard_help')
    <h2 class="wizard-help__title">Where is it held?</h2>
    <p>Pick <strong>In person</strong> or <strong>Online</strong>. For online events, customers are told they’ll receive joining details by email.</p>
    <p>For in-person events, type the address and save — we’ll try to place a pin on the map automatically. If it lands slightly off, just drag the marker to the exact spot.</p>
    <p>The map only appears on your public event page once a pin is set. No venue yet? You can skip this for now.</p>
@endsection
