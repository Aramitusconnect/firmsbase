<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Firm;
use App\Models\LeadSource;
use InvalidArgumentException;

/**
 * LeadSourceService — FirmsVault staging follow-up addition
 * ("Application Completion — Catalogs + Firm-Owned Reference Data").
 * The only writer of `lead_sources` — firm_id is always the caller's
 * firm (LeadSource is intentionally firm-scoped, never platform-global
 * — see that model's own docblock). Mirrors ExpenseCategoryService's
 * exact shape: `lead_sources` carries permanent FORCE ROW LEVEL
 * SECURITY (see database/migrations for its RLS-activation migration),
 * so every real DB write below runs inside its own
 * runWithFirmContext() call.
 *
 * Deactivation is a soft state flip (is_active=false), never a hard
 * delete — a lead source already referenced by a FirmLead must remain
 * a valid foreign key target forever; only new selections stop
 * offering it (FirmLeadResource's own `->where('is_active', true)`
 * filter already enforces that).
 */
class LeadSourceService
{
    public function create(Firm $firm, string $code, string $name): LeadSource
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $code, $name): LeadSource {
            $this->assertCodeIsUniqueWithinFirm($firm, $code);

            return LeadSource::create([
                'firm_id' => $firm->id,
                'code' => $code,
                'name' => $name,
                'is_active' => true,
            ]);
        });
    }

    public function update(Firm $firm, LeadSource $leadSource, string $code, string $name): LeadSource
    {
        $this->assertBelongsToFirm($leadSource, $firm);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $leadSource, $code, $name): LeadSource {
            if (strcasecmp($code, $leadSource->code) !== 0) {
                $this->assertCodeIsUniqueWithinFirm($firm, $code, excludingId: $leadSource->id);
            }

            $leadSource->update(['code' => $code, 'name' => $name]);

            return $leadSource->fresh();
        });
    }

    public function deactivate(Firm $firm, LeadSource $leadSource): LeadSource
    {
        $this->assertBelongsToFirm($leadSource, $firm);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($leadSource): LeadSource {
            $leadSource->update(['is_active' => false]);

            return $leadSource->fresh();
        });
    }

    public function reactivate(Firm $firm, LeadSource $leadSource): LeadSource
    {
        $this->assertBelongsToFirm($leadSource, $firm);

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($leadSource): LeadSource {
            $leadSource->update(['is_active' => true]);

            return $leadSource->fresh();
        });
    }

    private function assertBelongsToFirm(LeadSource $leadSource, Firm $firm): void
    {
        if ((int) $leadSource->firm_id !== (int) $firm->id) {
            throw new InvalidArgumentException('This lead source does not belong to the acting firm.');
        }
    }

    private function assertCodeIsUniqueWithinFirm(Firm $firm, string $code, ?int $excludingId = null): void
    {
        $query = LeadSource::query()
            ->where('firm_id', $firm->id)
            ->whereRaw('lower(code) = ?', [strtolower($code)]);

        if ($excludingId !== null) {
            $query->whereKeyNot($excludingId);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException("A lead source with code \"{$code}\" already exists for this firm.");
        }
    }
}
