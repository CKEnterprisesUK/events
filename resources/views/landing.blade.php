@extends('layouts.app')

@section('title', config('app.name', 'Event Ticketing Platform'))

@section('content')
    <section>
        <h1>Sell tickets from your own branded storefront</h1>
        <p>
            The Event Ticketing Platform lets charities and event organisers sell tickets
            online, collect payments directly into their own Stripe account, and check
            attendees in with a phone-browser QR scanner.
        </p>
        <p>
            <a class="btn" href="{{ url('/login') }}">Log in to your dashboard</a>
        </p>
    </section>
@endsection
