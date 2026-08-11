{{--
    Mission 1B (Extreme Security Hardening) — WebAuthn registration
    ceremony. Vanilla `navigator.credentials.create()`, no external JS
    library. On success, writes the response into this mounted
    action's own `credentialResponseJson` field via the exact
    `$wire.set('mountedActions.0.data.<field>', ...)` binding this
    codebase's own PHPUnit tests already exercise
    (LivewireUpdateRouteTenantContextFixTest::dataUpdates()) — never
    verified against a real browser/authenticator in this environment
    (no browser automation available here); the cryptographic
    verification this feeds IS fully verified, see
    WebAuthnCeremonyServiceTest.
--}}
<div
    x-data="{
        status: 'idle',
        error: null,
        async register() {
            this.status = 'waiting';
            this.error = null;

            try {
                const options = {{ $optionsJson }};

                const publicKey = {
                    ...options,
                    challenge: webauthnBase64UrlToBuffer(options.challenge),
                    user: { ...options.user, id: webauthnBase64UrlToBuffer(options.user.id) },
                    excludeCredentials: (options.excludeCredentials || []).map((c) => ({
                        ...c,
                        id: webauthnBase64UrlToBuffer(c.id),
                    })),
                };

                const credential = await navigator.credentials.create({ publicKey });

                const responseJson = JSON.stringify({
                    id: credential.id,
                    rawId: webauthnBufferToBase64Url(credential.rawId),
                    type: credential.type,
                    response: {
                        clientDataJSON: webauthnBufferToBase64Url(credential.response.clientDataJSON),
                        attestationObject: webauthnBufferToBase64Url(credential.response.attestationObject),
                        transports: credential.response.getTransports ? credential.response.getTransports() : [],
                    },
                });

                $wire.set('mountedActions.0.data.credentialResponseJson', responseJson);
                this.status = 'done';
            } catch (e) {
                this.error = e.message || String(e);
                this.status = 'error';
            }
        },
    }"
    x-init="register()"
>
    <p x-show="status === 'waiting'">{{ __('Follow your browser\'s prompt to use your security key or passkey…') }}</p>
    <p x-show="status === 'done'" class="fi-color-success">{{ __('Security key detected. Click Register to finish.') }}</p>
    <template x-if="status === 'error'">
        <p class="fi-color-danger" x-text="error"></p>
    </template>
    <button type="button" x-show="status === 'error'" x-on:click="register()">{{ __('Try again') }}</button>
</div>

@once
    @push('scripts')
        <script>
            function webauthnBase64UrlToBuffer(value) {
                const padded = value.replace(/-/g, '+').replace(/_/g, '/').padEnd(value.length + (4 - (value.length % 4)) % 4, '=');
                const raw = window.atob(padded);
                const buffer = new Uint8Array(raw.length);
                for (let i = 0; i < raw.length; i++) {
                    buffer[i] = raw.charCodeAt(i);
                }
                return buffer.buffer;
            }

            function webauthnBufferToBase64Url(buffer) {
                const bytes = new Uint8Array(buffer);
                let binary = '';
                for (let i = 0; i < bytes.byteLength; i++) {
                    binary += String.fromCharCode(bytes[i]);
                }
                return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
            }
        </script>
    @endpush
@endonce
