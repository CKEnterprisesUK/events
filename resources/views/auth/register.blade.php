@extends('layouts.app')

@section('title', 'Sign up')

@section('content')
    <section class="auth-card">
        <h1>Create your account</h1>
        <p class="muted">Set up your organisation and start selling tickets. You'll be the account Owner.</p>

        <form method="POST" action="{{ url('/register') }}">
            @csrf

            <div class="field">
                <label for="company_name">Organisation name</label>
                <input id="company_name" type="text" name="company_name" value="{{ old('company_name') }}" required autofocus>
                @error('company_name')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="slug">Storefront address</label>
                <div class="slug-input">
                    <span class="slug-prefix">{{ rtrim(url('/'), '/') }}/</span>
                    <input id="slug" type="text" name="slug" value="{{ old('slug') }}" placeholder="your-org" required
                        pattern="[a-z0-9\-]+" autocomplete="off" spellcheck="false">
                </div>
                <p class="hint">Lowercase letters, numbers and hyphens only. This is your public storefront URL.</p>
                @error('slug')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <hr class="divider">

            <div class="field">
                <label for="name">Your name</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required autocomplete="name">
                @error('name')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
                @error('email')
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

            <button type="submit" class="btn btn-block">Create account</button>
        </form>

        <p class="auth-alt">Already have an account? <a href="{{ url('/login') }}">Log in</a></p>
    </section>
@endsection

@push('scripts')
<script>
    // Gently suggest a slug from the organisation name until the user edits it.
    (function () {
        var nameInput = document.getElementById('company_name');
        var slugInput = document.getElementById('slug');
        if (!nameInput || !slugInput) return;

        var slugEdited = slugInput.value.length > 0;
        slugInput.addEventListener('input', function () { slugEdited = true; });

        function slugify(value) {
            return value.toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .trim()
                .replace(/[\s_]+/g, '-')
                .replace(/-+/g, '-');
        }

        nameInput.addEventListener('input', function () {
            if (!slugEdited) {
                slugInput.value = slugify(nameInput.value);
            }
        });
    })();
</script>
@endpush
