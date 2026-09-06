@extends('layouts.app')

@section('title', 'Stripe connection')

@section('content')
    <section>
        <h1>Stripe connection</h1>

        @if (! $connected)
            {{-- No connected account: display status as not connected. (Requirement 11.5) --}}
            <p data-status="not_connected">Not connected</p>
            <p>Connect your Stripe account so ticket revenue is paid directly to your organisation.</p>
        @elseif ($chargesEnabled)
            <p data-status="charges_enabled">Connected — charges enabled</p>
            <p>Your Stripe account is connected and can accept paid ticket sales.</p>
        @else
            <p data-status="charges_disabled">Connected — charges not yet enabled</p>
            <p>Your Stripe account is connected but cannot accept payments yet. Finish Stripe onboarding to enable paid ticket sales.</p>
        @endif

        <form method="POST" action="{{ route('dashboard.stripe.start') }}">
            @csrf
            <button type="submit">
                {{ $connected ? 'Continue Stripe onboarding' : 'Connect Stripe' }}
            </button>
        </form>
    </section>
@endsection
