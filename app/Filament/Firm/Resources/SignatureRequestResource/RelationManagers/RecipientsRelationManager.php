<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\SignatureRequestResource\RelationManagers;

use App\Enums\SignatureRecipientType;
use App\Enums\SignatureRequestStatus;
use App\Models\Client;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Services\SignatureAndPdfAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * RecipientsRelationManager — "Recipients" tab on ViewSignatureRequest.
 *
 * signature_request_recipients has NO production INSERT caller anywhere
 * in this codebase before this workstream (confirmed directly by that
 * table's own base RLS migration, 2026_08_27_950036 — "this table's
 * INSERT path is currently entirely dormant in production"). Without
 * some way to add a recipient, SignatureRequestWorkflowService::send()
 * can never succeed (it hard-requires at least one recipient), which
 * would leave this entire firm-side Resource able to create/review a
 * request but never actually send one — a dead end, not a narrower
 * version of the feature. The "+ Add Recipient" action below is
 * therefore the one deliberately-added exception to "no bespoke extra
 * fields": a direct, tenant-context-wrapped
 * SignatureRequestRecipient::create() (status always Draft — a fresh
 * row, not a status transition, so this does not touch
 * SignatureWorkflowTransitionService/SignatureRecipientWorkflowService's
 * transition-graph enforcement at all) gated behind the exact same
 * canManageRequests() ceiling SignatureRequestWorkflowService::create()
 * itself uses, and only while the parent request is still Draft.
 * Otherwise strictly read-only — no edit/delete — every subsequent
 * status change is a signer's own action via SignatureRecipientController,
 * never something a firm user does through this table.
 */
class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Recipients';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! $ownerRecord instanceof SignatureRequest || (int) $firmUser->firm_id !== (int) $ownerRecord->firm_id) {
            return false;
        }

        return app(SignatureAndPdfAccessPolicyService::class)->canUseSignatures((int) $firmUser->firm_id);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('signer_name')
            ->columns([
                TextColumn::make('signer_name')->label('Name'),
                TextColumn::make('signer_email')->label('Email'),
                TextColumn::make('recipient_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) str($state)->headline()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) str($state)->headline()),
                TextColumn::make('viewed_at')->dateTime()->placeholder('—'),
                TextColumn::make('consented_at')->dateTime()->placeholder('—'),
                TextColumn::make('signed_at')->dateTime()->placeholder('—'),
            ])
            ->headerActions([
                $this->addRecipientAction(),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    private function addRecipientAction(): Action
    {
        return Action::make('addRecipient')
            ->label('+ Add Recipient')
            ->modalHeading('Add Recipient')
            ->modalSubmitActionLabel('Add Recipient')
            ->schema([
                Select::make('recipient_type')
                    ->label('Type')
                    ->options(collect(SignatureRecipientType::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all())
                    ->default(SignatureRecipientType::External->value)
                    ->required()
                    ->live(),

                Select::make('client_id')
                    ->label('Client')
                    ->visible(fn (Get $get): bool => $get('recipient_type') === SignatureRecipientType::Client->value)
                    ->options(fn (): array => self::firmScoped(fn () => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all()))
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (Get $get, $state, callable $set): void {
                        if (! filled($state)) {
                            return;
                        }

                        $client = self::firmScoped(fn () => Client::query()->where('id', $state)->first());

                        if ($client !== null) {
                            $set('signer_name', $client->display_name);
                            $set('signer_email', $client->email);
                        }
                    }),

                TextInput::make('signer_name')
                    ->label('Signer Name')
                    ->required(),

                TextInput::make('signer_email')
                    ->label('Signer Email')
                    ->email()
                    ->required(),
            ])
            ->visible(function (): bool {
                $ownerRecord = $this->getOwnerRecord();
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null || ! $ownerRecord instanceof SignatureRequest || $ownerRecord->status !== SignatureRequestStatus::Draft) {
                    return false;
                }

                return app(SignatureAndPdfAccessPolicyService::class)->canManageRequests($firmUser);
            })
            ->action(function (array $data): void {
                $ownerRecord = $this->getOwnerRecord();
                $firmUser = Auth::user()?->activeFirmUser();

                if ($firmUser === null || ! $ownerRecord instanceof SignatureRequest || ! app(SignatureAndPdfAccessPolicyService::class)->canManageRequests($firmUser)) {
                    Notification::make()->title('Not permitted')->danger()->send();

                    return;
                }

                app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    function () use ($data, $ownerRecord, $firmUser): void {
                        $fresh = SignatureRequest::query()->where('id', $ownerRecord->id)->firstOrFail();

                        if ((int) $firmUser->firm_id !== (int) $fresh->firm_id || $fresh->status !== SignatureRequestStatus::Draft) {
                            Notification::make()->title('This request can no longer accept new recipients.')->danger()->send();

                            return;
                        }

                        $clientId = null;

                        if (($data['recipient_type'] ?? null) === SignatureRecipientType::Client->value && filled($data['client_id'] ?? null)) {
                            $client = Client::query()->where('id', $data['client_id'])->where('firm_id', $fresh->firm_id)->first();
                            $clientId = $client?->id;
                        }

                        SignatureRequestRecipient::create([
                            'signature_request_id' => $fresh->id,
                            'firm_id' => $fresh->firm_id,
                            'recipient_type' => $data['recipient_type'],
                            'client_id' => $clientId,
                            'signer_name' => (string) $data['signer_name'],
                            'signer_email' => (string) $data['signer_email'],
                            'status' => SignatureRequestStatus::Draft->value,
                        ]);

                        Notification::make()->title('Recipient added')->success()->send();
                    },
                );
            });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function firmScoped(callable $callback)
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return [];
        }

        return app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, $callback);
    }
}
