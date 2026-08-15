<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanResource\Pages;

use App\Enums\BillingInterval;
use App\Enums\PlanStatus;
use App\Enums\PlatformSubscriptionStatus;
use App\Filament\Actions\Platform\ActivatePlanAction;
use App\Filament\Actions\Platform\ArchivePlanAction;
use App\Filament\Resources\PlanAddOnResource;
use App\Filament\Resources\PlanResource;
use App\Filament\Resources\PlatformSubscriptionResource;
use App\Models\Plan;
use App\Services\PlatformBillingCommercialOverviewService;
use App\Support\MoneyDisplay;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * ViewPlan — the standard Filament ViewRecord page (plans carries no
 * RLS, ordinary {record} route-model-binding by uuid). Activate/Archive
 * live here as header actions, mirroring ViewPlatformAdministrator's
 * "mutations live on the View page" convention.
 *
 * BILLING & COMMERCIAL CONTROL PLANE PASS — what this page gained.
 * Previously it showed a plan's own columns and nothing else, which
 * left the two questions an operator actually has unanswered: what does
 * this plan grant, and who is on it? Both are now shown:
 *
 *   - COMPOSITION: the plan's bundled modules, its optional add-ons,
 *     and its limits, read from plan_modules / plan_limits.
 *   - REACH: how many platform subscriptions and firm licenses
 *     reference this plan, and how many of those subscriptions are
 *     live. These are the numbers that make "can I safely change this
 *     plan?" answerable, and they are the same numbers PlanService uses
 *     to decide whether the financial-terms lock applies.
 *   - VERSIONING TRUTH: an explicit statement that plans have no
 *     versions, no effective dates, and no grandfathering, and what is
 *     done instead.
 *
 * Every count is a withCount aggregate on a single-record query — no
 * collection of subscriptions or licenses is ever loaded into PHP.
 *
 * This page shows COMMERCIAL SOURCE CONFIGURATION — what the plan
 * intends to grant. It deliberately does NOT resolve or display any
 * firm's effective entitlements: that resolution has its own precedence
 * rules (plan, add-on, override, policy) and its own surface, and
 * duplicating a partial version of it here would produce a second,
 * quietly-disagreeing answer to the same question.
 */
class ViewPlan extends ViewRecord
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActivatePlanAction::make(),
            ArchivePlanAction::make(),
        ];
    }

    /**
     * Reach counts for this one plan, as SQL aggregates.
     */
    private function reach(): Plan
    {
        /** @var Plan $record */
        $record = $this->getRecord();

        /** @var Plan $withCounts */
        $withCounts = Plan::query()
            ->whereKey($record->getKey())
            ->withCount([
                'platformSubscriptions as subscriptions_count',
                'platformSubscriptions as live_subscriptions_count' => fn (Builder $q) => $q
                    ->whereIn('status', [
                        PlatformSubscriptionStatus::Active->value,
                        PlatformSubscriptionStatus::Trialing->value,
                        PlatformSubscriptionStatus::PastDue->value,
                    ]),
                'firmLicenses as firm_licenses_count',
                'orgLicenses as org_licenses_count',
                'modules as bundled_modules_count' => fn (Builder $q) => $q->where('is_addon', false),
                'modules as addon_modules_count' => fn (Builder $q) => $q->where('is_addon', true),
            ])
            ->firstOrFail();

        return $withCounts;
    }

    public function infolist(Schema $schema): Schema
    {
        /** @var Plan $record */
        $record = $this->getRecord();
        $reach = $this->reach();
        $currency = PlatformBillingCommercialOverviewService::CURRENCY;

        return $schema->components([
            Section::make('Plan')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('code')->label('Plan code')->placeholder('—')->fontFamily('mono'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (PlanStatus $state): string => Str::headline($state->value))
                        ->color(fn (PlanStatus $state): string => match ($state) {
                            PlanStatus::Active => 'success',
                            PlanStatus::Draft => 'gray',
                            PlanStatus::Archived => 'danger',
                        }),
                    TextEntry::make('price_cents')
                        ->label('Price')
                        ->formatStateUsing(fn (?int $state): string => MoneyDisplay::fromCents($state, $currency)),
                    TextEntry::make('billing_interval')
                        ->label('Billing interval')
                        ->formatStateUsing(fn (BillingInterval $state): string => Str::headline($state->value)),
                    TextEntry::make('support_access_level')
                        ->label('Support access level')
                        ->placeholder('—')
                        ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Str::headline($state)),
                    TextEntry::make('trial_days')->label('Trial days')->placeholder('No trial'),
                    IconEntry::make('trial_requires_card')->label('Trial requires card')->boolean(),
                    IconEntry::make('is_active')->label('Active')->boolean(),
                    TextEntry::make('created_at')->label('Created')->dateTime(),
                    TextEntry::make('description')
                        ->label('Internal description')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Section::make('Who is on this plan')
                ->icon(Heroicon::OutlinedUsers)
                ->schema([
                    Text::make(new HtmlString(
                        'Platform subscriptions: <strong>'.e((string) $reach->subscriptions_count).'</strong> '.
                        '(<strong>'.e((string) $reach->live_subscriptions_count).'</strong> live)'
                    )),
                    Text::make(new HtmlString(
                        'Firm licences: <strong>'.e((string) $reach->firm_licenses_count).'</strong> &nbsp;·&nbsp; '.
                        'Organization licences: <strong>'.e((string) $reach->org_licenses_count).'</strong>'
                    )),
                    Text::make($this->lockStatement($reach)),
                    Text::make(new HtmlString(
                        '<a href="'.e(PlatformSubscriptionResource::getUrl()).'" class="fi-link">'.
                        'View subscriptions &rarr;</a>'
                    )),
                ]),

            Section::make('What this plan includes')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->schema([
                    ...$this->moduleList($record, isAddon: false),
                    ...$this->moduleList($record, isAddon: true),
                    ...$this->limitList($record),
                    Text::make(new HtmlString(
                        '<a href="'.e(PlanAddOnResource::getUrl()).'" class="fi-link">Manage add-ons &rarr;</a>'
                    )),
                    Text::make(
                        'This is the plan\'s commercial source configuration — what it is set up to grant. What a '.
                        'specific firm ultimately has access to is resolved separately, taking overrides and '.
                        'policy into account, and is not shown here: a second, partial answer to that question on '.
                        'this page would eventually disagree with the real one.'
                    ),
                ]),

            Section::make('Changing this plan')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->schema([
                    Text::make(
                        'Plans have no versions and no effective dates. A subscription references a plan and '.
                        'stores no price of its own, so changing a plan\'s price would retroactively change what '.
                        'every existing subscriber is understood to be paying — including on invoices already '.
                        'issued against it.'
                    ),
                    Text::make(
                        'That is prevented rather than versioned. Once any platform subscription or firm licence '.
                        'references a plan, its price, billing interval, and plan code become locked and cannot be '.
                        'edited. Name, description, support access level, and trial settings stay editable at any '.
                        'time — this is a narrow financial-terms lock, not a general freeze.'
                    ),
                    Text::make(
                        'There is no grandfathering, no scheduled or effective-dated change, and no proration '.
                        'mechanism to fall back on. To offer different commercial terms, create a new plan and '.
                        'move subscribers to it deliberately. Archiving this plan stops it being offered; it does '.
                        'not change anyone already on it.'
                    ),
                    Text::make(
                        'Plans are never deleted from this console. A plan referenced by a subscription, licence, '.
                        'or historical invoice is financial evidence of what was sold.'
                    ),
                ]),

            Section::make('Not configurable on a plan')
                ->icon(Heroicon::OutlinedInformationCircle)
                ->collapsible()
                ->collapsed()
                ->schema([
                    Text::make(
                        'A plan has no tax rate, tax behaviour, jurisdiction, or tax-inclusive/exclusive setting, '.
                        'and nothing in this platform calculates tax. It has no setup fee, no discount, coupon, or '.
                        'promotional pricing, and no currency of its own — every amount in this domain is '.
                        $currency.'. Usage metrics carry no per-plan rate or price either: usage is recorded as '.
                        'quantity only, with no pricing attached anywhere.'
                    ),
                    Text::make(
                        'These are absent capabilities, not empty settings. No zero-tax or zero-discount line is '.
                        'shown that would imply either was evaluated.'
                    ),
                ]),
        ]);
    }

    private function lockStatement(Plan $reach): string
    {
        $inUse = ((int) $reach->subscriptions_count) > 0 || ((int) $reach->firm_licenses_count) > 0;

        return $inUse
            ? 'This plan is in use, so its price, billing interval, and plan code are locked and cannot be '.
                'edited. Editing them would retroactively change the commercial terms of everyone above.'
            : 'Nothing references this plan yet, so its price, billing interval, and plan code are still '.
                'editable. They lock permanently as soon as the first subscription or licence is created '.
                'against it.';
    }

    /**
     * The heading is always emitted, list or no list — an operator has
     * to be able to tell "this plan bundles nothing" apart from "the
     * bundled-modules section failed to render".
     *
     * @return array<int, Text|UnorderedList>
     */
    private function moduleList(Plan $record, bool $isAddon): array
    {
        $modules = $record->modules()
            ->where('is_addon', $isAddon)
            ->with('module')
            ->orderBy('module_code')
            ->get();

        $heading = $isAddon
            ? 'Optional add-ons'
            : 'Bundled modules (included in the base price)';

        if ($modules->isEmpty()) {
            return [Text::make($heading.': none configured.')];
        }

        return [
            Text::make(new HtmlString('<strong>'.e($heading).'</strong>')),
            UnorderedList::make(
                $modules->map(fn ($planModule): Text => Text::make(new HtmlString(
                    '<strong>'.e($planModule->module?->module_name ?? $planModule->module_code).'</strong> — '.
                    e($planModule->enabled ? 'Enabled' : 'Disabled').', '.
                    e(Str::headline($planModule->status->value)).
                    ' <span class="fi-color-gray">('.e($planModule->module_code).')</span>'
                )))->all()
            ),
        ];
    }

    /**
     * @return array<int, Text|UnorderedList>
     */
    private function limitList(Plan $record): array
    {
        $limits = $record->limits()->orderBy('metric')->get();

        if ($limits->isEmpty()) {
            return [Text::make('Limits: none configured — this plan sets no quota on any metric.')];
        }

        return [
            Text::make(new HtmlString('<strong>Limits</strong>')),
            UnorderedList::make(
                $limits->map(fn ($limit): Text => Text::make(new HtmlString(
                    '<strong>'.e(Str::headline($limit->metric->value)).'</strong>: '.
                    e($limit->limit_value === null ? 'Unlimited' : number_format((int) $limit->limit_value))
                )))->all()
            ),
        ];
    }
}
