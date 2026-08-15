{{--
    Past support access, firm-facing — the firm's own permanent record of
    who entered their data, why, and how the access ended.

    "Ended" / "Revoked" / "Expired" are distinct, explicit text labels
    rather than colour variations: how a support session ended is
    materially different information (it expired on its own vs. the firm
    cut it short), and that distinction must survive without colour.
--}}
<div class="fi-section-content">
    @if (empty($sessions))
        <p class="fi-color-gray text-sm">
            No past support sessions for your firm.
        </p>
    @else
        <ul role="list" class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($sessions as $session)
                <li class="py-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <span class="text-sm font-medium">
                            {{ $session['platform_admin_name'] ?? 'Platform support' }}
                        </span>
                        <span class="fi-badge fi-color-gray text-xs font-medium">
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
                            <dt class="inline font-medium">
                                {{ $session['revoked_at'] !== null ? 'Revoked:' : 'Ended:' }}
                            </dt>
                            <dd class="inline">
                                {{ ($session['revoked_at'] ?? $session['ended_at'] ?? $session['expires_at'])?->toDayDateTimeString() ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </li>
            @endforeach
        </ul>
    @endif
</div>
