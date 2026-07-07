<?php

namespace Tests\Feature\Governance\FinalExecutiveRecommendation;

use App\Enums\GovernanceMappingStatus;
use App\Services\FinalExecutiveReadinessMappingService;
use Tests\TestCase;

/**
 * ExecutiveStructuralCommitmentsTest — proves the three structural
 * commitments FinalExecutiveReadinessMappingService::structuralCommitments()
 * classifies Implemented actually hold, using STRUCTURAL ordering
 * (migration filename sequence, which Laravel timestamp-prefixes
 * chronologically) and presence checks rather than hardcoded exact
 * timestamp strings.
 */
class ExecutiveStructuralCommitmentsTest extends TestCase
{
    private FinalExecutiveReadinessMappingService $service;

    /** @var array<int, string> */
    private array $migrationFilenames;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FinalExecutiveReadinessMappingService();

        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files);
        $this->migrationFilenames = array_map(fn (string $path) => basename($path), $files);
    }

    public function test_organizations_migration_exists_before_firms_migration(): void
    {
        $organizationsIndex = $this->indexOfMigrationContaining('create_organizations_table');
        $firmsIndex = $this->indexOfMigrationContaining('create_firms_table');

        $this->assertNotNull($organizationsIndex, 'No organizations migration found.');
        $this->assertNotNull($firmsIndex, 'No firms migration found.');
        $this->assertLessThan($firmsIndex, $organizationsIndex, 'organizations migration must precede firms migration.');
    }

    public function test_billing_accounts_migration_exists_before_firms_migration(): void
    {
        $billingAccountsIndex = $this->indexOfMigrationContaining('create_billing_accounts_table');
        $firmsIndex = $this->indexOfMigrationContaining('create_firms_table');

        $this->assertNotNull($billingAccountsIndex, 'No billing_accounts migration found.');
        $this->assertNotNull($firmsIndex, 'No firms migration found.');
        $this->assertLessThan($firmsIndex, $billingAccountsIndex, 'billing_accounts migration must precede firms migration.');
    }

    public function test_communication_consents_migration_exists_among_early_foundation_migrations(): void
    {
        $communicationConsentsIndex = $this->indexOfMigrationContaining('create_communication_consents_table');
        $totalMigrationCount = count($this->migrationFilenames);

        $this->assertNotNull($communicationConsentsIndex, 'No communication_consents migration found.');
        // "Early" is structural, not an exact position: it must fall
        // within the first quarter of the entire migration sequence.
        $this->assertLessThan($totalMigrationCount / 4, $communicationConsentsIndex, 'communication_consents migration is not among the early foundation migrations.');
    }

    public function test_payment_plans_migration_exists_before_platform_billing_and_deployment_governance_migrations(): void
    {
        $paymentPlansIndex = $this->indexOfMigrationContaining('create_payment_plans_table');
        $platformBillingIndex = $this->indexOfMigrationContaining('create_platform_subscriptions_table');
        $deploymentConfigIndex = $this->indexOfMigrationContaining('create_deployment_configs_table');

        $this->assertNotNull($paymentPlansIndex, 'No payment_plans migration found.');
        $this->assertNotNull($platformBillingIndex, 'No platform billing migration found.');
        $this->assertNotNull($deploymentConfigIndex, 'No deployment governance migration found.');

        $this->assertLessThan($platformBillingIndex, $paymentPlansIndex, 'payment_plans migration must precede platform billing migrations.');
        $this->assertLessThan($deploymentConfigIndex, $paymentPlansIndex, 'payment_plans migration must precede deployment governance migrations.');
    }

    public function test_fleet_migration_runs_and_license_files_exist_with_phase_16_deployment_control_migrations(): void
    {
        $fleetMigrationRunsIndex = $this->indexOfMigrationContaining('create_fleet_migration_runs_table');
        $licenseFilesIndex = $this->indexOfMigrationContaining('create_license_files_table');
        $deploymentConfigIndex = $this->indexOfMigrationContaining('create_deployment_configs_table');

        $this->assertNotNull($fleetMigrationRunsIndex, 'No fleet_migration_runs migration found.');
        $this->assertNotNull($licenseFilesIndex, 'No license_files migration found.');
        $this->assertNotNull($deploymentConfigIndex, 'No deployment_configs migration found.');

        // "With" is structural proximity, not exact adjacency: all three
        // must fall within the same 30-migration deployment-control
        // neighborhood rather than being scattered arbitrarily far apart.
        $this->assertLessThan(30, abs($fleetMigrationRunsIndex - $deploymentConfigIndex));
        $this->assertLessThan(30, abs($licenseFilesIndex - $deploymentConfigIndex));
    }

    public function test_each_structural_commitment_is_implemented(): void
    {
        foreach ($this->service->structuralCommitments() as $item) {
            $this->assertSame(GovernanceMappingStatus::Implemented, $item->status, "{$item->item_key} should be Implemented.");
            $this->assertNotEmpty($item->notes);
        }
    }

    private function indexOfMigrationContaining(string $needle): ?int
    {
        foreach ($this->migrationFilenames as $index => $filename) {
            if (str_contains($filename, $needle)) {
                return $index;
            }
        }

        return null;
    }
}
