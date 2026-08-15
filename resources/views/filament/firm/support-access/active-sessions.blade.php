{{--
    Active support sessions, firm-facing. Status is never conveyed by
    colour alone — every row carries an explicit "Active" text label and a
    server-derived time remaining, so the state is legible to a screen
    reader and in high-contrast/monochrome exactly as it is on screen.

    The countdown text is informational only: the server re-checks the
    session's expiry on every single authorization, so a session is refused
    the moment it expires regardless of what this page last rendered.
--}}
<div class="fi-section-content">
    @if (empty($sessions))
        <p class="fi-color-gray text-sm">
            No support sessions are currently active in your firm.
        </p>
    @else
        <ul role="list" class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($sessions as $session)
                <li class="py-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <span class="text-sm font-medium">
                            {{ $session['platform_admin_name'] ?? 'Platform support' }}
                        </span>
                        <span class="fi-badge fi-color-success text-xs font-medium">
                            {{ $session['status_label'] }}
                        </span>
                    </div>

                    <dl class="mt-1 space-y-0.5 text-xs fi-color-gray">
                        <div>
                            <dt class="inline font-medium">Session:</dt>
                            <dd class="inline">{{ $session['reference'] }}</dd>
                        </div>
                        <div>
                            <dt class="inline font-medium">Type:</dt>
                            <dd class="inline">{{ $session['access_type_label'] }}</dd>
                        </div>
                        @if (! empty($session['reason']))
                            <div>
                                <dt class="inline font-medium">Reason:</dt>
                                <dd class="inline">{{ $session['reason'] }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="inline font-medium">Started:</dt>
                            <dd class="inline">{{ $session['started_at']?->toDayDateTimeString() ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="inline font-medium">Ends automatically:</dt>
                            <dd class="inline">
                                {{ $session['expires_at']?->toDayDateTimeString() ?? '—' }}
                                @if (! empty($session['time_remaining']))
                                    ({{ $session['time_remaining'] }} remaining)
                                @endif
                            </dd>
                        </div>
                    </dl>
                </li>
            @endforeach
        </ul>
    @endif
</div>
