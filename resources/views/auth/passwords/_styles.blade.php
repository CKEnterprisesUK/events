<style>
    :root {
        --navy: #0f1425;
        --purple: #674df3;
        --purple-dark: #5238d6;
        --ink: #1b1f2e;
        --body: #454a5a;
        --muted: #838694;
        --heading-font: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        --body-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: var(--body-font); color: var(--body); background: #eef0f5; line-height: 1.6; -webkit-font-smoothing: antialiased; }
    h1 { font-family: var(--heading-font); color: var(--ink); line-height: 1.25; margin: 0; }
    a { color: var(--purple); text-decoration: none; }
    a:hover { text-decoration: underline; }

    .auth-narrow { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 3rem 1.5rem; }
    .form-card { width: 100%; max-width: 400px; background: #fff; border-radius: 10px; padding: 2.5rem 2rem; box-shadow: 0 10px 40px rgba(15,20,37,.08); }
    .form-card .mobile-logo { display: inline-flex; align-items: baseline; gap: .4rem; font-family: 'Cabin Sketch', cursive; font-weight: 700; color: var(--ink); margin-bottom: 1.75rem; line-height: 1; }
    .form-card .mobile-logo .events { font-size: 1.5rem; color: var(--purple); }
    .form-card .mobile-logo .by { font-family: var(--body-font); font-weight: 500; font-size: .75rem; letter-spacing: .04em; color: var(--muted); text-transform: uppercase; }
    .form-card .mobile-logo .ck { font-size: 1.5rem; color: var(--ink); }
    .form-card h1 { font-size: 1.5rem; margin-bottom: .4rem; }
    .form-card .sub { color: var(--muted); margin: 0 0 1.75rem; }

    .alert { background: #fdecec; border: 1px solid #f5c2c2; color: #a12626; border-radius: 6px; padding: .8rem 1rem; font-size: .92rem; margin-bottom: 1.5rem; }
    .alert--ok { background: #eafaf1; border-color: #a7e0c3; color: #1b7a4b; }

    .field { margin-bottom: 1.25rem; }
    .field label { display: block; font-weight: 600; color: var(--ink); margin-bottom: .45rem; font-size: .93rem; }
    .field input { width: 100%; padding: .75rem .9rem; border: 1px solid #d5d7e0; border-radius: 6px; font-size: 1rem; font-family: var(--body-font); color: var(--ink); background: #fff; }
    .field input:focus { outline: none; border-color: var(--purple); box-shadow: 0 0 0 3px rgba(103,77,243,.12); }
    .field .hint { margin: .5rem 0 0; font-size: .85rem; color: var(--muted); line-height: 1.5; }
    .field .hint em { font-style: normal; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--ink); }

    .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; padding: .8rem 1.5rem; border-radius: 6px; font-weight: 600; font-size: 1rem; font-family: var(--body-font); cursor: pointer; border: none; background: var(--purple); color: #fff; transition: background .15s ease; }
    .btn:hover { background: var(--purple-dark); }
    .alt { text-align: center; margin-top: 1.5rem; font-size: .95rem; color: var(--muted); }
</style>
