@extends('errors.layout')

@section('code', '404')
@section('heading', 'Page not found')
@section('message', "We couldn't find the page you were looking for. It may have been moved, or the link might be out of date.")

@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">Back to home</a>
    <a class="btn btn-ghost" href="{{ url('/login') }}">Go to login</a>
@endsection
