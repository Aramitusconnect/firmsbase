{{--
    Quick Add — global header menu (Firm Feature Manifest Tier 1
    completeness pass). Rendered via a `Panel::renderHook(TOPBAR_END, ...)`
    registered in `FirmPanelProvider`, and injected into
    `App\Filament\Firm\Livewire\FirmTopbar`'s own DOM (see that class's
    docblock for why `wire:click="mountAction(...)"` below resolves
    correctly against it rather than the current page's Livewire
    component).

    ONLY the nine Tier 1 creation flows that actually, currently exist —
    no "+ Matter" (Matter creation has no safe general-purpose service
    yet — Firm Feature Manifest §2), nothing for Trust/Invoices/AI/
    Documents-file-upload (none of those exist yet either).

    Two items (Client, Payment) are modal Actions — the exact same
    `AddClientAction`/`RecordPaymentAction` classes ClientResource's/
    PaymentResource's own list pages use (see FirmTopbar's own
    docblock) — mounted here via `mountAction()`. The remaining seven
    are plain links to each resource's own `CreateRecord` page route:
    none of those resources expose a modal Action for creation (each
    is a genuinely ordinary Filament Create page, per that resource's
    own docblock), so forcing them into a modal here would diverge
    from — not reuse — the resource's real creation flow.

    Every item's visibility below re-checks the SAME authorization the
    target flow itself already enforces (each domain's own
    `*AccessPolicyService` for the two Action-backed items, or the
    resource's own `canAccess()`/`canCreate()` — which is exactly what
    each resource's real `CreateRecord` page authorizes against via
    `CanAuthorizeResourceAccess`/`CreateRecord::authorizeAccess()` — for
    the seven link-backed items) — never a new, separately-maintained
    authorization rule.
--}}
@php
    $firmUser = auth()->user()?->activeFirmUser();
@endphp

@if ($firmUser)
    @php
        $canAddClient = app(\App\Services\ClientCrmAccessPolicyService::class)->canConvertLead($firmUser->role);
        $canAddContact = \App\Filament\Firm\Resources\ContactResource::canAccess() && \App\Filament\Firm\Resources\ContactResource::canCreate();
        $canAddLead = \App\Filament\Firm\Resources\FirmLeadResource::canAccess() && \App\Filament\Firm\Resources\FirmLeadResource::canCreate();
        $canAddTask = \App\Filament\Firm\Resources\TaskResource::canAccess() && \App\Filament\Firm\Resources\TaskResource::canCreate();
        $canAddDeadline = \App\Filament\Firm\Resources\DeadlineResource::canAccess() && \App\Filament\Firm\Resources\DeadlineResource::canCreate();
        $canAddTimeEntry = \App\Filament\Firm\Resources\TimeEntryResource::canAccess() && \App\Filament\Firm\Resources\TimeEntryResource::canCreate();
        $canAddExpense = \App\Filament\Firm\Resources\ExpenseResource::canAccess() && \App\Filament\Firm\Resources\ExpenseResource::canCreate();
        $canAddPayment = app(\App\Services\PaymentAccessPolicyService::class)->canRecordPayment($firmUser->role);
        $canAddDocumentRequest = \App\Filament\Firm\Resources\DocumentRequestResource::canAccess() && \App\Filament\Firm\Resources\DocumentRequestResource::canCreate();

        $hasAnyQuickAddItem = $canAddClient || $canAddContact || $canAddLead || $canAddTask
            || $canAddDeadline || $canAddTimeEntry || $canAddExpense || $canAddPayment || $canAddDocumentRequest;
    @endphp

    @if ($hasAnyQuickAddItem)
        <x-filament::dropdown placement="bottom-end" teleport>
            <x-slot name="trigger">
                <x-filament::button
                    icon="heroicon-o-plus"
                    color="primary"
                    size="sm"
                    class="fi-firm-quick-add-trigger"
                >
                    Quick Add
                </x-filament::button>
            </x-slot>

            <x-filament::dropdown.list>
                @if ($canAddClient)
                    <x-filament::dropdown.list.item
                        icon="heroicon-o-user-group"
                        wire:click="mountAction('quickAddClient')"
                    >
                        Client
                    </x-filament::dropdown.list.item>
                @endif

                @if ($canAddContact)
                    <x-filament::dropdown.list.item
                        icon="heroicon-o-identification"
                        tag="a"
                        :href="\App\Filament\Firm\Resources\ContactResource::getUrl('create')"
                    >
                        Contact
                    </x-filament::dropdown.list.item>
                @endif

                @if ($canAddLead)
                    <x-filament::dropdown.list.item
                        icon="heroicon-o-user-plus"
                        tag="a"
                        :href="\App\Filament\Firm\Resources\FirmLeadResource::getUrl('create')"
                    >
                        Lead
                    </x-filament::dropdown.list.item>
                @endif

                @if ($canAddTask)
                    <x-filament::dropdown.list.item
                        icon="heroicon-o-check-circle"
                        tag="a"
                        :href="\App\Filament\Firm\Resources\TaskResource::getUrl('create')"
                    >
                        Task
                    </x-filament::dropdown.list.item>
                @endif

                @if ($canAddDeadline)
                    <x-filament::dropdown.list.item
                        icon="heroicon-o-calendar-days"
                        tag="a"
                        :href="\App\Filament\Firm\Resources\DeadlineResource::getUrl('create')"
                    >
                        Deadline
                    </x-filament::dropdown.list.item>
                @endif

                @if ($canAddTimeEntry)
                    <x-filament::dropdown.list.item
                        icon="heroicon-o-clock"
                        tag="a"
                        :href="\App\Filament\Firm\Resources\TimeEntryResource::getUrl('create')"
                    >
                        Time Entry
                    </x-filament::dropdown.list.item>
                @endif

                @if ($canAddExpense)
                    <x-filament::dropdown.list.item
                        icon="heroicon-o-receipt-percent"
                        tag="a"
                        :href="\App\Filament\Firm\Resources\ExpenseResource::getUrl('create')"
                    >
                        Expense
                    </x-filament::dropdown.list.item>
                @endif

                @if ($canAddPayment)
                    <x-filament::dropdown.list.item
                        icon="heroicon-o-banknotes"
                        wire:click="mountAction('quickAddPayment')"
                    >
                        Payment
                    </x-filament::dropdown.list.item>
                @endif

                @if ($canAddDocumentRequest)
                    <x-filament::dropdown.list.item
                        icon="heroicon-o-clipboard-document-list"
                        tag="a"
                        :href="\App\Filament\Firm\Resources\DocumentRequestResource::getUrl('create')"
                    >
                        Document Request
                    </x-filament::dropdown.list.item>
                @endif
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @endif
@endif
