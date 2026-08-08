<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Models\SecurityEvent;
use App\Services\FirmSecurityActivityAccessPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * FirmSecurityActivityPage — Firm Feature Manifest §10/§11: closes the
 * "Firm-facing login history / security dashboard — Not built" and
 * "Support-access transparency — Not built" gaps. A read-only Filament
 * Page (deliberately NOT a Resource, matching `ExpenseReportPage`'s own
 * "no underlying model to CRUD, only an aggregate read view" precedent
 * — `SecurityEvent` is append-only by design, see its own `booted()`
 * guard) reading `SecurityEvent` scoped to the CURRENT firm only.
 *
 * RLS VERIFIED, NOT ASSUMED: `security_events` carries FORCE ROW LEVEL
 * SECURITY (`2026_08_25_930034_force_rls_on_security_events_table`).
 * Its SELECT policy is `firm_id = current_setting('app.current_firm_id')
 * OR (firm_id IS NULL AND current_setting(...) IS NULL)` — read directly
 * from that migration before writing this page. Inside a real
 * `runWithFirmContext($firm)` call (which sets `app.current_firm_id` to
 * this firm's own id), a session can therefore see ONLY this firm's own
 * `firm_id` rows — the null-firm_id branch is structurally unreachable
 * once a firm context is active, and no OTHER firm's rows are ever
 * visible regardless of query shape. This matches
 * `PlatformSecurityDashboardService`'s own documented understanding of
 * this exact policy (see that class's docblock) — reused here as
 * confirmation, not re-derived independently.
 *
 * REDACTION DISCIPLINE — reuses `PlatformSecurityDashboardService`'s own
 * rule verbatim: the raw `metadata` JSON column is NEVER selected here,
 * for any event, under any category. `firmSecurityEvents()` below's own
 * `SecurityEvent::query()->get([...])` column list deliberately omits
 * it, mirroring that service's own `->get(['id', 'firm_id', 'actor_type',
 * 'actor_id', 'event_type', 'category', 'created_at'])` call exactly (this
 * page additionally omits `ip_address`/`user_agent`, which that service
 * also never selects — same discipline, applied consistently).
 *
 * PER-CATEGORY HANDLING (documented product decision, defaults to the
 * MORE conservative reading wherever genuinely unsure, per this task's
 * own instruction):
 *
 *   - `authentication` (`login_succeeded`/`login_failed`) — shown
 *     PLAINLY: event label, actor (`ClassBasename #id`, never a raw
 *     email/name lookup — matches `PlatformSecurityDashboardService`'s
 *     own `class_basename($event->actor_type)` convention), and
 *     timestamp. Manifest §10/§11 explicitly names this "the safest
 *     subset to ship first" — the only category shown with any actor
 *     detail at all.
 *
 *   - `support_access` — HEAVILY SUMMARIZED, no actor identity, no
 *     metadata, no reason text: a single fixed sentence ("Platform
 *     support accessed this firm's data") plus the timestamp only.
 *     Real transparency value (a Firm Owner learns THAT and WHEN
 *     support touched their data) without disclosing which specific
 *     platform-admin staff member or WHY — the manifest itself flags
 *     "leaking operationally-sensitive support-access reasoning to a
 *     firm user is a real risk," so the reason/actor are never surfaced
 *     here even in summarized form.
 *
 *   - `high_risk_change` — same treatment as `support_access`, same
 *     reasoning: a fixed sentence ("A high-risk platform-level change
 *     affecting this firm was made") plus timestamp only, no actor, no
 *     metadata.
 *
 *   - EVERY OTHER category (`webhook_replay`,
 *     `platform_integration_oversight`, `platform_admin_mfa`, or any
 *     future category not one of the three above) — EXCLUDED ENTIRELY
 *     from this view. Conservative-by-default: none of these were
 *     named in the manifest's own "safest subset" guidance, and this
 *     page would rather under-disclose than guess at a redaction shape
 *     for a category it wasn't asked to handle. `presentEvent()` below
 *     returns `null` for any unrecognized category, and the caller
 *     filters those out before rendering — a new category added to
 *     `security_events` in the future is invisible here by default
 *     until a future change deliberately adds a `match` arm for it,
 *     never silently exposed.
 *
 * Capped at the most recent 100 events (mirrors
 * `PlatformSecurityDashboardService::recentSecurityEvents()`'s own
 * `$limit` pattern, narrower default since this is a single firm's
 * stream rather than a cross-firm merge) — `->paginated(false)`, same
 * choice as `ExpenseReportPage` for an already-fully-materialized,
 * already-sorted Collection.
 *
 * AUTHORIZATION — `FirmSecurityActivityAccessPolicyService::canView()`,
 * FirmOwner only (see that service's own docblock for the full
 * reasoning).
 */
class FirmSecurityActivityPage extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Security Activity';

    protected static string|\UnitEnum|null $navigationGroup = 'Firm Management';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Security Activity';

    protected static ?string $slug = 'security-activity';

    private const EVENT_LIMIT = 100;

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(FirmSecurityActivityAccessPolicyService::class)->canView($firmUser->role);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->firmSecurityEvents())
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime(),
                TextColumn::make('category_label')->label('Category')->badge(),
                TextColumn::make('event_label')->label('Event'),
                TextColumn::make('actor_label')->label('Actor')->placeholder('—'),
            ])
            ->paginated(false)
            ->emptyStateHeading('No security events recorded yet')
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck);
    }

    /**
     * @return Collection<int, array{id: int, category_label: string, event_label: string, actor_label: ?string, created_at: Carbon}>
     */
    private function firmSecurityEvents(): Collection
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! app(FirmSecurityActivityAccessPolicyService::class)->canView($firmUser->role)) {
            return collect();
        }

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            fn (): Collection => SecurityEvent::query()
                ->where('firm_id', $firmUser->firm_id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::EVENT_LIMIT)
                ->get(['id', 'actor_type', 'actor_id', 'event_type', 'category', 'created_at'])
                ->map(fn (SecurityEvent $event): ?array => $this->presentEvent($event))
                ->filter()
                ->values(),
        );
    }

    /**
     * @return array{id: int, category_label: string, event_label: string, actor_label: ?string, created_at: Carbon}|null
     *                                                                                                                    null means: this category is deliberately not shown
     *                                                                                                                    on this page (see this class's own docblock).
     */
    private function presentEvent(SecurityEvent $event): ?array
    {
        return match ($event->category) {
            'authentication' => [
                'id' => $event->id,
                'category_label' => 'Authentication',
                'event_label' => match ($event->event_type) {
                    'login_succeeded' => 'Login succeeded',
                    'login_failed' => 'Login failed',
                    default => str($event->event_type)->headline()->toString(),
                },
                'actor_label' => $event->actor_type !== null
                    ? class_basename($event->actor_type).($event->actor_id !== null ? ' #'.$event->actor_id : '')
                    : null,
                'created_at' => $event->created_at,
            ],
            'support_access' => [
                'id' => $event->id,
                'category_label' => 'Support Access',
                'event_label' => "Platform support accessed this firm's data",
                'actor_label' => null,
                'created_at' => $event->created_at,
            ],
            'high_risk_change' => [
                'id' => $event->id,
                'category_label' => 'Platform Change',
                'event_label' => 'A high-risk platform-level change affecting this firm was made',
                'actor_label' => null,
                'created_at' => $event->created_at,
            ],
            default => null,
        };
    }
}
