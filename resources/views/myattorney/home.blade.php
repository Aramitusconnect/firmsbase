@extends('myattorney.layout')

@section('title', 'MyAttorney by FirmsVault — Find Legal Help in Michigan')
@section('meta_description', 'Search Michigan attorneys and law firms by practice area, location, and language on MyAttorney by FirmsVault.')

@section('content')
    <section class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-1">Find Legal Help</h1>
        <p class="text-gray-600 mb-6">Search Michigan attorneys and law firms by practice area, location, and language.</p>

        <form method="GET" action="{{ route('myattorney.home') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" role="search" aria-label="Firm and attorney search">
            <div class="lg:col-span-2">
                <label for="search-name" class="block text-sm font-medium text-gray-700 mb-1">Firm or attorney name</label>
                <input type="text" id="search-name" name="name" value="{{ $criteria->name }}" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600" placeholder="e.g. Smith Law">
            </div>

            <div>
                <label for="search-practice-area" class="block text-sm font-medium text-gray-700 mb-1">Practice area</label>
                <select id="search-practice-area" name="practice_area" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    <option value="">Any practice area</option>
                    @foreach ($practiceAreas as $area)
                        <option value="{{ $area->slug }}" @selected($criteria->practiceAreaSlug === $area->slug)>{{ $area->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="search-city" class="block text-sm font-medium text-gray-700 mb-1">City</label>
                <input type="text" id="search-city" name="city" value="{{ $criteria->city }}" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600" placeholder="e.g. Detroit">
            </div>

            <div>
                <label for="search-language" class="block text-sm font-medium text-gray-700 mb-1">Language</label>
                <select id="search-language" name="language" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    <option value="">Any language</option>
                    @foreach ($languages as $language)
                        <option value="{{ $language->code }}" @selected($criteria->languageCode === $language->code)>{{ $language->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end gap-2">
                <label for="search-accepting" class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" id="search-accepting" name="accepting_inquiries" value="1" @checked($criteria->acceptingInquiriesOnly) class="rounded border-gray-300 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    Accepting new inquiries only
                </label>
            </div>

            <div class="lg:col-span-3 flex items-end">
                <button type="submit" class="rounded bg-blue-700 px-5 py-2 text-white font-medium hover:bg-blue-800 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-900">
                    Search
                </button>
            </div>
        </form>
    </section>

    @if ($hasQuery)
        <section aria-live="polite">
            @if ($results->total() === 0)
                <div class="rounded border border-gray-200 p-8 text-center">
                    <p class="text-gray-700">No firms matched your search.</p>
                    <p class="text-sm text-gray-500 mt-1">Try broadening your practice area or location.</p>
                </div>
            @else
                <p class="text-sm text-gray-500 mb-4">{{ $results->total() }} {{ \Illuminate\Support\Str::plural('result', $results->total()) }}</p>
                <ul class="space-y-4">
                    @foreach ($results as $result)
                        <li class="rounded border border-gray-200 p-5">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold text-gray-900">
                                    <a href="{{ route('myattorney.firms.show', $result->slug) }}" class="hover:underline focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                                        {{ $result->displayName }}
                                    </a>
                                </h2>
                                @foreach ($result->badges as $badge)
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{{ $badge->label() }}</span>
                                @endforeach
                            </div>

                            @if ($result->nearestCity)
                                <p class="text-sm text-gray-600 mt-1">{{ $result->nearestCity }}, {{ $result->nearestState }}</p>
                            @endif

                            @if (count($result->practiceAreaNames) > 0)
                                <p class="text-sm text-gray-600 mt-1">{{ implode(', ', $result->practiceAreaNames) }}</p>
                            @endif

                            @if ($result->acceptingInquiries)
                                <p class="text-sm text-green-700 mt-1">Accepting new inquiries</p>
                            @endif

                            <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                <a href="{{ route('myattorney.firms.show', $result->slug) }}" class="text-blue-700 hover:underline focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">View Profile</a>
                                @if ($result->phone)
                                    <a href="tel:{{ $result->phone }}" class="text-blue-700 hover:underline focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">Call</a>
                                @endif
                                @if ($result->website)
                                    <a href="{{ $result->website }}" rel="nofollow noopener" target="_blank" class="text-blue-700 hover:underline focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">Website</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                <nav class="mt-6" aria-label="Search results pages">
                    {{ $results->links() }}
                </nav>
            @endif
        </section>
    @endif
@endsection
