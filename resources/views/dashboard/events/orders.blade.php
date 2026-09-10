{{--
    Orders screen: recent orders with cancel/refund. (Requirements 10.1, 17.1)
--}}
@extends('layouts.event')

@section('active_section', 'orders')

{{-- Orders list has its own table; suppress the shared hero banner here. --}}
@section('hide_hero', '1')

@section('section')
    @include('dashboard.events._orders', ['recentOrders' => $recentOrders])
@endsection
