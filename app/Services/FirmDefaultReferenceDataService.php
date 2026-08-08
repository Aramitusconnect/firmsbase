<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\LeadSource;

/**
 * FirmDefaultReferenceDataService — FirmsVault staging follow-up
 * addition ("Application Completion — Catalogs + Firm-Owned Reference
 * Data"). Idempotently seeds each firm's own starting
 * ExpenseCategory/LeadSource rows — the firm-scoped counterpart to
 * PracticeAreaService/MatterTypeService's platform-global catalog.
 *
 * Deliberately writes directly (wrapped in its own
 * TenantContextService::runWithFirmContext() call, mirroring
 * ActivationChecklistService::seedProductionReadinessItems()'s exact
 * shape) rather than routing through ExpenseCategoryService::create() —
 * that service's create() unconditionally asserts the `expenses` module
 * entitlement is enabled first, which is correct for a real user-facing
 * "+ Add Expense Category" submission but wrong here: default reference
 * data must exist for every newly provisioned firm regardless of
 * whether expenses happens to be entitled yet at provisioning time (a
 * plan-less firm, or a plan without the expenses module, must still end
 * up with the same starting categories ready for the moment expenses
 * IS later entitled — see FirmProvisioningService's own call site for
 * why this runs unconditionally, not gated on $plan). LeadSource has no
 * such entitlement gate at all, so seedDefaultLeadSources() writes
 * directly for the same architectural consistency, not because it
 * needs to route around anything.
 *
 * Idempotent by construction: every call re-checks which of the fixed
 * default names/codes already exist for the firm and only inserts the
 * missing ones — safe to call on a firm that already has some or all
 * defaults (e.g. the repair command re-run, or a firm that already
 * self-created a category with the same name before this ran).
 */
class FirmDefaultReferenceDataService
{
    /**
     * @var array<int, string>
     */
    private const DEFAULT_EXPENSE_CATEGORY_NAMES = [
        'Filing Fees',
        'Court Costs',
        'Service of Process',
        'Postage / Courier',
        'Copies / Printing',
        'Travel',
        'Mileage',
        'Parking / Tolls',
        'Expert Fees',
        'Investigator Fees',
        'Medical Records',
        'Transcripts',
        'Research',
        'Other Client Cost',
        'Internal / Non-Billable Expense',
    ];

    /**
     * @var array<string, string> code => name
     */
    private const DEFAULT_LEAD_SOURCES = [
        'referral_client' => 'Referral - Client',
        'referral_attorney' => 'Referral - Attorney',
        'referral_professional' => 'Referral - Professional',
        'website' => 'Website',
        'google' => 'Google',
        'social_media' => 'Social Media',
        'advertising' => 'Advertising',
        'event_community' => 'Event / Community',
        'walk_in' => 'Walk-In',
        'phone' => 'Phone',
        'returning_client' => 'Returning Client',
        'other' => 'Other',
    ];

    public function __construct(
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    /**
     * @return array<int, string> the category names actually inserted (empty if all already existed)
     */
    public function seedDefaultExpenseCategories(Firm $firm): array
    {
        return $this->tenantContext->runWithFirmContext($firm, function () use ($firm): array {
            $existingNamesLowercase = ExpenseCategory::query()
                ->where('firm_id', $firm->id)
                ->get('name')
                ->map(fn (ExpenseCategory $category): string => strtolower($category->name))
                ->all();

            $inserted = [];

            foreach (self::DEFAULT_EXPENSE_CATEGORY_NAMES as $name) {
                if (in_array(strtolower($name), $existingNamesLowercase, true)) {
                    continue;
                }

                ExpenseCategory::create([
                    'firm_id' => $firm->id,
                    'chart_of_accounts_id' => null,
                    'name' => $name,
                    'is_active' => true,
                ]);

                $inserted[] = $name;
            }

            return $inserted;
        });
    }

    /**
     * @return array<int, string> the lead source codes actually inserted (empty if all already existed)
     */
    public function seedDefaultLeadSources(Firm $firm): array
    {
        return $this->tenantContext->runWithFirmContext($firm, function () use ($firm): array {
            $existingCodes = LeadSource::query()
                ->where('firm_id', $firm->id)
                ->pluck('code')
                ->all();

            $inserted = [];

            foreach (self::DEFAULT_LEAD_SOURCES as $code => $name) {
                if (in_array($code, $existingCodes, true)) {
                    continue;
                }

                LeadSource::create([
                    'firm_id' => $firm->id,
                    'code' => $code,
                    'name' => $name,
                    'is_active' => true,
                ]);

                $inserted[] = $code;
            }

            return $inserted;
        });
    }

    /**
     * @return array{expense_categories: array<int, string>, lead_sources: array<int, string>}
     */
    public function seedAllDefaults(Firm $firm): array
    {
        return [
            'expense_categories' => $this->seedDefaultExpenseCategories($firm),
            'lead_sources' => $this->seedDefaultLeadSources($firm),
        ];
    }
}
