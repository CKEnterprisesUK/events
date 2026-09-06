@extends('layouts.dashboard')

@section('title', 'Super-Admin — Settings')

@section('content')
    <section>
        <h1>Platform settings</h1>

        <p class="muted">Platform-wide configuration and troubleshooting tools.</p>

        @if (session('status'))
            <p class="status" role="status">{{ session('status') }}</p>
        @endif

        @if (session('error'))
            <p class="error" role="alert">{{ session('error') }}</p>
        @endif

        <h2>Mail</h2>
        <p class="muted">
            The effective mail configuration, read from the environment. Use the
            test tool below to confirm the transport can actually deliver.
        </p>

        <table>
            <tbody>
                <tr>
                    <th scope="row">Mailer</th>
                    <td>{{ $mail['default'] }}</td>
                </tr>
                <tr>
                    <th scope="row">Host</th>
                    <td>{{ $mail['host'] }}</td>
                </tr>
                <tr>
                    <th scope="row">Port</th>
                    <td>{{ $mail['port'] }}</td>
                </tr>
                <tr>
                    <th scope="row">Scheme / encryption</th>
                    <td>{{ $mail['scheme'] }}</td>
                </tr>
                <tr>
                    <th scope="row">Username</th>
                    <td>{{ $mail['username'] }}</td>
                </tr>
                <tr>
                    <th scope="row">Password</th>
                    <td>{{ $mail['password'] }}</td>
                </tr>
                <tr>
                    <th scope="row">From address</th>
                    <td>{{ $mail['from_address'] }}</td>
                </tr>
                <tr>
                    <th scope="row">From name</th>
                    <td>{{ $mail['from_name'] }}</td>
                </tr>
            </tbody>
        </table>

        <h2>Send a test email</h2>
        <p class="muted">
            Sends a diagnostic message through the configured mailer. It is sent
            immediately, so any transport error is reported back here.
        </p>

        <form method="POST" action="{{ route('admin.settings.test-mail') }}">
            @csrf
            <div class="field">
                <label for="test-email">Recipient email</label>
                <input
                    type="email"
                    id="test-email"
                    name="email"
                    value="{{ old('email', $defaultTestEmail) }}"
                    required
                    placeholder="you@example.com"
                >
                @error('email')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="btn">Send test email</button>
        </form>
    </section>
@endsection
