<?php

namespace Tests\Feature\Governance\Concerns;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\EncryptionKeyService;

/**
 * Shared setup for Phase 17 governance tests: a firm with a provisioned
 * TenantEncryptionKey (needed by key-destruction tests) and helper
 * factories for platform admins.
 */
trait SetsUpGovernanceFirm
{
    protected function makeGovernanceFirm(): Firm
    {
        $firm = Firm::factory()->create();

        app(EncryptionKeyService::class)->provision($firm);

        return $firm->fresh();
    }

    protected function makePlatformAdmin(): PlatformAdmin
    {
        return PlatformAdmin::factory()->create();
    }
}
