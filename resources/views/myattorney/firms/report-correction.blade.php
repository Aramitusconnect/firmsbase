@extends('myattorney.layout')

@section('title', 'Report a Correction — ' . $firm->display_name . ' | MyAttorney by FirmsVault')
@section('meta_description', 'Report an incorrect detail or request removal of a MyAttorney listing.')

@section('content')
    <section class="max-w-xl">
        <h1 class="text-2xl font-bold text-gray-900 mb-1">Report a Correction</h1>
        <p class="text-gray-600 mb-6">{{ $firm->display_name }}</p>

        @if ($errors->any())
            <div class="mb-6 rounded border border-red-300 bg-red-50 p-4 text-sm text-red-800" role="alert">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('myattorney.firms.report-correction.store', $firm->slug) }}" class="space-y-4">
            @csrf

            <div>
                <label for="correction_type" class="block text-sm font-medium text-gray-700 mb-1">What's wrong?</label>
                <select id="correction_type" name="correction_type" required class="w-full rounded border border-gray-300 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    @foreach ($correctionTypes as $type)
                        <option value="{{ $type->value }}" @selected(old('correction_type') === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Details</label>
                <textarea id="description" name="description" required rows="4" maxlength="2000" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">{{ old('description') }}</textarea>
            </div>

            <div>
                <label for="reporter_name" class="block text-sm font-medium text-gray-700 mb-1">Your name (optional)</label>
                <input type="text" id="reporter_name" name="reporter_name" value="{{ old('reporter_name') }}" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
            </div>

            <div>
                <label for="reporter_email" class="block text-sm font-medium text-gray-700 mb-1">Your email (optional, in case we need to follow up)</label>
                <input type="email" id="reporter_email" name="reporter_email" value="{{ old('reporter_email') }}" class="w-full rounded border border-gray-300 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
            </div>

            <button type="submit" class="rounded bg-blue-700 px-5 py-2 text-white font-medium hover:bg-blue-800 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-900">
                Submit Report
            </button>
        </form>
    </section>
@endsection
