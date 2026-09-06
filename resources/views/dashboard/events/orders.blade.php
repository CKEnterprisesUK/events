{{--
    Orders screen: recent orders with cancel/refund. (Requirements 10.1, 17.1)
--}}
@extends('layouts.event')

@section('active_section', 'orders')

@section('section')
    @include('dashboard.events._orders', ['recentOrders' => $recentOrders])
@endsection
