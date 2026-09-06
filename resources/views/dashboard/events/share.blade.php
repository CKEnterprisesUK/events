{{--
    Share screen: public event link + downloadable QR code.
    (Requirements 4.1, 4.2, 4.4)
--}}
@extends('layouts.event')

@section('active_section', 'share')

@section('section')
    @include('dashboard.events._share', ['publicUrl' => $publicUrl, 'event' => $event])
@endsection
