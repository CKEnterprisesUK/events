@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <section>
        <h1>Dashboard</h1>
        <p>Welcome back, {{ auth()->user()->name }}.</p>
    </section>
@endsection
