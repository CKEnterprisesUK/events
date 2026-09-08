{{--
    Wizard step 4: Tickets (Req 4.5). Reuses the `_ticket_accordion` component,
    which manages its own per-row <form>s posting straight to the ticket-type
    store/update/destroy routes — so ticket types are created/edited inline as
    the user adds them, independent of the wizard's advance form.

    Because those row forms cannot be nested inside another <form>, this step
    renders in the layout's "bare" mode: the accordion sits outside the wizard
    form and only the Back/Skip/Continue nav is wrapped in a minimal advance
    form (posting to `events.wizard.save` step `tickets`). Optional step → Skip.

    The accordion needs $event, $ticketTypes, and the overall $eventRemaining.
    We pass the draft's own ticket types and its overall remaining.
--}}
@extends('dashboard.events.wizard._layout')

@section('wizard_skip', true)

@section('wizard_panel_bare')
    @include('dashboard.events._ticket_accordion', [
        'event' => $event,
        'ticketTypes' => $event->ticketTypes()->latest()->get(),
        'eventRemaining' => $event->overallRemaining(),
    ])
@endsection

@section('wizard_help')
    <h2 class="wizard-help__title">Set up your tickets</h2>
    <p>Add each type of ticket you want to sell — for example <em>General</em>, <em>VIP</em>, or <em>Early bird</em>. Enter <strong>0</strong> for a free ticket.</p>
    <p><strong>Availability</strong> controls how many can sell: a fixed <em>capped</em> number, a shared event-wide pool, or <em>unlimited</em>.</p>
    <p><strong>Sales period</strong> defaults to on sale from publication until the event starts. Untick a default to set your own open or close time.</p>
    <p>Each ticket saves on its own as you add it. You can always come back and add more before publishing.</p>
@endsection
