<x-filament-panels::page>
    {{ $this->content }}

    @if ($linkToken !== '')
        <div
            wire:ignore
            x-data="{
                open() {
                    const handler = Plaid.create({
                        token: @js($linkToken),
                        onSuccess: (public_token, metadata) => {
                            fetch(@js(route('client-portal.plaid.exchange')), {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                                },
                                body: JSON.stringify({
                                    public_token: public_token,
                                    firm_integration_id: @js($firmIntegrationId),
                                    matter_id: @js($matterId),
                                }),
                            }).then((response) => response.json()).then((data) => {
                                if (data.redirect) {
                                    window.location.href = data.redirect;
                                }
                            });
                        },
                        onExit: () => {},
                    });
                    handler.open();
                }
            }"
            class="fi-financial-evidence-panel"
        >
            <script src="https://cdn.plaid.com/link/v2/stable/link-initialize.js"></script>
            <button
                type="button"
                x-on:click="open()"
                class="fi-btn fi-btn-color-primary fi-btn-size-md"
            >
                Connect with Plaid
            </button>
        </div>
    @else
        <p>No pending connection request could be prepared for this matter.</p>
    @endif
</x-filament-panels::page>
