<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name') }} — Review &amp; Sign</title>
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
            max-width: 640px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
            padding: 32px 28px;
        }
        h1 { font-size: 1.25rem; margin: 0 0 4px; }
        p { line-height: 1.5; }
        .muted { color: #6b7280; font-size: 0.9rem; }
        .notice { border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; font-size: 0.95rem; }
        .notice.success { background: #ecfdf5; color: #065f46; }
        .notice.error { background: #fef2f2; color: #991b1b; }
        .notice.info { background: #eff6ff; color: #1e3a8a; }
        .document-frame {
            width: 100%;
            height: 480px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            margin: 16px 0;
        }
        .consent-box {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 0.85rem;
            color: #374151;
            max-height: 160px;
            overflow-y: auto;
            margin-bottom: 16px;
        }
        label.checkbox { display: flex; align-items: flex-start; gap: 8px; font-weight: 500; font-size: 0.9rem; cursor: pointer; }
        label.checkbox input { margin-top: 3px; }
        button {
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            background: #4f46e5;
            color: #ffffff;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 16px;
        }
        button:disabled { opacity: 0.5; cursor: default; }
        button.secondary { background: #ffffff; color: #991b1b; border: 1px solid #fca5a5; }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; }
        form.inline { display: inline; }
    </style>
</head>
<body>
    <div class="card">
        @if (session('status'))
            <div class="notice success">{{ session('status') }}</div>
        @endif

        <h1>{{ $recipient->signatureRequest?->title ?? 'Document requiring your signature' }}</h1>
        <p class="muted">Signer: {{ $recipient->signer_name }} ({{ $recipient->signer_email }})</p>

        @if ($recipient->status->value === 'signed' || $recipient->status->value === 'completed')
            <div class="notice success">You have already signed this document. No further action is needed.</div>
        @elseif ($recipient->status->value === 'declined')
            <div class="notice info">You declined to sign this document.</div>
        @elseif (in_array($recipient->status->value, ['expired', 'voided'], true))
            <div class="notice info">This signature request is no longer available. Please contact the firm directly.</div>
        @else
            @if ($documentAccessible)
                <p class="muted">Please review the document below before consenting and signing.</p>
                <iframe class="document-frame" src="{{ route('public.signature-recipients.document', ['uuid' => $recipient->uuid]) }}?token={{ $token }}" title="Document to sign"></iframe>
            @else
                <div class="notice error">The document could not be loaded. Please contact the firm directly.</div>
            @endif

            @if ($recipient->hasConsented())
                <div class="notice success">You consented to sign electronically on {{ optional($recipient->consented_at)->format('M j, Y g:i A') }}.</div>
            @else
                <div class="consent-box">{{ $consentText }}</div>
            @endif

            <div class="actions">
                @if ($canConsent)
                    <form method="POST" action="{{ route('public.signature-recipients.consent', ['uuid' => $recipient->uuid]) }}" class="inline">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <label class="checkbox">
                            <input type="checkbox" required>
                            I have reviewed the document and consent to sign electronically.
                        </label>
                        <button type="submit">I Consent</button>
                    </form>
                @endif

                @if ($canSign)
                    <form method="POST" action="{{ route('public.signature-recipients.sign', ['uuid' => $recipient->uuid]) }}" class="inline">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <button type="submit">Sign Document</button>
                    </form>
                @endif

                @if ($canDecline)
                    <form method="POST" action="{{ route('public.signature-recipients.decline', ['uuid' => $recipient->uuid]) }}" class="inline">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <input type="hidden" name="reason" value="Declined by signer.">
                        <button type="submit" class="secondary">Decline to Sign</button>
                    </form>
                @endif
            </div>

            <p class="muted" style="margin-top:20px;font-size:0.8rem;">
                Signing electronically has the same legal effect as a handwritten signature. This page never displays or stores anything beyond your consent and signature status.
            </p>
        @endif
    </div>
</body>
</html>
