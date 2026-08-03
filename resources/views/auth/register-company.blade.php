<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Register your company — {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f3f4f6; color: #111827; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        form { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); width: 100%; max-width: 380px; }
        h1 { font-size: 1.25rem; margin: 0 0 1.25rem; }
        label { display: block; font-size: .8rem; font-weight: 600; margin: 1rem 0 .25rem; }
        input { width: 100%; padding: .55rem .65rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: .95rem; box-sizing: border-box; }
        button { margin-top: 1.5rem; width: 100%; padding: .65rem; background: #111827; color: #fff; border: none; border-radius: 6px; font-size: .95rem; cursor: pointer; }
        .error { color: #b91c1c; font-size: .8rem; margin-top: .25rem; }
    </style>
</head>
<body>
    <form method="POST" action="{{ route('register-company.store') }}">
        @csrf
        <h1>Register your company</h1>

        <label for="company_name">Company name</label>
        <input id="company_name" name="company_name" value="{{ old('company_name') }}" required>
        @error('company_name') <div class="error">{{ $message }}</div> @enderror

        <label for="admin_name">Your name</label>
        <input id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required>
        @error('admin_name') <div class="error">{{ $message }}</div> @enderror

        <label for="admin_email">Your email</label>
        <input id="admin_email" type="email" name="admin_email" value="{{ old('admin_email') }}" required>
        @error('admin_email') <div class="error">{{ $message }}</div> @enderror

        <label for="password">Password</label>
        <input id="password" type="password" name="password" required>
        @error('password') <div class="error">{{ $message }}</div> @enderror

        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required>

        <button type="submit">Create company</button>
    </form>
</body>
</html>
