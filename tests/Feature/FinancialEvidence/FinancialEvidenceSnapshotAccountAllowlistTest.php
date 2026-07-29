<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Enums\FirmUserRole;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Models\FirmIntegration;
use App\Livewire\FinancialEvidence\FinancialEvidenceSnapshotsPanel;
use App\Models\Client;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceClientConsent;
use App\Models\FinancialEvidenceMatterAuthorization;
use App\Models\FinancialEvidenceSnapshot;
use App\Models\FinancialEvidenceTransaction;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\MatterAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * FinancialEvidenceSnapshotAccountAllowlistTest — H3 remediation proof.
 *
 * FinancialEvidenceSnapshotsPanel's "Create Snapshot" wizard scopes its
 * CheckboxList OPTIONS to
 * FinancialEvidenceMatterScopeService::connectedBankAccountIds() for
 * DISPLAY, but generateSnapshot() previously used the SUBMITTED
 * `bank_account_ids` verbatim — never re-intersecting them against that
 * same allowlist — so tampered checkbox values permanently baked
 * another matter's (or another client's, or another firm's) accounts
 * and transactions into an immutable, exportable
 * financial_evidence_snapshots row.
 *
 * Every test here drives generateSnapshot() with a DIRECTLY-SUPPLIED
 * data array — i.e. the attacker's position, bypassing the rendered
 * form entirely — which is exactly the input path the display-time
 * scoping never covered.
 *
 * Rejection convention: the WHOLE request is refused (RuntimeException,
 * no snapshot row written), matching the existing sibling precedent in
 * FinancialEvidenceReportsPanel::loadSnapshotOrFail(), which refuses
 * the entire export rather than emitting a partial one. A mixed
 * valid+invalid submission is therefore rejected outright — never
 * silently trimmed while still reporting success.
 */
class FinancialEvidenceSnapshotAccountAllowlistTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // The legitimate path still works.
    // ------------------------------------------------------------

    public function test_an_authorized_account_produces_a_snapshot_whose_provenance_records_the_verified_set(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel($world);

        $this->generate($panel, $world['firm'], [$world['matterA']['account']->id]);

        $snapshot = $this->runWithFirmContext($world['firm'], fn () => FinancialEvidenceSnapshot::query()
            ->where('matter_id', $world['matterA']['matter']->id)
            ->first());

        $this->assertNotNull($snapshot);
        $this->assertSame([$world['matterA']['account']->id], $snapshot->authorized_account_ids_json);
        $this->assertSame(
            [$world['matterA']['connection']->id],
            $snapshot->authorized_source_json['firm_integration_ids'],
            'authorized_source_json must be derived from the POST-INTERSECTION account set.'
        );
        $this->assertCount(1, $snapshot->retrieved_record_refs_json, 'Only Matter A\'s own transaction may be referenced.');
        $this->assertSame(
            $world['matterA']['transaction']->id,
            $snapshot->retrieved_record_refs_json[0]['id'],
        );
    }

    // ------------------------------------------------------------
    // H3 — the tampering matrix.
    // ------------------------------------------------------------

    public function test_an_account_from_another_matter_in_the_same_firm_is_rejected(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel($world);

        $this->assertRejected(
            $panel,
            $world['firm'],
            [$world['matterB']['account']->id],
            'not authorized for this matter',
        );

        $this->assertNoSnapshotExists($world);
    }

    public function test_an_account_belonging_to_another_client_is_rejected(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel($world);

        // Matter B in the same firm belongs to a DIFFERENT Client (each
        // Matter::factory()->forFirm() mints its own client) and hangs
        // off that client's own connection.
        $this->assertNotSame(
            $world['matterA']['matter']->client_id,
            $world['matterB']['matter']->client_id,
            'Sanity check: the two matters must belong to different clients for this test to mean anything.'
        );

        $this->assertRejected(
            $panel,
            $world['firm'],
            [$world['matterB']['account']->id],
            'not authorized for this matter',
        );

        $this->assertNoSnapshotExists($world);
    }

    public function test_an_account_from_another_firm_is_rejected(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel($world);

        $this->assertRejected(
            $panel,
            $world['firm'],
            [$world['otherFirmMatter']['account']->id],
            'not authorized for this matter',
        );

        $this->assertNoSnapshotExists($world);
    }

    public function test_a_mixed_valid_and_invalid_submission_is_rejected_outright_and_writes_nothing(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel($world);

        $this->assertRejected(
            $panel,
            $world['firm'],
            [$world['matterA']['account']->id, $world['matterB']['account']->id],
            'not authorized for this matter',
        );

        $this->assertNoSnapshotExists(
            $world,
            'A mixed valid+invalid submission must be refused outright — never silently trimmed to the valid subset '
            .'while still reporting success.'
        );
    }

    public function test_an_empty_account_set_is_rejected_rather_than_producing_an_empty_snapshot(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel($world);

        $this->assertRejected($panel, $world['firm'], [], 'At least one authorized account');
        $this->assertNoSnapshotExists($world);
    }

    /**
     * The public Livewire property is set DIRECTLY here (not via a form
     * submission), proving the gate is not merely form-level: the
     * component is re-pointed at Matter B — a matter the acting user is
     * not assigned to — and the mutation is still refused.
     */
    public function test_direct_livewire_property_tampering_on_matter_id_is_still_caught(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel($world);

        $panel->matterId = $world['matterB']['matter']->id;

        try {
            $this->generate($panel, $world['firm'], [$world['matterB']['account']->id]);
            $this->fail('Re-pointing the public matterId property at an unassigned matter must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('do not have access to this matter', $e->getMessage());
        }

        $this->runWithFirmContext($world['firm'], function () use ($world): void {
            $this->assertFalse(
                FinancialEvidenceSnapshot::query()->where('matter_id', $world['matterB']['matter']->id)->exists(),
            );
        });
    }

    // ------------------------------------------------------------
    // H3 — the authorization / consent / connection chain must be live.
    // ------------------------------------------------------------

    public function test_a_superseded_authorization_leaves_no_authorized_account_and_is_rejected(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel($world);

        $this->runWithFirmContext($world['firm'], fn () => FinancialEvidenceMatterAuthorization::query()
            ->where('matter_id', $world['matterA']['matter']->id)
            ->update(['superseded_at' => now()]));

        // The allowlist is now empty, so the previously-valid account id
        // is no longer authorized — a revoked/renewed authorization must
        // take effect at SUBMIT time, not only at render time.
        $this->assertRejected(
            $panel,
            $world['firm'],
            [$world['matterA']['account']->id],
            'not authorized for this matter',
        );

        $this->assertNoSnapshotExists($world);
    }

    public function test_a_declined_client_consent_is_rejected(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel($world);

        $this->runWithFirmContext($world['firm'], fn () => $world['matterA']['consent']->update([
            'granted_at' => null,
            'declined_at' => now(),
        ]));

        $this->assertRejected(
            $panel,
            $world['firm'],
            [$world['matterA']['account']->id],
            'declined or not granted',
        );

        $this->assertNoSnapshotExists($world);
    }

    public function test_a_disconnected_connection_is_rejected(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel($world);

        $this->runWithFirmContext($world['firm'], fn () => $world['matterA']['connection']->update([
            'status' => ConnectionStatus::Disconnected->value,
            'disconnected_at' => now(),
        ]));

        $this->assertRejected(
            $panel,
            $world['firm'],
            [$world['matterA']['account']->id],
            'no longer active',
        );

        $this->assertNoSnapshotExists($world);
    }

    public function test_a_reauthorization_required_connection_is_rejected(): void
    {
        $world = $this->makeWorld();
        $panel = $this->mountedPanel($world);

        $this->runWithFirmContext($world['firm'], fn () => $world['matterA']['connection']->update([
            'status' => ConnectionStatus::ReauthorizationRequired->value,
        ]));

        $this->assertRejected(
            $panel,
            $world['firm'],
            [$world['matterA']['account']->id],
            'no longer active',
        );

        $this->assertNoSnapshotExists($world);
    }

    /**
     * A transaction outside the authorization's own date window must
     * never be pulled into the snapshot — the authorized range is
     * enforced at generation time, not merely recorded on the row.
     */
    public function test_transactions_outside_the_authorized_date_range_are_never_pulled_in(): void
    {
        $world = $this->makeWorld();

        $this->runWithFirmContext($world['firm'], fn () => $world['matterA']['authorization']->update([
            'authorized_date_range_start' => now()->subDays(3)->toDateString(),
            'authorized_date_range_end' => now()->addDay()->toDateString(),
        ]));

        $outOfRange = $this->runWithFirmContext($world['firm'], fn () => $this->makeTransaction(
            $world['firm'],
            $world['matterA']['account'],
            999_00,
            now()->subYear()->toDateString(),
        ));

        $panel = $this->mountedPanel($world);
        $this->generate($panel, $world['firm'], [$world['matterA']['account']->id]);

        $snapshot = $this->runWithFirmContext($world['firm'], fn () => FinancialEvidenceSnapshot::query()
            ->where('matter_id', $world['matterA']['matter']->id)
            ->first());

        $this->assertNotNull($snapshot);

        $referencedIds = array_column($snapshot->retrieved_record_refs_json, 'id');

        $this->assertContains($world['matterA']['transaction']->id, $referencedIds);
        $this->assertNotContains(
            $outOfRange->id,
            $referencedIds,
            'A transaction outside the authorized date range must never be baked into the snapshot.'
        );
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function makeWorld(): array
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();

        $matterA = $this->makeMatterWithEvidence($firm);
        $matterB = $this->makeMatterWithEvidence($firm);
        $otherFirmMatter = $this->makeMatterWithEvidence($otherFirm);

        $user = User::factory()->create();

        $this->runWithFirmContext($firm, fn () => FirmUser::factory()
            ->role(FirmUserRole::BillingStaff)
            ->forUser($user)
            ->create(['firm_id' => $firm->id]));

        $this->runWithFirmContext(
            $firm,
            fn () => MatterAssignment::factory()->forMatter($matterA['matter'])->forUser($user)->create(),
        );

        $this->actingAs($user);

        return [
            'firm' => $firm,
            'otherFirm' => $otherFirm,
            'matterA' => $matterA,
            'matterB' => $matterB,
            'otherFirmMatter' => $otherFirmMatter,
            'user' => $user,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeMatterWithEvidence(Firm $firm): array
    {
        return $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $connection = FirmIntegration::factory()->forFirm($firm)->create();
            $client = Client::query()->findOrFail($matter->client_id);

            $account = FinancialEvidenceBankAccount::query()->create([
                'firm_id' => $firm->id,
                'firm_integration_id' => $connection->id,
                'plaid_account_id' => 'acc_'.Str::random(12),
                'account_name' => 'Checking',
                'mask' => '9876',
                'raw_json' => [],
            ]);

            $consent = FinancialEvidenceClientConsent::query()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'matter_id' => $matter->id,
                'firm_integration_id' => $connection->id,
                'granted_products_json' => ['bank_account', 'transaction'],
                'granted_at' => now(),
            ]);

            $authorization = FinancialEvidenceMatterAuthorization::query()->create([
                'firm_id' => $firm->id,
                'matter_id' => $matter->id,
                'firm_integration_id' => $connection->id,
                'consent_id' => $consent->id,
            ]);

            return [
                'matter' => $matter,
                'client' => $client,
                'connection' => $connection,
                'account' => $account,
                'consent' => $consent,
                'authorization' => $authorization,
                'transaction' => $this->makeTransaction($firm, $account, 100_00, now()->toDateString()),
            ];
        });
    }

    private function makeTransaction(Firm $firm, FinancialEvidenceBankAccount $account, int $amountCents, string $date): FinancialEvidenceTransaction
    {
        return FinancialEvidenceTransaction::query()->create([
            'firm_id' => $firm->id,
            'firm_integration_id' => $account->firm_integration_id,
            'plaid_transaction_id' => 'txn_'.Str::random(16),
            'plaid_account_id' => $account->plaid_account_id,
            'bank_account_id' => $account->id,
            'amount_cents' => $amountCents,
            'transaction_date' => $date,
            'merchant_name' => 'Merchant',
            'pending' => false,
            'provider_retrieved_at' => now(),
            'raw_json' => [],
        ]);
    }

    private function mountedPanel(array $world): FinancialEvidenceSnapshotsPanel
    {
        $panel = new FinancialEvidenceSnapshotsPanel;

        $this->runWithFirmContext($world['firm'], fn () => $panel->mount($world['matterA']['matter']->id));

        return $panel;
    }

    /**
     * @param  array<int, int>  $bankAccountIds
     */
    private function generate(FinancialEvidenceSnapshotsPanel $panel, Firm $firm, array $bankAccountIds): void
    {
        $this->runWithFirmContext($firm, fn () => $this->invokePrivate($panel, 'generateSnapshot', [[
            'source_product' => 'transactions',
            'bank_account_ids' => $bankAccountIds,
            'limitations_text' => 'Test limitations.',
        ]]));
    }

    /**
     * @param  array<int, int>  $bankAccountIds
     */
    private function assertRejected(
        FinancialEvidenceSnapshotsPanel $panel,
        Firm $firm,
        array $bankAccountIds,
        string $expectedMessageFragment,
    ): void {
        try {
            $this->generate($panel, $firm, $bankAccountIds);
            $this->fail('Snapshot generation must be refused for an unauthorized submission, not silently accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedMessageFragment, $e->getMessage());
        }
    }

    private function assertNoSnapshotExists(array $world, string $message = 'No snapshot row may be written by a rejected request.'): void
    {
        $this->runWithFirmContext($world['firm'], function () use ($message): void {
            $this->assertSame(0, FinancialEvidenceSnapshot::query()->count(), $message);
        });
    }

    private function invokePrivate(object $target, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $args);
    }
}
