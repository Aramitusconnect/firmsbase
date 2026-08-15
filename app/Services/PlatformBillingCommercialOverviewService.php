<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlanStatus;
use App\Enums\PlatformInvoiceStatus;
use App\Enums\PlatformPaymentAttemptStatus;
use App\Enums\PlatformPaymentStatus;
use App\Enums\PlatformRefundStatus;
use App\Enums\PlatformSubscriptionStatus;
use App\Enums\TrialRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PlatformBillingCommercialOverviewService — Billing & Commercial
 * Control Plane pass. The single read model behind
 * PlatformBillingCommercialOverviewPage.
 *
 * DESIGN RULES THIS SERVICE ENFORCES
 * ----------------------------------
 * 1. EVERY number here is a bounded SQL aggregate. Nothing loads a
 *    collection of subscriptions, invoices, payments, or usage rows
 *    into PHP to count or sum it. Each method below is one query (two
 *    where two different tables are involved), regardless of how many
 *    rows exist.
 *
 * 2. Money is computed in integer cents, never floats. The only
 *    division anywhere is intdiv() on the annualized total (see
 *    revenue()), and its rounding behavior is stated in that method's
 *    docblock rather than left implicit.
 *
 * 3. A metric that this schema cannot support is NOT returned as zero.
 *    It is absent from this service entirely, and the page states the
 *    capability gap in words. Concretely, the following are deliberately
 *    NOT computed here because no backing data exists at this HEAD:
 *      - payment recovery rate / recovered amount: platform_payment_
 *        attempts has no retry, dunning, recovery, or resolution state
 *        of any kind, and nothing in this codebase retries a platform
 *        payment. A "0% recovered" figure would assert a recovery
 *        process that does not exist.
 *      - credits issued / credits outstanding: there is no Credit
 *        model, table, or service anywhere in this codebase.
 *      - unbilled / priced / unpriced usage: usage_rollups has no
 *        price, rate, invoice, or finalized column — usage is recorded
 *        quantity only, with no commercial pricing linkage at all.
 *      - scheduled plan changes: platform_subscriptions has no
 *        scheduled-change column and no service supports scheduling one.
 *      - churn / ARPU / LTV / revenue growth: platform_subscriptions
 *        records cancelled_at but no historical MRR series, and this
 *        schema stores no per-subscription price snapshot, so no
 *        point-in-time revenue figure for any past period can be
 *        reconstructed. Deriving these from current-state rows alone
 *        would be a guess presented as a number.
 *      - reseller metrics: no reseller/partner domain exists.
 *
 * 4. CURRENCY. This schema has no currency column on any commercial
 *    table — not on plans, not on platform_subscriptions, not on
 *    platform_invoices, not on platform_payments (PlatformPaymentService
 *    hardcodes 'usd' when it calls the gateway). Every amount in this
 *    domain is therefore USD by construction, and this service labels
 *    it explicitly via CURRENCY rather than rendering a bare number.
 *    There is no mixed-currency summation risk here because there is no
 *    second currency; if a currency column is ever added, every SUM in
 *    this class must be re-examined before it can keep aggregating.
 *
 * 5. All time comparisons take an explicit "as of" instant passed in
 *    from PHP, never SQL now(). That keeps the numbers deterministic
 *    under Carbon test-time travel and makes the freshness stamp the
 *    page displays the same instant the figures were computed against.
 */
final class PlatformBillingCommercialOverviewService
{
    /**
     * See rule 4 above. Not a lookup — a documented invariant of this
     * schema, asserted in one place so every caller renders the same
     * tag.
     */
    public const CURRENCY = 'USD';

    /**
     * The "expiring soon" horizon for trials. Seven days, matching the
     * commercial brief; expressed as a constant so the page's label and
     * the query can never drift apart.
     */
    public const TRIAL_EXPIRY_HORIZON_DAYS = 7;

    /**
     * Recurring-revenue figures.
     *
     * INCLUDED: subscriptions in status Active only. Trialing is not
     * yet paying, PastDue is not currently collecting, and Cancelled /
     * Expired have ended — counting any of those would overstate
     * committed revenue. Those counts are returned separately so an
     * operator can see the full picture without them being folded into
     * the money figure.
     *
     * A subscription scheduled to cancel at period end IS still counted
     * (it is active and paying for the current period); `cancelling` is
     * returned alongside so the forward risk is visible.
     *
     * AMOUNT PER SUBSCRIPTION = the plan's price plus every
     * platform_subscription_items line (quantity x unit_amount_cents).
     * platform_subscriptions stores no price snapshot of its own — only
     * plan_id — so the plan's current price IS the subscription's
     * price. That is safe to rely on here precisely because
     * PlanService::update() refuses to change price_cents /
     * billing_interval / code on any plan already referenced by a
     * FirmLicense or PlatformSubscription; a plan's financial terms
     * cannot move underneath an existing subscriber.
     *
     * NORMALIZATION. Each subscription's amount is first annualized in
     * exact integer cents (monthly x 12, annual x 1) and summed. ARR is
     * that exact sum. MRR is intdiv(ARR, 12) — a single truncating
     * division applied ONCE to the total, not per row, so per-row
     * rounding cannot accumulate. MRR x 12 may therefore be up to 11
     * cents below ARR; ARR is the exact figure and MRR is the derived
     * one, which is the honest way round given annual plans are priced
     * annually in this schema.
     *
     * TAX AND DISCOUNTS ARE EXCLUDED, because neither exists on a plan:
     * `plans` has no tax rate, tax behavior, jurisdiction, setup fee,
     * or discount column. These are list-price figures.
     *
     * @return array{active:int, trialing:int, past_due:int, cancelled:int, expired:int, cancelling:int, arr_cents:int, mrr_cents:int}
     */
    public function revenue(): array
    {
        $counts = DB::table('platform_subscriptions')
            ->selectRaw('count(*) filter (where status = ?) as active', [PlatformSubscriptionStatus::Active->value])
            ->selectRaw('count(*) filter (where status = ?) as trialing', [PlatformSubscriptionStatus::Trialing->value])
            ->selectRaw('count(*) filter (where status = ?) as past_due', [PlatformSubscriptionStatus::PastDue->value])
            ->selectRaw('count(*) filter (where status = ?) as cancelled', [PlatformSubscriptionStatus::Cancelled->value])
            ->selectRaw('count(*) filter (where status = ?) as expired', [PlatformSubscriptionStatus::Expired->value])
            ->selectRaw(
                'count(*) filter (where cancel_at_period_end and status in (?, ?)) as cancelling',
                [PlatformSubscriptionStatus::Active->value, PlatformSubscriptionStatus::Trialing->value],
            )
            ->first();

        $annualized = (int) (DB::table('platform_subscriptions as s')
            ->join('plans as p', 'p.id', '=', 's.plan_id')
            ->leftJoinSub(
                DB::table('platform_subscription_items')
                    ->select('platform_subscription_id')
                    ->selectRaw('sum(quantity * unit_amount_cents) as items_cents')
                    ->groupBy('platform_subscription_id'),
                'i',
                'i.platform_subscription_id',
                '=',
                's.id',
            )
            ->where('s.status', PlatformSubscriptionStatus::Active->value)
            ->selectRaw(
                'coalesce(sum(case when s.billing_interval = ? then (p.price_cents + coalesce(i.items_cents, 0)) '.
                'else (p.price_cents + coalesce(i.items_cents, 0)) * 12 end), 0) as annualized_cents',
                ['annual'],
            )
            ->value('annualized_cents') ?? 0);

        return [
            'active' => (int) ($counts->active ?? 0),
            'trialing' => (int) ($counts->trialing ?? 0),
            'past_due' => (int) ($counts->past_due ?? 0),
            'cancelled' => (int) ($counts->cancelled ?? 0),
            'expired' => (int) ($counts->expired ?? 0),
            'cancelling' => (int) ($counts->cancelling ?? 0),
            'arr_cents' => $annualized,
            'mrr_cents' => intdiv($annualized, 12),
        ];
    }

    /**
     * Trial figures.
     *
     * `conversion_rate` is ALL-TIME and its denominator is stated
     * explicitly: trials that reached a terminal outcome (Converted,
     * Expired, or Cancelled). It is deliberately not period-bounded,
     * because trial_requests records converted_at but has NO timestamp
     * for expiry or cancellation — only a status. A period-bounded rate
     * would need a denominator this table cannot produce, so rather
     * than divide by an incompatible denominator, the rate is scoped to
     * something the data actually supports. Returns null (never 0.0)
     * when no trial has reached a terminal outcome yet.
     *
     * @return array{active:int, expiring_soon:int, awaiting_provisioning:int, provisioned:int, converted:int, expired:int, cancelled:int, terminal:int, conversion_rate:float|null}
     */
    public function trials(CarbonImmutable $asOf): array
    {
        $horizon = $asOf->addDays(self::TRIAL_EXPIRY_HORIZON_DAYS);

        $row = DB::table('trial_requests')
            ->selectRaw('count(*) filter (where status = ?) as active', [TrialRequestStatus::Active->value])
            ->selectRaw(
                'count(*) filter (where status = ? and expires_at is not null and expires_at >= ? and expires_at < ?) as expiring_soon',
                [TrialRequestStatus::Active->value, $asOf, $horizon],
            )
            ->selectRaw('count(*) filter (where status = ?) as awaiting_provisioning', [TrialRequestStatus::Requested->value])
            ->selectRaw('count(*) filter (where status = ?) as provisioned', [TrialRequestStatus::Provisioned->value])
            ->selectRaw('count(*) filter (where status = ?) as converted', [TrialRequestStatus::Converted->value])
            ->selectRaw('count(*) filter (where status = ?) as expired', [TrialRequestStatus::Expired->value])
            ->selectRaw('count(*) filter (where status = ?) as cancelled', [TrialRequestStatus::Cancelled->value])
            ->first();

        $converted = (int) ($row->converted ?? 0);
        $terminal = $converted + (int) ($row->expired ?? 0) + (int) ($row->cancelled ?? 0);

        return [
            'active' => (int) ($row->active ?? 0),
            'expiring_soon' => (int) ($row->expiring_soon ?? 0),
            'awaiting_provisioning' => (int) ($row->awaiting_provisioning ?? 0),
            'provisioned' => (int) ($row->provisioned ?? 0),
            'converted' => $converted,
            'expired' => (int) ($row->expired ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
            'terminal' => $terminal,
            'conversion_rate' => $terminal === 0 ? null : round($converted / $terminal * 100, 1),
        ];
    }

    /**
     * Invoice figures.
     *
     * `outstanding_cents` sums total_cents for invoices in Open or
     * PastDue only. Draft invoices have not been issued; Paid and Void
     * are settled. There is no partial-payment concept to net off:
     * platform_invoices carries no amount-paid column, and
     * PlatformPaymentService only ever charges the full invoice total
     * and marks the invoice Paid on success — so an unpaid invoice's
     * outstanding amount IS its total.
     *
     * `overdue` counts invoices that are Open or PastDue AND whose
     * due_at has passed — a due-date fact, independent of whether a job
     * has moved the row into the PastDue status. `past_due_status`
     * reports the stored status separately so the two are never
     * conflated.
     *
     * @return array{draft:int, open:int, past_due_status:int, paid:int, void:int, overdue:int, outstanding_cents:int}
     */
    public function invoices(CarbonImmutable $asOf): array
    {
        $unpaid = [PlatformInvoiceStatus::Open->value, PlatformInvoiceStatus::PastDue->value];

        $row = DB::table('platform_invoices')
            ->selectRaw('count(*) filter (where status = ?) as draft', [PlatformInvoiceStatus::Draft->value])
            ->selectRaw('count(*) filter (where status = ?) as open', [PlatformInvoiceStatus::Open->value])
            ->selectRaw('count(*) filter (where status = ?) as past_due_status', [PlatformInvoiceStatus::PastDue->value])
            ->selectRaw('count(*) filter (where status = ?) as paid', [PlatformInvoiceStatus::Paid->value])
            ->selectRaw('count(*) filter (where status = ?) as void', [PlatformInvoiceStatus::Void->value])
            ->selectRaw('count(*) filter (where status in (?, ?) and due_at is not null and due_at < ?) as overdue', [...$unpaid, $asOf])
            ->selectRaw('coalesce(sum(total_cents) filter (where status in (?, ?)), 0) as outstanding_cents', $unpaid)
            ->first();

        return [
            'draft' => (int) ($row->draft ?? 0),
            'open' => (int) ($row->open ?? 0),
            'past_due_status' => (int) ($row->past_due_status ?? 0),
            'paid' => (int) ($row->paid ?? 0),
            'void' => (int) ($row->void ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'outstanding_cents' => (int) ($row->outstanding_cents ?? 0),
        ];
    }

    /**
     * Payment figures — what was actually attempted and settled.
     *
     * `accounts_with_failures` is a distinct count of billing accounts
     * with at least one failed attempt: the closest thing this schema
     * supports to "accounts requiring attention". It is NOT a recovery
     * queue — nothing retries these — so the page labels it as
     * accounts with a failed attempt on record, not as work to do.
     *
     * No recovery rate, recovered amount, or amount-at-risk is returned.
     * See this class's own docblock, rule 3.
     *
     * @return array{succeeded:int, failed:int, refunded:int, partially_refunded:int, failed_attempts:int, accounts_with_failures:int, refunds_pending:int, refunds_completed:int, refunds_failed:int, refunded_cents:int}
     */
    public function payments(): array
    {
        $payments = DB::table('platform_payments')
            ->selectRaw('count(*) filter (where status = ?) as succeeded', [PlatformPaymentStatus::Succeeded->value])
            ->selectRaw('count(*) filter (where status = ?) as failed', [PlatformPaymentStatus::Failed->value])
            ->selectRaw('count(*) filter (where status = ?) as refunded', [PlatformPaymentStatus::Refunded->value])
            ->selectRaw('count(*) filter (where status = ?) as partially_refunded', [PlatformPaymentStatus::PartiallyRefunded->value])
            ->first();

        $attempts = DB::table('platform_payment_attempts')
            ->selectRaw('count(*) filter (where status = ?) as failed_attempts', [PlatformPaymentAttemptStatus::Failed->value])
            ->selectRaw(
                'count(distinct billing_account_id) filter (where status = ?) as accounts_with_failures',
                [PlatformPaymentAttemptStatus::Failed->value],
            )
            ->first();

        $refunds = DB::table('platform_refunds')
            ->selectRaw(
                'count(*) filter (where status in (?, ?)) as refunds_pending',
                [PlatformRefundStatus::Requested->value, PlatformRefundStatus::Processing->value],
            )
            ->selectRaw('count(*) filter (where status = ?) as refunds_completed', [PlatformRefundStatus::Completed->value])
            ->selectRaw('count(*) filter (where status = ?) as refunds_failed', [PlatformRefundStatus::Failed->value])
            ->selectRaw(
                'coalesce(sum(amount_cents) filter (where status = ?), 0) as refunded_cents',
                [PlatformRefundStatus::Completed->value],
            )
            ->first();

        return [
            'succeeded' => (int) ($payments->succeeded ?? 0),
            'failed' => (int) ($payments->failed ?? 0),
            'refunded' => (int) ($payments->refunded ?? 0),
            'partially_refunded' => (int) ($payments->partially_refunded ?? 0),
            'failed_attempts' => (int) ($attempts->failed_attempts ?? 0),
            'accounts_with_failures' => (int) ($attempts->accounts_with_failures ?? 0),
            'refunds_pending' => (int) ($refunds->refunds_pending ?? 0),
            'refunds_completed' => (int) ($refunds->refunds_completed ?? 0),
            'refunds_failed' => (int) ($refunds->refunds_failed ?? 0),
            'refunded_cents' => (int) ($refunds->refunded_cents ?? 0),
        ];
    }

    /**
     * Platform usage figures.
     *
     * ATTRIBUTION, NOT PRICING. usage_rollups records a quantity for a
     * (billing account, optional firm, metric, period) and nothing
     * else: no unit price, no rate card, no charge, no invoice link, no
     * finalized flag. So there is no priced/unpriced/invoiced/billable
     * split to report, and none is invented here — only how many
     * records exist and how they are attributed.
     *
     * A null firm_id row is the BILLING-ACCOUNT-LEVEL aggregate for that
     * metric/period, not an unattributed orphan (see
     * UsageRollup::isAccountLevelAggregate()). It is reported as
     * "account-level", never as "unallocated", because calling it
     * unallocated would describe a data-quality problem that is not
     * happening.
     *
     * `data_through` is the latest period_ends_at on record — the real
     * freshness bound of this dataset, or null when nothing is recorded.
     *
     * @return array{records:int, account_level:int, firm_attributed:int, billing_accounts:int, metrics:int, data_through:?string}
     */
    public function usage(): array
    {
        $row = DB::table('usage_rollups')
            ->selectRaw('count(*) as records')
            ->selectRaw('count(*) filter (where firm_id is null) as account_level')
            ->selectRaw('count(*) filter (where firm_id is not null) as firm_attributed')
            ->selectRaw('count(distinct billing_account_id) as billing_accounts')
            ->selectRaw('count(distinct metric) as metrics')
            ->selectRaw('max(period_ends_at) as data_through')
            ->first();

        return [
            'records' => (int) ($row->records ?? 0),
            'account_level' => (int) ($row->account_level ?? 0),
            'firm_attributed' => (int) ($row->firm_attributed ?? 0),
            'billing_accounts' => (int) ($row->billing_accounts ?? 0),
            'metrics' => (int) ($row->metrics ?? 0),
            'data_through' => $row->data_through ?? null,
        ];
    }

    /**
     * Catalog figures — the commercial source configuration itself.
     *
     * Add-ons are plan_modules rows flagged is_addon (this domain's
     * approved model — there is no separate add-ons table), so an
     * "active add-on" here means an enabled, non-retired add-on module
     * row on some plan.
     *
     * @return array{plans_active:int, plans_draft:int, plans_archived:int, addons_enabled:int, addons_total:int, modules_bundled:int}
     */
    public function catalog(): array
    {
        $plans = DB::table('plans')
            ->selectRaw('count(*) filter (where status = ?) as plans_active', [PlanStatus::Active->value])
            ->selectRaw('count(*) filter (where status = ?) as plans_draft', [PlanStatus::Draft->value])
            ->selectRaw('count(*) filter (where status = ?) as plans_archived', [PlanStatus::Archived->value])
            ->first();

        $modules = DB::table('plan_modules')
            ->selectRaw("count(*) filter (where is_addon and enabled and status = 'active') as addons_enabled")
            ->selectRaw('count(*) filter (where is_addon) as addons_total')
            ->selectRaw('count(*) filter (where not is_addon) as modules_bundled')
            ->first();

        return [
            'plans_active' => (int) ($plans->plans_active ?? 0),
            'plans_draft' => (int) ($plans->plans_draft ?? 0),
            'plans_archived' => (int) ($plans->plans_archived ?? 0),
            'addons_enabled' => (int) ($modules->addons_enabled ?? 0),
            'addons_total' => (int) ($modules->addons_total ?? 0),
            'modules_bundled' => (int) ($modules->modules_bundled ?? 0),
        ];
    }

    /**
     * Commercial conditions that a human operator can actually do
     * something about, right now.
     *
     * Deliberately EXCLUDED from this queue, even though the brief
     * lists them as candidate conditions: the missing payment gateway,
     * the missing Credit domain, and the missing reseller domain. Those
     * are permanent product/roadmap gaps with no operator action that
     * would clear them — parking them in an alert queue trains
     * operators to ignore the queue. The page states them separately as
     * standing capability boundaries instead.
     *
     * Every entry names a real, queryable condition:
     *  - overdue invoices: past their due date and unpaid.
     *  - billing accounts with failed payment attempts on record.
     *  - trials expiring inside the horizon: a sales action.
     *  - trials awaiting provisioning: an operations action.
     *  - active plans priced at zero: a catalog configuration error a
     *    plan owner can correct (a zero-price active plan bills nothing).
     *  - live subscriptions on a non-active plan: a real configuration
     *    inconsistency (a subscriber sitting on a draft or archived
     *    plan), surfaced because it silently distorts every revenue
     *    figure above.
     *
     * @return array<int, array{key:string, label:string, count:int, detail:string}>
     */
    public function requiresAttention(CarbonImmutable $asOf): array
    {
        $invoices = $this->invoices($asOf);
        $payments = $this->payments();
        $trials = $this->trials($asOf);

        $zeroPricedActivePlans = (int) DB::table('plans')
            ->where('status', PlanStatus::Active->value)
            ->where('price_cents', 0)
            ->count();

        $subscriptionsOnNonActivePlan = (int) DB::table('platform_subscriptions as s')
            ->join('plans as p', 'p.id', '=', 's.plan_id')
            ->whereIn('s.status', [
                PlatformSubscriptionStatus::Active->value,
                PlatformSubscriptionStatus::Trialing->value,
                PlatformSubscriptionStatus::PastDue->value,
            ])
            ->where('p.status', '!=', PlanStatus::Active->value)
            ->count();

        $candidates = [
            [
                'key' => 'overdue_invoices',
                'label' => 'Overdue invoices',
                'count' => $invoices['overdue'],
                'detail' => 'Issued, unpaid, and past their due date.',
            ],
            [
                'key' => 'accounts_with_failed_payments',
                'label' => 'Billing accounts with a failed payment attempt',
                'count' => $payments['accounts_with_failures'],
                'detail' => 'Recorded failures only — no retry or dunning exists to work them through.',
            ],
            [
                'key' => 'trials_expiring',
                'label' => 'Trials expiring within '.self::TRIAL_EXPIRY_HORIZON_DAYS.' days',
                'count' => $trials['expiring_soon'],
                'detail' => 'Active trials whose expiry date falls inside the horizon.',
            ],
            [
                'key' => 'trials_awaiting_provisioning',
                'label' => 'Trials awaiting provisioning',
                'count' => $trials['awaiting_provisioning'],
                'detail' => 'Requested from the sales pipeline and not yet provisioned to an organization.',
            ],
            [
                'key' => 'zero_priced_active_plans',
                'label' => 'Active plans priced at zero',
                'count' => $zeroPricedActivePlans,
                'detail' => 'An active plan with a zero price bills nothing — confirm this is intentional.',
            ],
            [
                'key' => 'subscriptions_on_non_active_plan',
                'label' => 'Live subscriptions on a draft or archived plan',
                'count' => $subscriptionsOnNonActivePlan,
                'detail' => 'A subscriber is sitting on a plan that is no longer active in the catalog.',
            ],
        ];

        return array_values(array_filter($candidates, fn (array $item): bool => $item['count'] > 0));
    }
}
