<?php

declare(strict_types=1);

namespace App\Filament\Firm\Livewire;

use App\Filament\Firm\Resources\ClientResource\Actions\AddClientAction;
use App\Filament\Firm\Resources\PaymentResource\Actions\RecordPaymentAction;
use Filament\Actions\Action;
use Filament\Livewire\Topbar;

/**
 * FirmTopbar — hosts the global "Quick Add" menu's two modal-backed
 * items (Client, Payment) so they are mountable from ANY page in the
 * Firm panel, not just ClientResource's/PaymentResource's own list
 * pages. This is the Filament v4-supported extension point
 * (`Panel::topbarLivewireComponent()` — see `HasTopbar`'s own source)
 * for swapping the panel's topbar Livewire component; the Quick Add
 * dropdown itself is injected into this component's rendered DOM via
 * a `Panel::renderHook(PanelsRenderHook::TOPBAR_END, ...)` registered
 * in `FirmPanelProvider` (see `resources/views/filament/firm/
 * quick-add-menu.blade.php`), so `wire:click="mountAction(...)"`
 * inside that partial resolves against THIS component (the nearest
 * Livewire root in the DOM), not the current page's own component.
 *
 * Deliberately NOT a reimplementation: `quickAddClientAction()` and
 * `quickAddPaymentAction()` return the exact same Action classes
 * ClientResource\Pages\ListClients and PaymentResource\Pages\
 * ListPayments already use as their own "+ Add Client"/"Record
 * Payment" header actions (`AddClientAction`/`RecordPaymentAction`) —
 * same form schema, same service calls
 * (`LeadConversionService::convert()`/`ManualPaymentService::submit()`),
 * same role-authorization gate (each Action's own `->visible()`
 * closure, checked again defense-in-depth inside its own `->action()`
 * closure) as their originals. Both Actions are already fully
 * self-contained (no dependency on a specific hosting Resource/Page —
 * confirmed by direct source read of both classes and the
 * `RecordsManualPayment` trait they/their siblings share), so hosting
 * them on a different Livewire component changes nothing about their
 * behavior or authorization.
 *
 * The other seven Quick Add items (Contact/Lead/Task/Deadline/
 * TimeEntry/Expense/Document Request) are plain links to each
 * resource's own `CreateRecord` page route — see the Blade partial's
 * own comments for why those are never forced into a modal here.
 */
class FirmTopbar extends Topbar
{
    public function quickAddClientAction(): Action
    {
        return AddClientAction::make();
    }

    public function quickAddPaymentAction(): Action
    {
        return RecordPaymentAction::make();
    }
}
