@extends('errors.layout')

@section('code', '403')
@section('heading', 'Access denied')
@section('message', $exception?->getMessage() ?: "You don't have permission to view this page. If you think this is a mistake, contact your account Owner.")

@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">Back to home</a>
    <a class="btn btn-ghost" href="{{ url('/login') }}">Log in as another user</a>
@endsection
