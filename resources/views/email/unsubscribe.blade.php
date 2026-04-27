<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribe — Market Funded</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f9fafb; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: white; border-radius: 12px; padding: 40px; max-width: 480px; width: 100%; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }
        h1 { font-size: 1.5rem; color: #111827; margin-bottom: 8px; }
        p { color: #6b7280; line-height: 1.6; }
        .email { font-weight: 600; color: #111827; }
        button { background: #dc2626; color: white; border: none; padding: 12px 32px; border-radius: 8px; font-size: 1rem; cursor: pointer; margin-top: 24px; width: 100%; }
        button:hover { background: #b91c1c; }
        .cancel { display: block; margin-top: 12px; color: #6b7280; text-decoration: none; font-size: 0.875rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Unsubscribe</h1>
        <p>You are about to unsubscribe <span class="email">{{ $recipient->email }}</span> from all Market Funded email communications.</p>
        <form method="POST" action="{{ route('email.unsubscribe.confirm', $recipient->id) }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button type="submit">Confirm Unsubscribe</button>
        </form>
        <a href="/" class="cancel">Cancel — take me back</a>
    </div>
</body>
</html>
