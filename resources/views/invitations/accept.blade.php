@extends('layouts.app')

@section('title', 'Accept invitation')

@section('content')
    <section style="max-width: 420px; margin: 0 auto;">
        <h1>Accept your invitation</h1>

        <p>You have been invited to join as <strong>{{ ucfirst($invitation->role) }}</strong>.</p>
        <p>Email: {{ $invitation->email }}</p>

        <form method="POST" action="{{ route('invitations.accept', $invitation->token) }}">
            @csrf

            <div class="field">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus>
                @error('name')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
                @error('password')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn">Create account</button>
        </form>
    </section>
@endsection
