<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\TrustLedgerResource\Pages;

use App\Enums\TrustApprovalEventType;
use App\Filament\Firm\Concerns\ScopesQueriesToActiveFirm;
use App\Filament\Firm\Resources\TrustLedgerResource;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\ApproveDepositAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\CloseTrustLedgerAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\DenyAdjustmentAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\DenyDepositAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\FirstApproveAdjustmentAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\FreezeTrustLedgerAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\PostDepositAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\RequestAdjustmentAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\RequestDepositAction;
use App\Filament\Firm\Resources\TrustLedgerResource\Actions\SecondApproveAdjustmentAction;
use App\Models\TrustApprovalEvent;
use App\Models\TrustLedger;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewTrustLedger — read-only Infolist only (no `form()` on
 * TrustLedgerResource at all). Deposit request/approve/deny/post and
 * Adjustment request/first-approve/second-approve/deny live here as
 * header Actions (see ApproveDepositAction's own docblock for why —
 * TrustLedger has no `approvalEvents()` relation to bind a
 * RelationManager table to, since trust_approval_events is a Trust
 * model file this task must not modify). The "Pending Approval Events"
 * section below is a purely READ-ONLY visibility aid for those same
 * pending events (via a plain `state()` closure, not a relation), built
 * so a user can see what is pending before opening one of the Approve/
 * Deny/Post Action forms above.
 */
class ViewTrustLedger extends ViewRecord
{
    use ScopesQueriesToActiveFirm;

    protected static string $resource = TrustLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RequestDepositAction::make(),
            ApproveDepositAction::make(),
            DenyDepositAction::make(),
            PostDepositAction::make(),
            RequestAdjustmentAction::make(),
            FirstApproveAdjustmentAction::make(),
            SecondApproveAdjustmentAction::make(),
            DenyAdjustmentAction::make(),
            FreezeTrustLedgerAction::make(),
            CloseTrustLedgerAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Trust Ledger')
                ->columns(2)
                ->schema([
                    TextEntry::make('client.display_name')->label('Client')->placeholder('—'),
                    TextEntry::make('trustAccount.account_name')->label('Trust Account')->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) $state)
                        ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                            'active' => 'success',
                            'frozen' => 'warning',
                            'closed' => 'gray',
                            default => 'gray',
                        }),
                    TextEntry::make('balance.balance_cents')
                        ->label('Balance')
                        ->formatStateUsing(fn ($state): string => '$'.number_format(((int) $state) / 100, 2)),
                    TextEntry::make('created_at')->label('Created At')->dateTime(),
                ]),
            Section::make('Pending Approval Events')
                ->description('Deposit and high-risk adjustment requests awaiting approval/posting for this ledger. Read-only — use the header actions above to act on one.')
                ->schema([
                    RepeatableEntry::make('pendingApprovalEvents')
                        ->hiddenLabel()
                        ->state(fn (TrustLedger $record): array => $this->pendingApprovalEvents($record))
                        ->schema([
                            TextEntry::make('type')->label('Type')->badge(),
                            TextEntry::make('amount')->label('Amount'),
                            TextEntry::make('actor')->label('Actor'),
                            TextEntry::make('when')->label('When'),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    /**
     * @return array<int, array{type: string, amount: string, actor: string, when: string}>
     */
    private function pendingApprovalEvents(TrustLedger $record): array
    {
        return self::firmScoped(function () use ($record): array {
            return TrustApprovalEvent::query()
                ->where('trust_ledger_id', $record->id)
                ->whereIn('event_type', [
                    TrustApprovalEventType::DepositRequested->value,
                    TrustApprovalEventType::DepositApproved->value,
                    TrustApprovalEventType::AdjustmentRequested->value,
                    TrustApprovalEventType::AdjustmentFirstApproved->value,
                ])
                ->with('actor.user')
                ->orderByDesc('created_at')
                ->limit(25)
                ->get()
                ->map(fn (TrustApprovalEvent $event): array => [
                    'type' => str($event->event_type->value)->headline()->toString(),
                    'amount' => '$'.number_format($event->amount_cents / 100, 2),
                    'actor' => $event->actor?->user?->name ?? '—',
                    'when' => $event->created_at?->diffForHumans() ?? '—',
                ])
                ->all();
        }) ?? [];
    }
}
