@extends('myattorney.layout')

@section('title', $profile->name . ' | MyAttorney by FirmsVault')
@section('meta_description', $profile->biography ? \Illuminate\Support\Str::limit(strip_tags($profile->biography), 155) : $profile->name . ' — Attorney profile on MyAttorney by FirmsVault.')

@section('content')
    <article>
        <header class="mb-6">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-900">{{ $profile->name }}</h1>
                @foreach ($profile->badges as $badge)
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                        {{ $badge->label() }}
                    </span>
                @endforeach
            </div>
            @if ($profile->title)
                <p class="mt-1 text-gray-600">{{ $profile->title }}</p>
            @endif
        </header>

        @if ($profile->biography)
            <section class="mb-8" aria-labelledby="about-heading">
                <h2 id="about-heading" class="text-lg font-semibold text-gray-900 mb-2">About</h2>
                <p class="text-gray-700 whitespace-pre-line">{{ $profile->biography }}</p>
            </section>
        @endif

        @if (count($profile->practiceAreaNames) > 0)
            <section class="mb-8" aria-labelledby="practice-areas-heading">
                <h2 id="practice-areas-heading" class="text-lg font-semibold text-gray-900 mb-2">Practice Areas</h2>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($profile->practiceAreaNames as $name)
                        <li class="rounded bg-blue-50 px-3 py-1 text-sm text-blue-800">{{ $name }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if (count($profile->languageNames) > 0)
            <section class="mb-8" aria-labelledby="languages-heading">
                <h2 id="languages-heading" class="text-lg font-semibold text-gray-900 mb-2">Languages</h2>
                <p class="text-gray-700">{{ implode(', ', $profile->languageNames) }}</p>
            </section>
        @endif

        @if (count($profile->firms) > 0)
            <section class="mb-8" aria-labelledby="firms-heading">
                <h2 id="firms-heading" class="text-lg font-semibold text-gray-900 mb-2">
                    {{ collect($profile->firms)->contains('isCurrent', true) ? 'Current Firm' : 'Firm History' }}
                </h2>
                <ul class="space-y-2">
                    @foreach ($profile->firms as $firm)
                        <li>
                            <a href="{{ route('myattorney.firms.show', $firm->slug) }}" class="text-blue-700 hover:underline focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                                {{ $firm->displayName }}
                            </a>
                            @if ($firm->title)
                                <span class="text-gray-500"> — {{ $firm->title }}</span>
                            @endif
                            @unless ($firm->isCurrent)
                                <span class="text-gray-400 text-sm"> (former)</span>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </article>
@endsection
