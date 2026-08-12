<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name') }} — Secure Intake</title>
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
        .skip-link {
            position: absolute;
            left: -9999px;
            top: 0;
            background: #1f2937;
            color: #ffffff;
            padding: 10px 16px;
            border-radius: 0 0 8px 0;
            z-index: 10;
        }
        .skip-link:focus {
            left: 0;
        }
        :focus-visible {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
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
        h2 { font-size: 1.05rem; margin: 0 0 12px; }
        p { line-height: 1.5; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.95rem; }
        .muted { color: #6b7280; font-size: 0.9rem; }
        .notice {
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 16px;
            font-size: 0.95rem;
        }
        .notice.success { background: #ecfdf5; color: #065f46; }
        .notice.error { background: #fef2f2; color: #991b1b; }
        .notice.info { background: #eff6ff; color: #1e3a8a; }
        .field { margin-bottom: 18px; }
        .field input[type="text"],
        .field input[type="email"],
        .field input[type="tel"],
        .field input[type="number"],
        .field input[type="date"],
        .field select,
        .field textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
            font-family: inherit;
        }
        .field textarea { min-height: 90px; resize: vertical; }
        .field .help { margin-top: 4px; }
        .field .error { color: #991b1b; font-size: 0.85rem; margin-top: 4px; }
        .checkbox-row { display: flex; align-items: center; gap: 8px; }
        .checkbox-row label { margin: 0; font-weight: 400; }
        .progress-track {
            height: 6px;
            background: #e5e7eb;
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        .progress-fill { height: 100%; background: #2563eb; }
        .actions { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
        .btn {
            display: inline-block;
            border: none;
            border-radius: 8px;
            padding: 10px 18px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            background: #2563eb;
            color: #ffffff;
        }
        .btn:hover { background: #1d4ed8; }
        .btn[disabled] { opacity: 0.6; cursor: not-allowed; }
        .btn-secondary {
            background: #ffffff;
            color: #1f2937;
            border: 1px solid #d1d5db;
        }
        .btn-secondary:hover { background: #f3f4f6; }
        .btn-link {
            background: none;
            border: none;
            color: #2563eb;
            font-size: 0.85rem;
            cursor: pointer;
            padding: 0;
        }
        .chat-box {
            border: 1px solid #dbeafe;
            background: #eff6ff;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 18px;
        }
        .chat-box textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.95rem;
            min-height: 60px;
        }
        .review-list { list-style: none; margin: 0 0 16px; padding: 0; }
        .review-list li {
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 0;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }
        .review-list li:last-child { border-bottom: none; }
        .review-value { flex: 1; word-break: break-word; }
        .documents-list { list-style: none; margin: 8px 0 0; padding: 0; font-size: 0.9rem; }
        .documents-list li { padding: 4px 0; }
        .consent-row { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 10px; }
        .consent-row label { margin: 0; font-weight: 400; font-size: 0.9rem; }
        [wire\:loading] { opacity: 0.7; }
    </style>
</head>
<body>
    <a href="#intake-main" class="skip-link">Skip to secure intake content</a>
    <main id="intake-main" class="card">
        {{ $slot }}
    </main>
</body>
</html>
