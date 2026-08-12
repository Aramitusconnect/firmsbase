<div>
    @if (! $found)
        <h1>Intake link not found</h1>
        <p class="muted">This link may be invalid. Please check the link you were given, or contact the firm directly.</p>
    @elseif (! $resumable)
        <h1>{{ $firmDisplayName }}</h1>
        <div class="notice info">This intake link is no longer available.</div>
        <p class="muted">Please contact the firm directly if you believe this is an error.</p>
    @else
        <h1>{{ $firmDisplayName }}</h1>
        <div class="notice success">Welcome back — your intake is saved and ready to continue.</div>
        <p class="muted">Current status: {{ str($status)->headline() }}</p>
    @endif
</div>
