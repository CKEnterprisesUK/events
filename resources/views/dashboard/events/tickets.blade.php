{{--
    Tickets screen: ticket-type management + complimentary issuance.
    (Requirements 6.1, 6.3, 2.6, 18.1)
--}}
@extends('layouts.event')

@section('active_section', 'tickets')

@section('section')
    @include('dashboard.events._ticket_types', [
        'event' => $event,
        'ticketTypes' => $ticketTypes,
        'eventRemaining' => $eventRemaining,
    ])
    @include('dashboard.events._comp', [
        'event' => $event,
        'ticketTypes' => $ticketTypes,
        'eventRemaining' => $eventRemaining,
    ])
@endsection
