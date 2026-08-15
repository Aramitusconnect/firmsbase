<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\FailedPaymentResource;
use App\Filament\Resources\PlanAddOnResource;
use App\Filament\Resources\PlanResource;
use App\Filament\Resources\PlatformInvoiceResource;
use App\Filament\Resources\PlatformRefundResource;
use App\Filament\Resources\PlatformSubscriptionResource;
use App\Filament\Resources\TrialRequestResource;
use App\Models\PlatformAdmin;
use App\Services\PlatformBillingCommercialOverviewService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Support\MoneyDisplay;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * PlatformBillingCommercialOverviewPage — Billing & Commercial Control
 * Plane pass. The landing page for the Billing & Commercial group: what
 * the commercial state of the platform actually is, what genuinely
 * needs an operator's attention, and — just as importantly — which
 * commercial capabilities this platform does not have.
 *
 * No equivalent page existed before this pass; every other Billing &
 * Commercial nav item was a per-entity list with no place to see the
 * whole. This is that place. It does not duplicate those lists: every
 * figure here links through to the resource that owns the underlying
 * records.
 *
 * ALL NUMBERS COME FROM PlatformBillingCommercialOverviewService, which
 * computes them as bounded SQL aggregates. This page performs NO query
 * of its own and iterates NO collection of commercial records — see
 * that service's docblock for the per-metric derivation rules, the
 * integer-cents money handling, and the explicit list of metrics that
 * are NOT computed because this schema cannot support them.
 *
 * WHY SOME EXPECTED METRICS ARE ABSENT RATHER THAN ZERO
 * -----------------------------------------------------
 * A zero asserts "we measured this and it was none." Several standard
 * SaaS commercial metrics cannot be measured at all here, and printing
 * 0 for them would be a false statement about the platform, not a
 * conservative one. Those appear in the "Capability boundaries" section
 * below as prose, never as a stat: payment recovery rate, credits,
 * priced/unbilled usage, scheduled plan changes, churn/ARPU/LTV/revenue
 * growth, and anything reseller-related.
 *
 * The Requires Attention queue holds only conditions an operator can
 * act on, and shows entries only when their count is non-zero. Standing
 * product gaps (no gateway, no Credit domain, no reseller domain) are
 * deliberately NOT queued as alerts — they would never clear, and a
 * queue that never clears stops being read.
 *
 * Scalar-property-only: this class declares no public properties, and
 * re-resolves both the acting admin and the read service on every
 * render rather than caching anything on `$this`.
 *
 * Every commercial table this page aggregates is platform-global —
 * re-verified at this pass's HEAD against the repository's own RLS
 * coverage registry and its exempt-table list rather than assumed from
 * an earlier report, so no firm/tenant context is set up here and none
 * is needed.
 *
 * FINAL ADMIN RECONCILIATION note: the registry is described rather
 * than named, for the same reason as PlatformInternalSalesCommissions-
 * Page — Section 26's firewall matches raw file contents under
 * app/Filament, and this page neither imports nor calls that service. Access is gated purely by
 * PlatformStaffAccessPolicyService::canAccessPlatformBilling().
 */
class PlatformBillingCommercialOverviewPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $navigationLabel = 'Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing & Commercial';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Billing & Commercial Overview';

    protected static ?string $slug = 'billing-commercial-overview';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessPlatformBilling($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'FirmsVault\'s own SaaS billing — what the platform charges its customer firms. This is not a law '.
            'firm\'s client billing, matter billing, or trust accounting, which live entirely elsewhere.';
    }

    public function content(Schema $schema): Schema
    {
        $asOf = CarbonImmutable::now();
        $overview = app(PlatformBillingCommercialOverviewService::class);

        $revenue = $overview->revenue();
        $trials = $overview->trials($asOf);
        $invoices = $overview->invoices($asOf);
        $payments = $overview->payments();
        $usage = $overview->usage();
        $catalog = $overview->catalog();
        $attention = $overview->requiresAttention($asOf);

        return $schema->components([
            $this->attentionSection($attention),
            $this->revenueSection($revenue),
            $this->trialsSection($trials),
            $this->invoicesSection($invoices),
            $this->paymentsSection($payments),
            $this->usageSection($usage),
            $this->catalogSection($catalog),
            $this->capabilityBoundariesSection(),
            $this->freshnessSection($asOf, $usage),
        ]);
    }

    /**
     * @param  array<int, array{key:string, label:string, count:int, detail:string}>  $attention
     */
    private function attentionSection(array $attention): Section
    {
        if ($attention === []) {
            return Section::make('Requires attention')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->schema([
                    Text::make(
                        'Nothing currently requires commercial attention: no overdue invoices, no billing accounts '.
                        'with a failed payment attempt, no trials expiring or awaiting provisioning, and no plan '.
                        'or subscription configuration problems.'
                    ),
                    Text::make(
                        'Missing platform capabilities are not listed here — they are standing product gaps with '.
                        'no operator action that would clear them, and are stated under Capability boundaries below.'
                    ),
                ]);
        }

        return Section::make('Requires attention')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->description('Commercial conditions an operator can act on right now.')
            ->schema([
                UnorderedList::make(array_map(
                    fn (array $item): Text => Text::make(new HtmlString(
                        e($item['label']).': <strong>'.e((string) $item['count']).'</strong> — '.e($item['detail'])
                    )),
                    $attention,
                )),
            ]);
    }

    /**
     * @param  array{active:int, trialing:int, past_due:int, cancelled:int, expired:int, cancelling:int, arr_cents:int, mrr_cents:int}  $revenue
     */
    private function revenueSection(array $revenue): Section
    {
        return Section::make('Recurring revenue and subscriptions')
            ->icon(Heroicon::OutlinedBanknotes)
            ->description('List-price figures from active subscriptions. Tax and discounts are excluded because '.
                'plans carry neither.')
            ->schema([
                Grid::make(4)->schema([
                    $this->stat('MRR', MoneyDisplay::fromCents($revenue['mrr_cents'], PlatformBillingCommercialOverviewService::CURRENCY)),
                    $this->stat('ARR', MoneyDisplay::fromCents($revenue['arr_cents'], PlatformBillingCommercialOverviewService::CURRENCY)),
                    $this->stat('Active subscriptions', (string) $revenue['active']),
                    $this->stat('Cancelling at period end', (string) $revenue['cancelling']),
                ]),
                Grid::make(4)->schema([
                    $this->stat('Trialing', (string) $revenue['trialing']),
                    $this->stat('Past due', (string) $revenue['past_due']),
                    $this->stat('Cancelled', (string) $revenue['cancelled']),
                    $this->stat('Expired', (string) $revenue['expired']),
                ]),
                Text::make(
                    'How these are derived: only subscriptions in Active status contribute to MRR and ARR — '.
                    'Trialing is not yet paying and Past Due is not currently collecting, so both are counted '.
                    'separately rather than folded in. A subscription set to cancel at period end still counts, '.
                    'because it is still paying for the current period. Each subscription\'s amount is its plan '.
                    'price plus every subscription line item; annual subscriptions are annualized exactly in '.
                    'integer cents, and MRR is that annual total divided by twelve, so ARR is the exact figure '.
                    'and MRR the derived one.'
                ),
                Text::make(
                    'Churn, ARPU, LTV, and revenue growth are not shown. This schema keeps no historical revenue '.
                    'series and no per-subscription price snapshot, so no past period\'s revenue can be '.
                    'reconstructed — any such figure would be a guess presented as a measurement.'
                ),
                $this->link('Open subscriptions', PlatformSubscriptionResource::getUrl()),
            ]);
    }

    /**
     * @param  array{active:int, expiring_soon:int, awaiting_provisioning:int, provisioned:int, converted:int, expired:int, cancelled:int, terminal:int, conversion_rate:float|null}  $trials
     */
    private function trialsSection(array $trials): Section
    {
        return Section::make('Trials')
            ->icon(Heroicon::OutlinedBeaker)
            ->schema([
                Grid::make(4)->schema([
                    $this->stat('Active trials', (string) $trials['active']),
                    $this->stat(
                        'Expiring within '.PlatformBillingCommercialOverviewService::TRIAL_EXPIRY_HORIZON_DAYS.' days',
                        (string) $trials['expiring_soon'],
                    ),
                    $this->stat('Awaiting provisioning', (string) $trials['awaiting_provisioning']),
                    $this->stat('Provisioned, not yet active', (string) $trials['provisioned']),
                ]),
                Grid::make(4)->schema([
                    $this->stat('Converted', (string) $trials['converted']),
                    $this->stat('Expired', (string) $trials['expired']),
                    $this->stat('Cancelled', (string) $trials['cancelled']),
                    $this->stat(
                        'Conversion rate',
                        $trials['conversion_rate'] === null
                            ? 'Not available'
                            : $trials['conversion_rate'].'%',
                    ),
                ]),
                Text::make(
                    $trials['conversion_rate'] === null
                        ? 'Conversion rate is not available: no trial has reached a terminal outcome yet, so there '.
                            'is no denominator to divide by. This is reported as unavailable rather than as 0%.'
                        : 'Conversion rate is all-time, not period-bounded: converted trials as a share of all '.
                            'trials that reached a terminal outcome (converted, expired, or cancelled — '.
                            $trials['terminal'].' in total). It cannot be period-bounded, because this domain '.
                            'records a converted-at timestamp but stores no timestamp for expiry or cancellation, '.
                            'so a period-scoped denominator would be incompatible with its numerator.'
                ),
                Text::make(
                    'What happens when a trial expires: the trial record\'s status becomes Expired and an audit '.
                    'event is written. That is all. Nothing in this codebase disables access, imposes a read-only '.
                    'mode, starts a grace period, or creates a subscription when a trial expires, and no scheduled '.
                    'job expires trials automatically — an operator marks a trial expired. Product access after '.
                    'expiry is governed by entitlements, which trial expiry does not touch.'
                ),
                $this->link('Open trials', TrialRequestResource::getUrl()),
            ]);
    }

    /**
     * @param  array{draft:int, open:int, past_due_status:int, paid:int, void:int, overdue:int, outstanding_cents:int}  $invoices
     */
    private function invoicesSection(array $invoices): Section
    {
        return Section::make('Invoices')
            ->icon(Heroicon::OutlinedDocumentText)
            ->schema([
                Grid::make(4)->schema([
                    $this->stat(
                        'Amount outstanding',
                        MoneyDisplay::fromCents($invoices['outstanding_cents'], PlatformBillingCommercialOverviewService::CURRENCY),
                    ),
                    $this->stat('Open', (string) $invoices['open']),
                    $this->stat('Past due (status)', (string) $invoices['past_due_status']),
                    $this->stat('Overdue (by due date)', (string) $invoices['overdue']),
                ]),
                Grid::make(4)->schema([
                    $this->stat('Draft', (string) $invoices['draft']),
                    $this->stat('Paid', (string) $invoices['paid']),
                    $this->stat('Void', (string) $invoices['void']),
                    $this->stat('Currency', PlatformBillingCommercialOverviewService::CURRENCY),
                ]),
                Text::make(
                    'Amount outstanding is the total of every issued, unpaid, non-void invoice. There is no '.
                    'partial-payment netting to do: this domain charges an invoice for its full total and marks it '.
                    'paid on success, so an unpaid invoice\'s outstanding amount is its total. "Past due (status)" '.
                    'is the stored invoice status; "Overdue (by due date)" is the due-date fact, counted '.
                    'independently so the two can never be silently conflated.'
                ),
                Text::make(
                    'Every amount in this domain is '.PlatformBillingCommercialOverviewService::CURRENCY.'. No '.
                    'commercial table in this schema has a currency column, so there is no second currency and no '.
                    'mixed-currency total anywhere on this page.'
                ),
                $this->link('Open invoices', PlatformInvoiceResource::getUrl()),
            ]);
    }

    /**
     * @param  array{succeeded:int, failed:int, refunded:int, partially_refunded:int, failed_attempts:int, accounts_with_failures:int, refunds_pending:int, refunds_completed:int, refunds_failed:int, refunded_cents:int}  $payments
     */
    private function paymentsSection(array $payments): Section
    {
        return Section::make('Payments and refunds')
            ->icon(Heroicon::OutlinedCreditCard)
            ->schema([
                Grid::make(4)->schema([
                    $this->stat('Successful payments', (string) $payments['succeeded']),
                    $this->stat('Failed payment attempts', (string) $payments['failed_attempts']),
                    $this->stat('Accounts with a failed attempt', (string) $payments['accounts_with_failures']),
                    $this->stat('Failed payments', (string) $payments['failed']),
                ]),
                Grid::make(4)->schema([
                    $this->stat('Refunds completed', (string) $payments['refunds_completed']),
                    $this->stat('Refunds pending', (string) $payments['refunds_pending']),
                    $this->stat('Refunds failed', (string) $payments['refunds_failed']),
                    $this->stat(
                        'Total refunded',
                        MoneyDisplay::fromCents($payments['refunded_cents'], PlatformBillingCommercialOverviewService::CURRENCY),
                    ),
                ]),
                Text::make(
                    'No recovery rate, recovered amount, or amount-at-risk is shown. Payment recovery is not '.
                    'operational on this platform: no production payment gateway is configured, nothing retries a '.
                    'failed platform payment, and this domain stores no retry, dunning, notification, or '.
                    'resolution state to measure. "Accounts with a failed attempt" is a record of what failed, not '.
                    'a work queue.'
                ),
                Text::make(
                    'Refund records here are evidence of refunds that were attempted through the gateway. Refunds '.
                    'cannot be issued or processed from this console for the same reason.'
                ),
                Grid::make(2)->schema([
                    $this->link('Open failed payments', FailedPaymentResource::getUrl()),
                    $this->link('Open refunds', PlatformRefundResource::getUrl()),
                ]),
            ]);
    }

    /**
     * @param  array{records:int, account_level:int, firm_attributed:int, billing_accounts:int, metrics:int, data_through:?string}  $usage
     */
    private function usageSection(array $usage): Section
    {
        return Section::make('Platform usage')
            ->icon(Heroicon::OutlinedChartBarSquare)
            ->schema([
                Grid::make(4)->schema([
                    $this->stat('Usage records', (string) $usage['records']),
                    $this->stat('Firm-attributed', (string) $usage['firm_attributed']),
                    $this->stat('Account-level aggregates', (string) $usage['account_level']),
                    $this->stat('Metrics in use', (string) $usage['metrics']),
                ]),
                Text::make(
                    'Usage here is recorded quantity only. This domain stores no unit price, rate, charge, invoice '.
                    'link, or finalized flag against a usage record, so there is no priced, unpriced, billable, or '.
                    'unbilled usage figure to show, and none is invented. Usage records are immutable — they are '.
                    'created and never edited or deleted, and no adjustment or correction ledger exists.'
                ),
                Text::make(
                    'A record with no firm is the billing-account-level aggregate for that metric and period, not '.
                    'an unattributed orphan — it is labelled "account-level", never "unallocated", because calling '.
                    'it unallocated would describe a data problem that is not happening.'
                ),
                Text::make(
                    'This is platform billable usage, keyed to a billing account. It is a different thing from '.
                    'integration provider telemetry and from what FirmsVault itself pays an upstream provider; '.
                    'those are operational cost figures and live under Integrations, not here.'
                ),
                $this->link('Open usage charges', PlatformUsageChargesPage::getUrl()),
            ]);
    }

    /**
     * @param  array{plans_active:int, plans_draft:int, plans_archived:int, addons_enabled:int, addons_total:int, modules_bundled:int}  $catalog
     */
    private function catalogSection(array $catalog): Section
    {
        return Section::make('Catalog')
            ->icon(Heroicon::OutlinedTag)
            ->schema([
                Grid::make(4)->schema([
                    $this->stat('Active plans', (string) $catalog['plans_active']),
                    $this->stat('Draft plans', (string) $catalog['plans_draft']),
                    $this->stat('Archived plans', (string) $catalog['plans_archived']),
                    $this->stat('Enabled add-ons', (string) $catalog['addons_enabled']),
                ]),
                Text::make(
                    'Add-ons are plan modules flagged as add-ons — this domain has no separate add-on entity. '.
                    '"Enabled add-ons" counts active, enabled add-on module rows across all plans ('.
                    $catalog['addons_total'].' add-on rows in total, alongside '.$catalog['modules_bundled'].
                    ' modules bundled into plan base prices).'
                ),
                Text::make(
                    'Scheduled plan changes are not shown, because none can exist: no subscription can have a plan '.
                    'change scheduled against it in this domain, and no service supports scheduling one.'
                ),
                Grid::make(2)->schema([
                    $this->link('Open plans', PlanResource::getUrl()),
                    $this->link('Open add-ons', PlanAddOnResource::getUrl()),
                ]),
            ]);
    }

    private function capabilityBoundariesSection(): Section
    {
        return Section::make('Capability boundaries')
            ->icon(Heroicon::OutlinedInformationCircle)
            ->description('What this platform cannot currently do commercially. Stated once, here, rather than '.
                'implied by an empty metric.')
            ->collapsible()
            ->schema([
                Callout::make('No production payment gateway')
                    ->warning()
                    ->footer([
                        Text::make(
                            'No production-capable payment gateway is configured. In staging and production the '.
                            'gateway resolves to an implementation that refuses every call rather than fabricating '.
                            'a success, so platform payments cannot be collected, retried, or refunded from this '.
                            'console. Payment and refund records remain readable as evidence; nothing acts on them.'
                        ),
                    ]),
                Callout::make('No credit domain')
                    ->warning()
                    ->footer([
                        Text::make(
                            'There is no Credit model, credits table, or credit service anywhere in this codebase. '.
                            'Credits cannot be issued, applied, or tracked, and no credit balance appears on any '.
                            'invoice or account. A credit is not a refund: a credit reduces what a customer owes, '.
                            'a refund returns money already collected. Only refunds exist here.'
                        ),
                    ]),
                Callout::make('No usage adjustment ledger')
                    ->warning()
                    ->footer([
                        Text::make(
                            'Recorded usage is immutable and there is no adjustment, correction, or reversal '.
                            'mechanism. A mis-recorded quantity cannot be corrected through this console, and it '.
                            'is deliberately not editable in place — editing the original record would destroy the '.
                            'evidence of what was actually observed.'
                        ),
                    ]),
                Callout::make('No plan versioning')
                    ->warning()
                    ->footer([
                        Text::make(
                            'Plans have no versions and no effective dates. A subscription references a plan and '.
                            'stores no price of its own, so changing a plan\'s price would change what existing '.
                            'subscribers are understood to be paying. That is prevented rather than versioned: a '.
                            'plan\'s price, billing interval, and code become locked once any subscription or firm '.
                            'license references it. Descriptive fields stay editable. There is no grandfathering '.
                            'or proration mechanism to fall back on.'
                        ),
                    ]),
                Callout::make('No reseller or partner domain')
                    ->warning()
                    ->footer([
                        Text::make(
                            'Reseller and partner account management is not implemented. Internal sales '.
                            'commissions for FirmsVault employees are tracked and are a different thing — see '.
                            'Reseller Readiness and Internal Sales Commissions in this navigation group.'
                        ),
                    ]),
                Callout::make('No tax or discount capability')
                    ->warning()
                    ->footer([
                        Text::make(
                            'Invoices carry a tax total field, but nothing in this codebase calculates tax: there '.
                            'is no tax rate, tax behavior, jurisdiction, or tax-inclusive/exclusive setting on any '.
                            'plan, subscription, or account. There is no discount, coupon, or promotion concept at '.
                            'any level either. Figures on this page are list-price, tax-exclusive amounts, and no '.
                            'zero-discount line is shown that would imply discounting was evaluated.'
                        ),
                    ]),
            ]);
    }

    /**
     * @param  array{records:int, account_level:int, firm_attributed:int, billing_accounts:int, metrics:int, data_through:?string}  $usage
     */
    private function freshnessSection(CarbonImmutable $asOf, array $usage): Section
    {
        return Section::make('Data freshness')
            ->icon(Heroicon::OutlinedClock)
            ->collapsible()
            ->collapsed()
            ->schema([
                Text::make('Computed at: '.$asOf->toDayDateTimeString().' ('.$asOf->timezoneName.')'),
                Text::make(
                    'Every figure on this page is computed live from the commercial tables at the moment the page '.
                    'renders. Nothing here is a cached snapshot, so there is no staleness window — and equally, no '.
                    '"last refreshed" time that differs from now.'
                ),
                Text::make(
                    'Usage data through: '.($usage['data_through'] ?? 'no usage recorded')
                ),
                Text::make(
                    'No next billing run or next retry time is shown. No scheduled job in this codebase generates '.
                    'platform invoices or retries platform payments, so there is no such time to report.'
                ),
            ]);
    }

    private function stat(string $label, string $value): Text
    {
        return Text::make(new HtmlString(
            '<span class="fi-ta-text-item-label">'.e($label).'</span><br />'.
            '<strong>'.e($value).'</strong>'
        ));
    }

    private function link(string $label, string $url): Text
    {
        return Text::make(new HtmlString(
            '<a href="'.e($url).'" class="fi-link">'.e($label).' &rarr;</a>'
        ));
    }
}
