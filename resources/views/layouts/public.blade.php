<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name') }} — Payment</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f4f5f7;
            color: #1f2937;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 32px 16px;
        }
        .card {
            width: 100%;
            max-width: 480px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
            padding: 32px 28px;
        }
        h1 { font-size: 1.25rem; margin: 0 0 4px; }
        p { line-height: 1.5; }
        .muted { color: #6b7280; font-size: 0.9rem; }
        label { display: block; font-weight: 600; font-size: 0.9rem; margin-bottom: 6px; }
        input[type="text"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
        }
        button {
            width: 100%;
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            background: var(--brand-color, #4f46e5);
            color: #ffffff;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 16px;
        }
        button:disabled { opacity: 0.6; cursor: default; }
        .notice {
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 16px;
            font-size: 0.95rem;
        }
        .notice.success { background: #ecfdf5; color: #065f46; }
        .notice.error { background: #fef2f2; color: #991b1b; }
        .notice.info { background: #eff6ff; color: #1e3a8a; }
        .amount-display { font-size: 1.75rem; font-weight: 700; margin: 8px 0 20px; }
    </style>
</head>
<body>
    <div class="card">
        {{ $slot }}
    </div>
</body>
</html>
