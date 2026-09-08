@extends('layouts.dashboard')

@section('title', 'Scanner')

@section('content')
    {{--
        Intermediary start page. It deliberately does NOT open the camera: it
        offers a single "start scanning" action and shows the recent-scan recap.
        Keeping the camera off this page lets the live scanner drop its heading
        and helper text and give the result banner the whole screen.
    --}}
    <section class="scanner-start">
        <h1>Ticket Scanner</h1>
        <p>Check attendees in at the door. Point your phone camera at a ticket
           QR code once you start scanning.</p>

        <a class="btn btn-primary scanner-start__go" href="{{ route('dashboard.scan.live') }}">
            Start scanning
        </a>

        @include('dashboard.scan._history', ['history' => $history])
    </section>
@endsection

@push('head')
    <style>
        .scanner-start__go {
            display: inline-block;
            margin: 1rem 0 0.5rem;
            font-size: 1.05rem;
            padding: 0.75rem 1.5rem;
        }
    </style>
@endpush
