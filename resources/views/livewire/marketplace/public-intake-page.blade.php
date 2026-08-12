<div>
    @if (! $found)
        <h1>Intake link not found</h1>
        <p class="muted">This link may be invalid. Please check the link you were given, or contact the firm directly.</p>
    @elseif (! $resumable)
        <h1>{{ $firmDisplayName }}</h1>
        <div class="notice info" role="status">This intake link is no longer available.</div>
        <p class="muted">Please contact the firm directly if you believe this is an error.</p>
    @elseif (! $editable)
        {{-- Submitted / UnderReview / ConflictReviewRequired / Accepted — the
             wizard is done; the Firm now owns the next step. Never re-opened
             for editing, and this same generic copy works both immediately
             after submission and on any later resume visit. --}}
        <h1>{{ $firmDisplayName }}</h1>
        <div class="notice success" role="status">
            Thanks — your secure intake has been submitted. {{ $firmDisplayName }} will review your request and follow up soon.
        </div>
        <p class="muted">Current status: {{ str($status)->headline() }}</p>
    @elseif (! $disclosureAcknowledged)
        <h1>{{ $firmDisplayName }}</h1>
        <h2>Before you begin</h2>
        <p>This secure intake form lets you share information about your legal matter with {{ $firmDisplayName }}. Please note:</p>
        <ul class="muted">
            <li>This form does not create an attorney-client relationship. One is only formed if and when {{ $firmDisplayName }} accepts your matter.</li>
            <li>Nothing you submit here is legal advice.</li>
            <li>Your information is shared only with {{ $firmDisplayName }}, the firm you selected.</li>
            <li>You can save your progress and return using this same link.</li>
        </ul>
        <div class="actions">
            <button type="button" wire:click="acknowledgeDisclosure" class="btn">Start Secure Intake</button>
        </div>
    @else
        <h1>{{ $firmDisplayName }}</h1>

        @if (isset($validationErrors['_general']))
            <div class="notice error" role="alert">{{ $validationErrors['_general'] }}</div>
        @endif

        @if ($reviewing)
            <h2>Review your answers</h2>

            @if ($totalCount > 0)
                <ul class="review-list">
                    @foreach ($reviewItems as $item)
                        <li>
                            <div>
                                <strong>{{ $item['label'] }}</strong>
                                <div class="review-value muted">{{ $item['value'] !== '' ? $item['value'] : '—' }}</div>
                            </div>
                            <button type="button" class="btn-link" wire:click="editAnswer('{{ $item['code'] }}')">Edit</button>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if (count($documentSummary) > 0)
                <h2>Attached documents</h2>
                <ul class="documents-list">
                    @foreach ($documentSummary as $document)
                        <li>
                            {{ $document['original_filename'] }}
                            @if ($document['pending'])
                                <span class="muted">(scanning…)</span>
                            @elseif (! $document['accepted'])
                                <span class="muted">(not usable)</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="consent-row">
                <input type="checkbox" id="communications-consent" wire:model="communicationsConsent">
                <label for="communications-consent">I agree to be contacted by {{ $firmDisplayName }} about this request.</label>
            </div>
            <div class="consent-row">
                <input type="checkbox" id="portal-consent" wire:model="portalConsent">
                <label for="portal-consent">I would like secure online access to my matter if {{ $firmDisplayName }} accepts it.</label>
            </div>

            <div class="actions">
                <button type="button" wire:click="submitIntake" wire:loading.attr="disabled" wire:target="submitIntake" class="btn">Submit Secure Intake</button>
            </div>
        @elseif ($questionCode === null)
            <div class="notice info" role="status">This firm's intake form isn't available right now. Please contact the firm directly.</div>
        @else
            <div class="progress" role="status" aria-live="polite">
                <div class="progress-track">
                    <div class="progress-fill" style="width: {{ $totalCount > 0 ? min(100, (int) round(($answeredCount / $totalCount) * 100)) : 0 }}%"></div>
                </div>
                <p class="muted">Question {{ min($answeredCount + 1, max($totalCount, 1)) }} of {{ $totalCount }}</p>
            </div>

            @if ($aiNotice)
                <div class="notice info" role="status">{{ $aiNotice }}</div>
            @endif

            @if ($aiAssistAvailable)
                <div class="chat-box">
                    <label for="chat-message">Or describe your situation in your own words</label>
                    <textarea id="chat-message" wire:model="chatMessage" placeholder="Tell us what's going on and we'll help fill in the details…"></textarea>
                    <div class="actions">
                        <button type="button" wire:click="sendChatMessage" wire:loading.attr="disabled" wire:target="sendChatMessage" class="btn btn-secondary">Send</button>
                    </div>
                </div>
            @endif

            <div class="field">
                <label for="answer-field">{{ $questionLabel }}@if ($questionRequired) <span aria-hidden="true">*</span>@endif</label>

                @if ($questionType === 'textarea')
                    <textarea id="answer-field" wire:model="answerValue" @if ($questionHelp) aria-describedby="answer-help" @endif @if (isset($validationErrors[$questionCode])) aria-invalid="true" @endif></textarea>
                @elseif ($questionType === 'select')
                    <select id="answer-field" wire:model="answerValue" @if ($questionHelp) aria-describedby="answer-help" @endif @if (isset($validationErrors[$questionCode])) aria-invalid="true" @endif>
                        <option value="">Select an option…</option>
                        @foreach ($questionOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                @elseif ($questionType === 'checkbox')
                    <div class="checkbox-row">
                        <input type="checkbox" id="answer-field" wire:model="answerValue">
                        <label for="answer-field">Yes</label>
                    </div>
                @else
                    @php
                        $inputType = match ($questionType) {
                            'email' => 'email',
                            'phone' => 'tel',
                            'number' => 'number',
                            'date' => 'date',
                            default => 'text',
                        };
                    @endphp
                    <input type="{{ $inputType }}" id="answer-field" wire:model="answerValue" @if ($questionHelp) aria-describedby="answer-help" @endif @if (isset($validationErrors[$questionCode])) aria-invalid="true" @endif>
                @endif

                @if ($questionHelp)
                    <p id="answer-help" class="muted help">{{ $questionHelp }}</p>
                @endif

                @if (isset($validationErrors[$questionCode]))
                    <p class="error" role="alert">{{ $validationErrors[$questionCode] }}</p>
                @endif
            </div>

            <div class="actions">
                <button type="button" wire:click="saveAnswer" wire:loading.attr="disabled" wire:target="saveAnswer" class="btn">Next</button>
                @if ($editingFromReview)
                    <button type="button" wire:click="backToReview" class="btn btn-secondary">Cancel</button>
                @endif
            </div>

            <details class="muted" style="margin-top: 24px;">
                <summary>Attach supporting documents (optional)</summary>
                <form method="POST" action="{{ route('public.marketplace-intakes.documents.store', $uuid) }}" enctype="multipart/form-data" style="margin-top: 12px;">
                    @csrf
                    <input type="file" name="file" aria-label="Attach a supporting document">
                    <div class="actions">
                        <button type="submit" class="btn btn-secondary">Upload</button>
                    </div>
                </form>

                @if (count($documentSummary) > 0)
                    <ul class="documents-list">
                        @foreach ($documentSummary as $document)
                            <li>
                                {{ $document['original_filename'] }}
                                @if ($document['pending'])
                                    <span class="muted">(scanning…)</span>
                                @elseif (! $document['accepted'])
                                    <span class="muted">(not usable)</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </details>
        @endif
    @endif
</div>
