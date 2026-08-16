{{--
    Client Portal "create client account" entry point.

    Rendered through PanelsRenderHook::AUTH_LOGIN_FORM_AFTER so the stock
    Filament login form — heading, email, password, remember me, forgot
    password, password visibility — is left completely untouched. This is
    additive markup underneath it, not a replacement view.
--}}
<div class="fi-signup-cta mt-6">
    <div class="relative mb-6">
        <div class="absolute inset-0 flex items-center" aria-hidden="true">
            <div class="w-full border-t border-gray-200 dark:border-white/10"></div>
        </div>
        <div class="relative flex justify-center">
            <span class="bg-white px-2 text-sm text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                {{ __('or') }}
            </span>
        </div>
    </div>

    <a
        href="{{ route('client-portal.register') }}"
        class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg gap-1.5 px-3 py-2 text-sm inline-grid w-full bg-white text-gray-950 ring-1 ring-gray-300 hover:bg-gray-50 focus-visible:ring-primary-600 dark:bg-transparent dark:text-white dark:ring-white/20 dark:hover:bg-white/5"
    >
        {{ __('Request client access') }}
    </a>
</div>
