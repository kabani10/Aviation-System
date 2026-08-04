<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Verify your identity — {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f3f4f6; color: #111827; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        form { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); width: 100%; max-width: 340px; }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        p { font-size: .85rem; color: #6b7280; margin: 0 0 1.25rem; }
        input { width: 100%; padding: .55rem .65rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1.1rem; letter-spacing: .15em; text-align: center; box-sizing: border-box; }
        button { margin-top: 1.25rem; width: 100%; padding: .65rem; background: #111827; color: #fff; border: none; border-radius: 6px; font-size: .95rem; cursor: pointer; }
        .error { color: #b91c1c; font-size: .8rem; margin-top: .5rem; }
        .logout { display: block; text-align: center; margin-top: 1rem; font-size: .8rem; color: #6b7280; }
    </style>
</head>
<body>
    <form method="POST" action="{{ route('two-factor.challenge.store') }}">
        @csrf
        <h1>Verify your identity</h1>
        <p>Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>

        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required>
        @error('code') <div class="error">{{ $message }}</div> @enderror

        <button type="submit">Verify</button>
    </form>
</body>
</html>
