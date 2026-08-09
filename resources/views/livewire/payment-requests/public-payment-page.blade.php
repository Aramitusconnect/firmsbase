<div @if($brandColor) style="--brand-color: {{ $brandColor }};" @endif>
    @if (! $found)
        <h1>Payment link not found</h1>
        <p class="muted">This link may be invalid. Please check the link you were given, or contact the firm directly.</p>
    @elseif ($resultMessage)
        <div class="notice {{ $resultSucceeded ? 'success' : 'error' }}">
            {{ $resultMessage }}
        </div>
        @if ($resultSucceeded)
            <p class="muted">You may close this page.</p>
        @endif
    @elseif (! $payable)
        <h1>{{ $firmDisplayName }}</h1>
        <div class="notice info">This payment link is no longer available.</div>
        <p class="muted">Please contact the firm directly if you believe this is an error.</p>
    @else
        <h1>{{ $firmDisplayName }}</h1>
        <p class="muted">{{ $purposeDescription }}</p>

        @if ($amountRule === 'fixed')
            <div class="amount-display">${{ number_format(($fixedAmountCents ?? 0) / 100, 2) }}</div>
        @else
            <label for="submittedAmountDollars">Amount to pay (USD)</label>
            <input type="text" id="submittedAmountDollars" wire:model="submittedAmountDollars" inputmode="decimal" placeholder="0.00">
            @if ($amountRule === 'up_to' && $remainingCents !== null)
                <p class="muted">Up to ${{ number_format($remainingCents / 100, 2) }} remaining.</p>
            @endif
        @endif

        <button type="button" wire:click="submit" wire:loading.attr="disabled" @if($submitting) disabled @endif>
            {{ $submitting ? 'Processing…' : 'Pay now' }}
        </button>

        <p class="muted" style="margin-top:16px;font-size:0.8rem;">
            Payments are processed securely. This page never displays or stores your card details.
        </p>
    @endif
</div>
