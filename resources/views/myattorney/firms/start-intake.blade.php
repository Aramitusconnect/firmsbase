@extends('myattorney.layout')

@section('title', 'Start Secure Intake | ' . $profile->displayName)
@section('meta_description', 'Choose what you need help with to start a secure intake with ' . $profile->displayName . '.')

@section('content')
    <article>
        <h1 class="text-2xl font-semibold text-gray-900">What do you need help with?</h1>
        <p class="mt-2 text-gray-700">
            {{ $profile->displayName }} handles several kinds of matter. Choosing the closest one
            helps the firm ask you the right questions.
        </p>

        <ul class="mt-8 space-y-3">
            @foreach ($practiceAreas as $practiceArea)
                <li>
                    <form method="POST" action="{{ route('myattorney.firms.start-intake', $profile->slug) }}">
                        @csrf
                        <input type="hidden" name="practice_area_id" value="{{ $practiceArea->id }}">
                        <button type="submit"
                            class="w-full rounded border border-gray-300 bg-white px-4 py-3 text-left text-sm font-medium text-gray-900 hover:border-blue-600 hover:bg-blue-50 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                            {{ $practiceArea->name }}
                            @if ($practiceArea->description)
                                <span class="mt-1 block text-xs font-normal text-gray-600">{{ $practiceArea->description }}</span>
                            @endif
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>

        <p class="mt-8 text-sm">
            <a href="{{ route('myattorney.firms.show', $profile->slug) }}" class="text-blue-700 underline">
                Back to {{ $profile->displayName }}
            </a>
        </p>
    </article>
@endsection
