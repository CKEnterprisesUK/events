@extends('layouts.app')

@section('title', $event->name.' — Ticket Types')

@section('content')
    <section>
        @if (session('status'))
            <p class="status">{{ session('status') }}</p>
        @endif

        <h1>Ticket Types for {{ $event->name }}</h1>

        @if ($ticketTypes->isEmpty())
            <p>No ticket types yet.</p>
        @else
            <ul>
                @foreach ($ticketTypes as $ticketType)
                    <li>
                        {{ $ticketType->name }} —
                        @if ($ticketType->isFree())
                            Free
                        @else
                            {{ number_format($ticketType->price_minor / 100, 2) }}
                        @endif
                        (capacity {{ $ticketType->capacity }},
                        {{-- Remaining available = capacity - sold - reserved. (Requirement 6.10) --}}
                        <span class="remaining">{{ $ticketType->availableQuantity() }} remaining</span>)
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
