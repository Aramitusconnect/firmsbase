<?php

namespace App\Services;

use App\Enums\PlatformLeadStatus;
use App\Models\PlatformAdmin;
use App\Models\PlatformLead;

/**
 * PlatformSalesLeadService — the only writer of platform_leads.
 * Deliberately unrelated to Phase 2's FirmLead/firm_leads — a platform
 * sales lead is a prospective law firm, not a firm's own client-intake
 * lead.
 */
class PlatformSalesLeadService
{
    public function create(array $attributes): PlatformLead
    {
        return PlatformLead::create([
            'company_name' => $attributes['company_name'],
            'contact_name' => $attributes['contact_name'],
            'contact_email' => $attributes['contact_email'] ?? null,
            'contact_phone' => $attributes['contact_phone'] ?? null,
            'source' => $attributes['source'] ?? null,
            'status' => PlatformLeadStatus::New,
            'notes' => $attributes['notes'] ?? null,
        ]);
    }

    public function assignTo(PlatformLead $lead, PlatformAdmin $admin): PlatformLead
    {
        $lead->update(['assigned_to' => $admin->id]);

        return $lead->fresh();
    }

    public function updateStatus(PlatformLead $lead, PlatformLeadStatus $status): PlatformLead
    {
        $lead->update(['status' => $status]);

        return $lead->fresh();
    }

    public function disqualify(PlatformLead $lead, string $reason): PlatformLead
    {
        $lead->update([
            'status' => PlatformLeadStatus::Disqualified,
            'notes' => trim(($lead->notes ?? '')."\nDisqualified: {$reason}"),
        ]);

        return $lead->fresh();
    }
}
