@extends('errors.layout')

@section('code', '500')
@section('heading', 'Something went wrong')
@section('message', "We hit an unexpected problem and couldn't complete your request. Our team has been notified and is looking into it.")

@section('actions')
    @if (! empty($reference))
        <div style="width:100%; margin: -0.5rem 0 1.5rem;">
            <p style="color:#9aa0b5; font-size:0.95rem; margin:0 0 0.5rem;">
                If you contact support, please quote this reference:
            </p>
            <code style="display:inline-block; font-family:'SFMono-Regular',ui-monospace,Menlo,Consolas,monospace; font-size:1.25rem; font-weight:700; letter-spacing:0.08em; color:#30f0b6; background:rgba(48,240,182,0.08); border:1px solid rgba(48,240,182,0.3); border-radius:6px; padding:0.55rem 1.1rem;">{{ $reference }}</code>
        </div>
    @endif

    <a class="btn btn-primary" href="{{ url('/') }}">Back to home</a>
    <a class="btn btn-ghost" href="javascript:history.back()">Go back</a>
@endsection
