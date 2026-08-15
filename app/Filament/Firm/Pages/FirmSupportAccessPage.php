<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Services\FirmSupportAccessPolicyService;
use App\Services\FirmSupportAccessService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
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
 * FirmSupportAccessPage — Prompt 6. The firm-facing surface for the
 * customer-consent step ordinary support access depends on: reviewing who
 * on the platform support team is asking to enter this firm's data and
 * why, approving or denying that request, seeing which support sessions
 * are currently active, revoking one immediately, and inspecting the
 * firm's own history of past support access.
 *
 * Before this page existed there was no firm-facing surface anywhere in
 * the application that could reach SupportAccessRequestService::approve()/
 * deny() — their only callers were tests — so the consent the zero-trust
 * support design is built on could not actually be given. See
 * FirmSupportAccessService's own docblock for the full gap description.
 *
 * A Page rather than a Resource, matching FirmSecurityActivityPage's own
 * precedent: this is a decision surface over a firm-wide stream of
 * governance records, not CRUD over a firm-owned model — nothing here is
 * ever created, edited or deleted from the firm panel.
 *
 * AUTHORIZATION — every read and every action re-checks
 * FirmSupportAccessPolicyService (FirmOwner only) inside
 * FirmSupportAccessService, on every call. canAccess()/
 * shouldRegisterNavigation() below hide the page from unauthorized roles,
 * but that is presentation only: the service refuses regardless, so a
 * hidden button is never the thing standing between a Paralegal and an
 * approval. No firm id is ever accepted from the request — the acting
 * user's own firm is resolved server-side — so a substituted firm,
 * request or session id cannot widen access.
 *
 * DELIBERATELY NOT SHOWN: nothing about the platform's own internals.
 * Platform role names, platform admin ids, high-risk approval state,
 * security-event internals and support tokens all stay on the platform
 * side. The firm sees what it needs to decide: who, why, what kind of
 * access, for how long, and until when.
 */
class FirmSupportAccessPage extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $navigationLabel = 'Support Access';

    protected static string|\UnitEnum|null $navigationGroup = 'Firm Management';

    protected static ?int $navigationSort = 31;

    protected static ?string $title = 'Support Access';

    protected static ?string $slug = 'support-access';

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(FirmSupportAccessPolicyService::class)->canReview($firmUser->role);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Platform support staff cannot enter your firm\'s data without a request you approve here. '
            .'Approved access is limited to the duration shown, ends automatically, and you can revoke it at any time.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * The pending-decision table. Active and past sessions are reached
     * through the header actions below rather than stacked into one page
     * of three tables — the decision queue is what needs attention, and
     * burying it under history would work against the point of the page.
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->pendingRequests())
            ->columns([
                TextColumn::make('reference')->label('Request')->searchable(),
                TextColumn::make('requested_by_name')->label('Requested by')->placeholder('Platform support'),
                TextColumn::make('access_type_label')
                    ->label('Type')
                    ->badge()
                    ->color(fn (array $record): string => $record['is_emergency'] ? 'danger' : 'gray'),
                TextColumn::make('reason')->label('Reason')->wrap()->limit(120),
                TextColumn::make('requested_duration_minutes')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : $state.' minutes')
                    ->alignEnd(),
                TextColumn::make('requested_at')->label('Requested')->dateTime()->since()->tooltip(
                    fn (array $record): ?string => $record['requested_at']?->toDayDateTimeString()
                ),
                TextColumn::make('decision_deadline')->label('Decide by')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                $this->approveAction(),
                $this->denyAction(),
            ])
            ->paginated(false)
            ->recordAction(null)
            ->recordUrl(null)
            ->emptyStateHeading('No support access requests are awaiting your decision')
            ->emptyStateDescription('Platform support staff must request access and wait for your approval before they can work inside your firm\'s data.')
            ->emptyStateIcon(Heroicon::OutlinedLifebuoy);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->activeSessionsAction(),
            $this->pastAccessAction(),
        ];
    }

    private function approveAction(): Action
    {
        return Action::make('approveSupportAccess')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve this support access request?')
            ->modalDescription(fn (array $record): string => sprintf(
                'This lets %s work inside your firm\'s data for %d minutes. Access starts when they begin the session, '
                .'ends automatically when the time is up, and you can revoke it sooner from Active support sessions. '
                .'Every action they take is recorded. %s',
                $record['requested_by_name'] ?? 'the requesting platform support representative',
                (int) $record['requested_duration_minutes'],
                $record['is_emergency']
                    ? 'This is an emergency request and is additionally governed by platform high-risk change approval.'
                    : 'They will not be signed in as you or any member of your firm — they act as themselves, as platform support.'
            ))
            ->modalSubmitActionLabel('Approve access')
            ->action(function (array $record): void {
                $this->runDecision(
                    fn (FirmSupportAccessService $service, $firmUser) => $service->approve($firmUser, (int) $record['id']),
                    'Support access approved',
                    $record['reference'] ?? null,
                );
            });
    }

    private function denyAction(): Action
    {
        return Action::make('denySupportAccess')
            ->label('Deny')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Deny this support access request?')
            ->modalDescription('Platform support will not be able to start a session from this request. They can raise a new request if the need remains.')
            ->modalSubmitActionLabel('Deny access')
            ->schema([
                Textarea::make('note')
                    ->label('Note for your own records (optional)')
                    ->helperText('Kept on this screen only — it is not sent to the platform support team.')
                    ->maxLength(500),
            ])
            ->action(function (array $record): void {
                $this->runDecision(
                    fn (FirmSupportAccessService $service, $firmUser) => $service->deny($firmUser, (int) $record['id']),
                    'Support access denied',
                    $record['reference'] ?? null,
                );
            });
    }

    private function activeSessionsAction(): Action
    {
        return Action::make('activeSupportSessions')
            ->label('Active support sessions')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('gray')
            ->modalHeading('Support sessions currently active in your firm')
            ->modalDescription('Each session ends automatically at the time shown. Revoking one takes effect immediately.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema(fn (): array => [
                View::make('filament.firm.support-access.active-sessions')
                    ->viewData(['sessions' => $this->activeSessions()->all()]),
            ])
            ->extraModalFooterActions([
                Action::make('revokeSupportSession')
                    ->label('Revoke the oldest active session')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Revoke this support session?')
                    ->modalDescription('Access ends immediately. Any further action by platform support in your firm will be refused straight away, not when the session would have expired.')
                    ->modalSubmitActionLabel('Revoke access now')
                    ->visible(fn (): bool => $this->activeSessions()->isNotEmpty())
                    ->action(function (): void {
                        $oldest = $this->activeSessions()->last();

                        if ($oldest === null) {
                            return;
                        }

                        $this->runDecision(
                            fn (FirmSupportAccessService $service, $firmUser) => $service->revokeSession($firmUser, (int) $oldest['id']),
                            'Support access revoked',
                            $oldest['reference'] ?? null,
                        );
                    }),
            ]);
    }

    private function pastAccessAction(): Action
    {
        return Action::make('pastSupportAccess')
            ->label('Past support access')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->modalHeading('Past support access to your firm')
            ->modalDescription('Your firm\'s record of every support session that is no longer active.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema(fn (): array => [
                View::make('filament.firm.support-access.past-sessions')
                    ->viewData(['sessions' => $this->pastSessions()->all()]),
            ]);
    }

    /**
     * Runs a decision through the service and surfaces its refusal
     * verbatim. A RuntimeException here is a real server-side denial
     * (unauthorized role, another firm's record, an already-decided or
     * expired request) — never swallowed, never converted into a success.
     */
    private function runDecision(callable $operation, string $successTitle, ?string $reference): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            Notification::make()->title('Your firm session could not be resolved.')->danger()->send();

            return;
        }

        try {
            $operation(app(FirmSupportAccessService::class), $firmUser);
        } catch (RuntimeException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title($successTitle)
            ->body($reference === null ? null : 'Reference '.$reference)
            ->success()
            ->send();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function pendingRequests(): Collection
    {
        return $this->guardedRead(fn (FirmSupportAccessService $service, $firmUser): Collection => $service->pendingRequests($firmUser));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function activeSessions(): Collection
    {
        return $this->guardedRead(fn (FirmSupportAccessService $service, $firmUser): Collection => $service->activeSessions($firmUser));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function pastSessions(): Collection
    {
        return $this->guardedRead(fn (FirmSupportAccessService $service, $firmUser): Collection => $service->pastSessions($firmUser));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function guardedRead(callable $read): Collection
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return collect();
        }

        try {
            return $read(app(FirmSupportAccessService::class), $firmUser);
        } catch (RuntimeException) {
            return collect();
        }
    }
}
