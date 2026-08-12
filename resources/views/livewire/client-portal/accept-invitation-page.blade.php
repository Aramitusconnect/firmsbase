<div>
    @if (! $found || ! $valid)
        <h1>Invitation link not found</h1>
        <p class="muted">This invitation link is invalid or has already been used. Please contact your firm directly if you need a new invitation.</p>
    @else
        <h1>{{ $firmDisplayName }}</h1>
        <h2>Set up your secure portal access</h2>
        <p class="muted">Create a password to access your secure client portal with {{ $firmDisplayName }}.</p>

        @if (isset($validationErrors['password']))
            <div class="notice error" role="alert">{{ $validationErrors['password'] }}</div>
        @endif

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" wire:model="password" autocomplete="new-password">
        </div>

        <div class="field">
            <label for="password-confirmation">Confirm password</label>
            <input type="password" id="password-confirmation" wire:model="passwordConfirmation" autocomplete="new-password">
        </div>

        <div class="actions">
            <button type="button" wire:click="acceptInvitation" wire:loading.attr="disabled" wire:target="acceptInvitation" class="btn">Set Up Portal Access</button>
        </div>
    @endif
</div>
