<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in &middot; Events by CK Enterprises</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cabin+Sketch:wght@700&family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0f1425;
            --navy-2: #1b1f2e;
            --purple: #674df3;
            --purple-dark: #5238d6;
            --teal: #30f0b6;
            --ink: #1b1f2e;
            --body: #454a5a;
            --muted: #838694;
            --line: #e6e7ec;
            --surface: #ffffff;
            --heading-font: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --body-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: var(--body-font);
            color: var(--body);
            background: #eef0f5;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        h1, h2, h3 { font-family: var(--heading-font); color: var(--ink); line-height: 1.25; margin: 0; }
        a { color: var(--purple); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .auth { min-height: 100vh; display: grid; grid-template-columns: 1fr 1fr; }

        /* Brand side */
        .auth .brand-side {
            background: var(--navy);
            color: #c1c5d4;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-right: 3px solid var(--purple);
        }
        .brand-side .logo { display: inline-flex; align-items: baseline; gap: .4rem; font-family: 'Cabin Sketch', cursive; font-weight: 700; color: #fff; line-height: 1; }
        .brand-side .logo .events { font-size: 1.5rem; color: var(--teal); }
        .brand-side .logo .by { font-family: var(--body-font); font-weight: 500; font-size: .75rem; letter-spacing: .04em; color: #9aa0b5; text-transform: uppercase; }
        .brand-side .logo .ck { font-size: 1.5rem; color: #fff; }
        .brand-side .pitch { text-align: center; }
        .brand-side .pitch .illustration { width: 100%; max-width: 380px; height: auto; margin: 0 auto 1.5rem; display: block; }
        .brand-side .pitch h2 { color: #fff; font-size: 1.7rem; margin: 0; }
        .brand-side .foot { font-size: .82rem; color: #8b90a5; }
        .brand-side .foot a { color: #c1c5d4; }

        /* Form side */
        .auth .form-side { display: flex; align-items: center; justify-content: center; padding: 3rem 2rem; }
        .form-card { width: 100%; max-width: 400px; }
        .form-card .mobile-logo { display: none; align-items: baseline; gap: .4rem; font-family: 'Cabin Sketch', cursive; font-weight: 700; color: var(--ink); margin-bottom: 2rem; line-height: 1; }
        .form-card .mobile-logo .events { font-size: 1.5rem; color: var(--purple); }
        .form-card .mobile-logo .by { font-family: var(--body-font); font-weight: 500; font-size: .75rem; letter-spacing: .04em; color: var(--muted); text-transform: uppercase; }
        .form-card .mobile-logo .ck { font-size: 1.5rem; color: var(--ink); }
        .form-card h1 { font-size: 1.7rem; margin-bottom: .4rem; }
        .form-card .sub { color: var(--muted); margin: 0 0 2rem; }

        .alert { background: #fdecec; border: 1px solid #f5c2c2; color: #a12626; border-radius: 6px; padding: .8rem 1rem; font-size: .92rem; margin-bottom: 1.5rem; }

        .field { margin-bottom: 1.25rem; }
        .field label { display: block; font-weight: 600; color: var(--ink); margin-bottom: .45rem; font-size: .93rem; }
        .field input[type="email"],
        .field input[type="password"] {
            width: 100%; padding: .75rem .9rem; border: 1px solid #d5d7e0; border-radius: 6px;
            font-size: 1rem; font-family: var(--body-font); color: var(--ink); background: #fff;
        }
        .field input:focus { outline: none; border-color: var(--purple); box-shadow: 0 0 0 3px rgba(103,77,243,.12); }
        .field .error { color: #b91c1c; font-size: .85rem; margin: .4rem 0 0; }
        .remember { display: flex; align-items: center; gap: .5rem; font-size: .92rem; color: var(--body); font-weight: 400; margin-bottom: 1.5rem; }
        .remember input { width: 16px; height: 16px; accent-color: var(--purple); }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; width: 100%;
            padding: .8rem 1.5rem; border-radius: 6px; font-weight: 600; font-size: 1rem;
            font-family: var(--body-font); cursor: pointer; border: none;
            background: var(--purple); color: #fff; transition: background .15s ease;
        }
        .btn:hover { background: var(--purple-dark); }

        .alt { text-align: center; margin-top: 1.75rem; font-size: .95rem; color: var(--muted); }

        @media (max-width: 860px) {
            .auth { grid-template-columns: 1fr; }
            .auth .brand-side { display: none; }
            .form-card .mobile-logo { display: flex; }
        }
    </style>
</head>
<body>
    <div class="auth">
        <aside class="brand-side">
            <a class="logo" href="{{ url('/') }}" aria-label="Events by CK Enterprises">
                <span class="events">Events</span>
                <span class="by">by</span>
                <span class="ck">CK Enterprises</span>
            </a>
            <div class="pitch">
                <img src="{{ asset('images/login-illustration.svg') }}" alt="" class="illustration">
                <h2>Welcome back</h2>
            </div>
            <div class="foot">
                A <a href="https://ckenterprises.co.uk/" target="_blank" rel="noopener">CK Enterprises UK</a> product
            </div>
        </aside>

        <main class="form-side">
            <div class="form-card">
                <a class="mobile-logo" href="{{ url('/') }}" aria-label="Events by CK Enterprises">
                    <span class="events">Events</span>
                    <span class="by">by</span>
                    <span class="ck">CK Enterprises</span>
                </a>

                <h1>Log in to your dashboard</h1>
                <p class="sub">Enter your details to continue.</p>

                @if (session('status'))
                    <div class="alert" role="status" style="background:#eafaf1;border-color:#a7e0c3;color:#1b7a4b;">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="alert" role="alert">
                        {{ $errors->first() }}
                    </div>
                @endif

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

                    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;">
                        <label class="remember" style="margin:0;">
                            <input type="checkbox" name="remember" value="1"> Remember me on this device
                        </label>
                        <a href="{{ route('password.request') }}">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn">Log in</button>
                </form>

                <p class="alt">
                    New here? <a href="{{ url('/register') }}">Create an account</a>
                </p>
            </div>
        </main>
    </div>
</body>
</html>
