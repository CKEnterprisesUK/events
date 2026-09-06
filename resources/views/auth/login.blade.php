@extends('layouts.app')

@section('title', 'Log in')

@section('content')
    <section style="max-width: 420px; margin: 0 auto;">
        <h1>Log in</h1>

        <form method="POST" action="{{ url('/login') }}">
            @csrf

            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                @error('email')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
                @error('password')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label style="font-weight: 400;">
                    <input type="checkbox" name="remember" value="1"> Remember me
                </label>
            </div>

            <button type="submit" class="btn">Log in</button>
        </form>
    </section>
@endsection
