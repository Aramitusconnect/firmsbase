<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Integrations\Enums\FinancialAccountClassification;
use App\Integrations\Services\FinancialAccountReclassificationService;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Models\FinancialAccountReclassificationRequest;
use App\Services\PlaidEntitlementPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * PlaidReclassificationApprovalsPage — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §2/§5). Every
 * `pending`/`first_approved` row firm-wide, with an "Approve" row
 * action hidden from the row's OWN `first_approved_by_firm_user_id` —
 * the same actor can never see their own request as approvable a
 * second time, a UX-layer MIRROR of `assertDistinctApprovers()`'s real,
 * service-layer-enforced boundary, never a substitute for it. This is
 * the first real, reachable caller of
 * `FinancialIntegrationAccessPolicyService::assertDistinctApprovers()`
 * (checkpoint4-security-review.md Finding 4/5 — the underlying service
 * enforces distinct-approver identity strictly; this page never
 * weakens that boundary for UI convenience).
 */
class PlaidReclassificationApprovalsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Reclassification Approvals';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $title = 'Pending Trust-Account Approvals';

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(PlaidEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(FinancialIntegrationAccessPolicyService::class)->canApprove($firmUser->role);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): Collection {
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null) {
                    return collect();
                }

                // CP8 fix: canAccess() gates navigation/route entry with
                // canApprove(), but this records() closure — the actual
                // data query — previously only checked for an
                // authenticated FirmUser. Any active firm user reaching
                // this route directly (bypassing nav) could read every
                // pending trust-account reclassification request
                // firm-wide (account names, requested classification,
                // reasoning) without holding the approve-tier role.
                // approve()/reject() were already independently re-gated
                // via assertCanApprove() inside
                // FinancialAccountReclassificationService — only this
                // read path was missing its own re-check.
                if (! app(FinancialIntegrationAccessPolicyService::class)->canApprove($firmUser->role)) {
                    return collect();
                }

                return FinancialAccountReclassificationRequest::query()
                    ->where('firm_id', $firmUser->firm_id)
                    ->whereIn('status', ['pending', 'first_approved'])
                    ->with('bankAccount')
                    ->orderByDesc('requested_at')
                    ->get()
                    ->map(fn (FinancialAccountReclassificationRequest $r): array => [
                        'id' => $r->id,
                        'account' => $r->bankAccount?->account_name ?? "Account #{$r->bank_account_id}",
                        'previous_classification' => $r->previous_classification !== null ? FinancialAccountClassification::from($r->previous_classification)->label() : 'Unclassified',
                        'requested_classification' => FinancialAccountClassification::from($r->requested_classification)->label(),
                        'reason' => $r->reason,
                        'status' => $r->status,
                        'first_approved_by_firm_user_id' => $r->first_approved_by_firm_user_id,
                        'requested_at' => $r->requested_at?->toDayDateTimeString(),
                    ]);
            })
            ->columns([
                TextColumn::make('account')->label('Account'),
                TextColumn::make('previous_classification')->label('From'),
                TextColumn::make('requested_classification')->label('To'),
                TextColumn::make('reason')->limit(60),
                TextColumn::make('status')->badge(),
                TextColumn::make('requested_at')->label('Requested'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(function (array $record): bool {
                        $firmUser = Auth::user()?->activeFirmUser();

                        if ($firmUser === null) {
                            return false;
                        }

                        // UX hint only — the real boundary is
                        // assertDistinctApprovers() inside the service,
                        // re-checked unconditionally below.
                        return (int) ($record['first_approved_by_firm_user_id'] ?? 0) !== (int) $firmUser->id;
                    })
                    ->action(fn (array $record) => $this->approve((int) $record['id'])),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (array $record) => $this->reject((int) $record['id'])),
            ])
            ->emptyStateHeading('No pending reclassification approvals')
            ->paginated(false);
    }

    private function approve(int $requestId): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return;
        }

        try {
            $request = (new TenantContextService)->runWithFirmContext($firmUser->firm_id, fn () => FinancialAccountReclassificationRequest::query()
                ->where('id', $requestId)
                ->where('firm_id', $firmUser->firm_id)
                ->firstOrFail());

            app(FinancialAccountReclassificationService::class)->approve($firmUser->firm, $request, $firmUser);

            Notification::make()->title('Approval recorded')->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title('Could not approve')->body($e->getMessage())->danger()->send();
        }
    }

    private function reject(int $requestId): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return;
        }

        try {
            $request = (new TenantContextService)->runWithFirmContext($firmUser->firm_id, fn () => FinancialAccountReclassificationRequest::query()
                ->where('id', $requestId)
                ->where('firm_id', $firmUser->firm_id)
                ->firstOrFail());

            app(FinancialAccountReclassificationService::class)->reject($firmUser->firm, $request, $firmUser);

            Notification::make()->title('Request rejected')->success()->send();
        } catch (RuntimeException $e) {
            Notification::make()->title('Could not reject')->body($e->getMessage())->danger()->send();
        }
    }
}
