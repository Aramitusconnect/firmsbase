<?php

namespace Tests\Feature\Webhooks\Concerns;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\EncryptionKeyService;
use App\Services\EntitlementService;

/**
 * Shared setup for Phase 14 webhook tests: a firm with the 'webhook'
 * entitlement enabled (correction #2 — separate from 'api') and an
 * active TenantEncryptionKey (needed by WebhookSecretService, which
 * reuses EmailBodyEncryptionService/EncryptionKeyService exactly as-is).
 */
trait SetsUpWebhookEntitledFirm
{
    protected function makeWebhookEntitledFirm(): Firm
    {
        $firm = Firm::factory()->create();

        app(EntitlementService::class)->setForSource(
            $firm,
            'webhook',
            EntitlementSource::AdminOverride,
            true,
        );

        app(EncryptionKeyService::class)->provision($firm);

        return $firm->fresh();
    }

    protected function makeFirmOwner(Firm $firm): FirmUser
    {
        return FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::FirmOwner]);
    }

    protected function makeAttorney(Firm $firm): FirmUser
    {
        return FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::Attorney]);
    }

    protected function makeBillingStaff(Firm $firm): FirmUser
    {
        return FirmUser::factory()->create(['firm_id' => $firm->id, 'role' => FirmUserRole::BillingStaff]);
    }
}
