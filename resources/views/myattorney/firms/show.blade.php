@extends('myattorney.layout')

@section('title', $profile->displayName . ' | MyAttorney by FirmsVault')
@section('meta_description', $profile->description ? \Illuminate\Support\Str::limit(strip_tags($profile->description), 155) : $profile->displayName . ' — Firm profile on MyAttorney by FirmsVault.')

@section('content')
    <article>
        <header class="mb-6">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-900">{{ $profile->displayName }}</h1>
                @foreach ($profile->badges as $badge)
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                        {{ $badge->label() }}
                    </span>
                @endforeach
            </div>

            @if ($profile->acceptingInquiries)
                <p class="mt-2 text-sm text-green-700">Accepting new inquiries</p>
            @endif
        </header>

        @if ($profile->description)
            <section class="mb-8" aria-labelledby="about-heading">
                <h2 id="about-heading" class="text-lg font-semibold text-gray-900 mb-2">About</h2>
                <p class="text-gray-700 whitespace-pre-line">{{ $profile->description }}</p>
            </section>
        @endif

        <section class="mb-8 flex flex-wrap gap-3" aria-label="Contact and links">
            @if ($profile->phone)
                <a href="tel:{{ $profile->phone }}" class="rounded border border-gray-300 px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    Call {{ $profile->phone }}
                </a>
            @endif
            @if ($profile->website)
                <a href="{{ $profile->website }}" rel="nofollow noopener" target="_blank" class="rounded border border-gray-300 px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    Visit Website
                </a>
            @endif
            @if ($claimUrl)
                <a href="{{ $claimUrl }}" class="rounded border border-blue-300 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-800 hover:bg-blue-100 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    Claim This Listing
                </a>
            @endif
            {{-- "Suggest a Correction" (checkpoint 8) action lands here once that route exists. --}}
        </section>

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

        @if (count($profile->offices) > 0)
            <section class="mb-8" aria-labelledby="offices-heading">
                <h2 id="offices-heading" class="text-lg font-semibold text-gray-900 mb-2">Offices</h2>
                <ul class="space-y-4">
                    @foreach ($profile->offices as $office)
                        <li class="rounded border border-gray-200 p-4">
                            <p class="font-medium text-gray-900">{{ $office->label }}</p>
                            <address class="not-italic text-gray-700">
                                {{ $office->addressLine1 }}@if ($office->addressLine2), {{ $office->addressLine2 }}@endif<br>
                                {{ $office->city }}, {{ $office->state }} {{ $office->postalCode }}
                            </address>
                            @if ($office->phone)
                                <p class="text-gray-700">{{ $office->phone }}</p>
                            @endif
                            @if ($office->appointmentOnly)
                                <p class="text-sm text-gray-500">By appointment only</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if (count($profile->attorneys) > 0)
            <section class="mb-8" aria-labelledby="attorneys-heading">
                <h2 id="attorneys-heading" class="text-lg font-semibold text-gray-900 mb-2">Attorneys</h2>
                <ul class="space-y-2">
                    @foreach ($profile->attorneys as $attorney)
                        <li>
                            <a href="{{ route('myattorney.attorneys.show', $attorney->slug) }}" class="text-blue-700 hover:underline focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                                {{ $attorney->name }}
                            </a>
                            @if ($attorney->title)
                                <span class="text-gray-500"> — {{ $attorney->title }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </article>
@endsection
