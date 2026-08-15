{{--
    PlatformRequiresAttentionWidget — CORE SuperAdmin mission, section
    15. Every item comes from $this->items(), itself derived entirely
    from the already-computed PlatformExecutiveDashboardService
    snapshot (see that widget class's own docblock — no query here).

    Accessibility: status is never color-only — each item's severity
    word ("Critical"/"Warning"/"Info") is rendered as visible text via
    <x-filament::badge>, not merely implied by badge color.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Requires Attention
        </x-slot>

        @php($items = $this->items())

        @if ($this->hasNothingToReport())
            <p class="fi-requires-attention-empty text-sm text-gray-500 dark:text-gray-400">
                No core platform administration or security issues currently require attention.
            </p>
        @else
            <ul class="fi-requires-attention-list space-y-2">
                @foreach ($items as $item)
                    <li class="flex items-start gap-x-3">
                        <x-filament::badge
                            :color="match ($item['level']) {
                                'critical' => 'danger',
                                'warning' => 'warning',
                                default => 'gray',
                            }"
                        >
                            {{ match ($item['level']) {
                                'critical' => 'Critical',
                                'warning' => 'Warning',
                                default => 'Info',
                            } }}
                        </x-filament::badge>

                        <span class="text-sm text-gray-950 dark:text-white">
                            @if ($item['url'])
                                <a href="{{ $item['url'] }}" class="underline hover:no-underline">{{ $item['message'] }}</a>
                            @else
                                {{ $item['message'] }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
