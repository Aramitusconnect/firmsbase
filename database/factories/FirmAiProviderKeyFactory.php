<?php

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Enums\AiProviderKeyStatus;
use App\Models\Firm;
use App\Models\FirmAiProviderKey;
use App\Models\TenantEncryptionKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirmAiProviderKey>
 *
 * NOTE: definition() below does NOT produce a genuinely decryptable
 * ciphertext (not routed through EmailBodyEncryptionService) — usable
 * only for tests that don't need to decrypt. Tests exercising real
 * encrypt/decrypt round-trips must go through
 * AiProviderKeyService::generate()/rotate() against a firm with an
 * active TenantEncryptionKey, matching WebhookSecretFactory's exact
 * convention.
 *
 * firm_ai_provider_keys has TWO tenant-scoped foreign keys (firm_id AND
 * encryption_key_id -> tenant_encryption_keys.firm_id) — like
 * matter_expenses (see database/factories/MatterExpenseFactory.php),
 * NOT like firm_ai_settings (which has only firm_id). definition()
 * therefore resolves ONE authoritative Firm::factory()->create() up
 * front and derives BOTH firm_id and encryption_key_id from that same
 * firm, rather than letting two independent factory calls each pick
 * their own unrelated firm — the exact root-cause fix already applied
 * to MatterExpenseFactory/MatterFactory/InvoiceFactory/PaymentFactory.
 * A bare row whose encryption_key_id belongs to a different firm than
 * firm_id is exactly the transitive cross-firm mismatch documented as
 * a known, deliberately-deferred gap in this table's FORCE migration
 * (no composite FK/trigger enforces it at the database layer) — the
 * factory must not manufacture that invalid shape by default just
 * because RLS itself cannot catch it.
 *
 * Per the approved design, this factory deliberately has NO
 * context-hold create() override of its own (unlike
 * TenantEncryptionKeyFactory's own create() override) — the intent,
 * matching the firm_ai_settings precedent, is that a bare
 * FirmAiProviderKey::factory()->create() should fail closed under
 * FORCE rather than silently succeed.
 *
 * FLAGGED, UNRESOLVED RISK (verified against Laravel's own Factory
 * internals, NOT empirically tested — this implementation pass has no
 * authority to run the test suite; rls-test-verifier must confirm or
 * refute this before relying on "bare create() fails closed" in any
 * assertion): Illuminate\Database\Eloquent\Factories\Factory::expandAttributes()
 * resolves a nested Factory instance appearing directly in definition()
 * (as encryption_key_id does here) by calling ->create() on it
 * SYNCHRONOUSLY, before the parent row is ever persisted (see
 * Factory::create() -> make() -> getExpandedAttributes() ->
 * expandAttributes(), and Factory::store() running strictly
 * afterward). TenantEncryptionKeyFactory::create() is itself a
 * context-hold override: it calls setDatabaseTenantContextForFirmId()
 * for the key's OWN firm_id and never restores/clears it. Because this
 * fix ties encryption_key_id's firm to the SAME $firm as this row's own
 * firm_id, that ambient PostgreSQL session/transaction-local setting
 * left behind by the nested key's creation would, by the time this
 * row's own INSERT runs, already equal this row's own firm_id — which
 * would satisfy the firm_ai_provider_keys_tenant_isolation policy's
 * WITH CHECK clause even though NOTHING in THIS factory (or the
 * caller) ever deliberately established context for this row. That is
 * a materially different, weaker guarantee than "fails closed": it
 * would be an accidental pass riding on an unrelated factory's own
 * convention, not a deliberate per-row context decision — and it
 * directly contradicts the "bare create() must continue to fail
 * closed" expectation this fix was asked to preserve. This risk is
 * deliberately NOT papered over with an unverified docblock claim; the
 * corresponding regression test (bare factory create() without an
 * explicit runWithFirmContext()/forFirm() wrap, against a freshly
 * cleared context) must be run for real before anyone treats "fails
 * closed" as confirmed for this table.
 */
class FirmAiProviderKeyFactory extends Factory
{
    protected $model = FirmAiProviderKey::class;

    public function definition(): array
    {
        $firm = Firm::factory()->create();

        return [
            'firm_id' => $firm->id,
            'provider' => AiProvider::OpenAi,
            'encrypted_key_ciphertext' => 'placeholder-ciphertext-not-real',
            'encryption_key_id' => TenantEncryptionKey::factory()->forFirm($firm),
            'status' => AiProviderKeyStatus::Active,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'encryption_key_id' => TenantEncryptionKey::factory()->forFirm($firm),
        ]);
    }

    public function rotated(): static
    {
        return $this->state(fn () => [
            'status' => AiProviderKeyStatus::Rotated,
            'rotated_at' => now(),
        ]);
    }
}
