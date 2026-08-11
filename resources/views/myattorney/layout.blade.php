<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', 'MyAttorney by FirmsVault')</title>
        <meta name="description" content="@yield('meta_description', 'Find legal help in Michigan. Search attorneys and law firms by practice area, location, and language.')">

        {{-- Mission 2 (MyAttorney Marketplace Core), checkpoint 12.
             $canonicalUrl/$og/$structuredData are only ever set by
             FirmProfileController/AttorneyProfileController today —
             every other page (home/search, correction report) simply
             omits them, so these blocks render nothing there. --}}
        @isset($canonicalUrl)
            <link rel="canonical" href="{{ $canonicalUrl }}">
        @endisset

        @isset($og)
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="MyAttorney by FirmsVault">
            <meta property="og:title" content="{{ $og['title'] }}">
            <meta property="og:description" content="{{ $og['description'] }}">
            <meta property="og:url" content="{{ $og['url'] }}">
        @endisset

        @isset($structuredData)
            <script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES) !!}</script>
        @endisset

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css'])
        @endif
    </head>
    <body class="bg-white text-gray-900 antialiased">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-4 focus:py-2 focus:shadow">
            Skip to main content
        </a>

        <header class="border-b border-gray-200">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
                <a href="{{ route('myattorney.home') }}" class="text-lg font-semibold text-gray-900 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-600">
                    MyAttorney <span class="text-sm font-normal text-gray-500">by FirmsVault</span>
                </a>
            </div>
        </header>

        <main id="main-content" class="mx-auto max-w-5xl px-4 py-8">
            @yield('content')
        </main>

        <footer class="border-t border-gray-200 mt-16">
            <div class="mx-auto max-w-5xl px-4 py-6 text-sm text-gray-500">
                <p>MyAttorney is a legal-services directory operated by FirmsVault. Listings do not constitute a referral, endorsement, or guarantee of any attorney's or firm's services.</p>
            </div>
        </footer>
    </body>
</html>
