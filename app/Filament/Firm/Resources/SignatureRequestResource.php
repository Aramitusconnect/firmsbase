<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources;

use App\Enums\SignatureRequestStatus;
use App\Filament\Firm\Resources\SignatureRequestResource\Actions\AttorneyReviewSignatureRequestAction;
use App\Filament\Firm\Resources\SignatureRequestResource\Actions\SendSignatureRequestAction;
use App\Filament\Firm\Resources\SignatureRequestResource\Actions\VoidSignatureRequestAction;
use App\Filament\Firm\Resources\SignatureRequestResource\Pages\ListSignatureRequests;
use App\Filament\Firm\Resources\SignatureRequestResource\Pages\ViewSignatureRequest;
use App\Filament\Firm\Resources\SignatureRequestResource\RelationManagers\RecipientsRelationManager;
use App\Models\Client;
use App\Models\Matter;
use App\Models\SignatureRequest;
use App\Services\SignatureAndPdfAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * SignatureRequestResource — Non-payment completion program,
 * e-signature signer-facing flow. First-ever Filament surface for
 * SignatureRequest (previously zero UI existed anywhere — confirmed by
 * direct repository search before this workstream). List + View only
 * (mirrors InvoiceResource's/PaymentResource's own established "no
 * bare Create/Edit form" ruling for a workflow-governed record — every
 * mutation is one of the dedicated Actions below, each calling exactly
 * one SignatureRequestWorkflowService method, never a raw
 * SignatureRequest::create()/update()).
 *
 * `status`/`attorney_reviewed_at`/`sent_at`/`completed_at`/`voided_at`/
 * `declined_at` are NEVER editable form fields anywhere in this
 * Resource or its Actions — SignatureRequestWorkflowService/
 * SignatureRecipientWorkflowService/SignatureRequestAggregationService
 * are the exclusive writers of every one of them.
 *
 * Authorization: gated on the existing, already-built
 * SignatureAndPdfAccessPolicyService (canUseSignatures() entitlement
 * gate for navigation/access; canManageRequests()/canReviewAsAttorney()/
 * canVoid() per-Action, mirrored inside each Action's own visible()/
 * action() closures exactly like SendInvoiceAction/VoidInvoiceAction
 * already do for BillingAccessPolicyService).
 */
class SignatureRequestResource extends Resource
{
    protected static ?string $model = SignatureRequest::class;

    protected static ?string $slug = 'signature-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $navigationLabel = 'E-Signature';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return parent::canAccess() && static::isFirmEntitled();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::isFirmEntitled();
    }

    private static function isFirmEntitled(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(SignatureAndPdfAccessPolicyService::class)->canUseSignatures((int) $firmUser->firm_id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? (string) str($state->value)->headline() : (string) str($state)->headline())
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'completed', 'signed' => 'success',
                        'sent', 'viewed', 'consented' => 'info',
                        'declined', 'expired', 'voided' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('client.display_name')->label('Client')->placeholder('—'),
                TextColumn::make('requestedByFirmUser.name')->label('Requested By')->placeholder('—'),
                TextColumn::make('attorney_reviewed_at')->label('Attorney Reviewed')->dateTime()->placeholder('—'),
                TextColumn::make('sent_at')->dateTime()->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => collect(SignatureRequestStatus::cases())->mapWithKeys(fn ($case) => [$case->value => (string) str($case->value)->headline()])->all()),
                SelectFilter::make('client_id')
                    ->label('Client')
                    ->options(fn (): array => Client::query()->orderBy('display_name')->pluck('display_name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('matter_id')
                    ->label('Matter')
                    ->options(fn (): array => Matter::query()
                        ->with('client')
                        ->get()
                        ->mapWithKeys(fn (Matter $matter): array => [
                            $matter->id => trim(($matter->client?->display_name ?? 'Matter').' — '."#{$matter->id}"),
                        ])
                        ->all()),
            ])
            ->recordActions([
                AttorneyReviewSignatureRequestAction::make(),
                SendSignatureRequestAction::make(),
                VoidSignatureRequestAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RecipientsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSignatureRequests::route('/'),
            'view' => ViewSignatureRequest::route('/{record}'),
        ];
    }
}
