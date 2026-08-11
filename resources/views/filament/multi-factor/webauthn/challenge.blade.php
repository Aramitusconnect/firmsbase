{{--
    Mission 1B (Extreme Security Hardening) — WebAuthn login-time
    challenge. Vanilla `navigator.credentials.get()`, triggered
    automatically on mount (a real browser prompts the user for their
    security key/passkey without an extra click). Writes the result
    into the challenge form's own `webauthnResponseJson` field.

    State path derived directly from Filament's own
    Auth\Pages\Login::defaultMultiFactorChallengeForm() (vendor source):
    the outer schema binds `->statePath('data.multiFactor')`, each
    provider's own challenge components are wrapped in
    `Group::make(...)->statePath($provider->getId())` (this provider's
    getId() is 'webauthn') — giving the full path
    `data.multiFactor.webauthn.webauthnResponseJson`. This composition
    could not be verified against a real browser/Livewire runtime in
    this environment (no browser automation available here); the
    cryptographic verification this field feeds IS fully verified, see
    WebAuthnCeremonyServiceTest.
--}}
<div
    x-data="{
        status: 'idle',
        error: null,
        async authenticate() {
            this.status = 'waiting';
            this.error = null;

            try {
                const options = {{ $optionsJson }};

                const publicKey = {
                    ...options,
                    challenge: webauthnBase64UrlToBuffer(options.challenge),
                    allowCredentials: (options.allowCredentials || []).map((c) => ({
                        ...c,
                        id: webauthnBase64UrlToBuffer(c.id),
                    })),
                };

                const credential = await navigator.credentials.get({ publicKey });

                const responseJson = JSON.stringify({
                    id: credential.id,
                    rawId: webauthnBufferToBase64Url(credential.rawId),
                    type: credential.type,
                    response: {
                        clientDataJSON: webauthnBufferToBase64Url(credential.response.clientDataJSON),
                        authenticatorData: webauthnBufferToBase64Url(credential.response.authenticatorData),
                        signature: webauthnBufferToBase64Url(credential.response.signature),
                        userHandle: credential.response.userHandle ? webauthnBufferToBase64Url(credential.response.userHandle) : null,
                    },
                });

                $wire.set('data.multiFactor.webauthn.webauthnResponseJson', responseJson);
                this.status = 'done';
            } catch (e) {
                this.error = e.message || String(e);
                this.status = 'error';
            }
        },
    }"
    x-init="authenticate()"
>
    <p x-show="status === 'waiting'">{{ __('Follow your browser\'s prompt to use your security key or passkey…') }}</p>
    <p x-show="status === 'done'" class="fi-color-success">{{ __('Security key verified.') }}</p>
    <template x-if="status === 'error'">
        <p class="fi-color-danger" x-text="error"></p>
    </template>
    <button type="button" x-show="status === 'error'" x-on:click="authenticate()">{{ __('Try again') }}</button>
</div>
