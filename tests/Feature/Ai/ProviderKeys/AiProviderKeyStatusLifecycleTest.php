<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\ProviderKeys;

use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Enums\AiProviderKeyStatus;
use App\Models\Firm;
use App\Models\FirmAiProviderKey;
use App\Services\AiProviderKeyService;
use App\Services\AiProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * A credential is in exactly one of three states, and the difference between
 * two of them is a statement to the firm rather than a technical detail:
 *
 *   Active   currently usable
 *   Rotated  superseded — a successor credential exists
 *   Revoked  deliberately turned off — nothing replaced it
 *
 * Only Active is ever usable. Collapsing Revoked into Rotated would make the
 * settings page tell a firm that revoked its key that a replacement exists,
 * so these tests pin the distinction as well as the usability rule.
 */
class AiProviderKeyStatusLifecycleTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    private function keys(): AiProviderKeyService
    {
        return app(AiProviderKeyService::class);
    }

    private function resolver(): AiProviderResolver
    {
        return app(AiProviderResolver::class);
    }

    private function firmWithCredential(): Firm
    {
        $firm = $this->makeAiEntitledFirm(AiMode::FirmOwned);
        $this->keys()->import($firm, AiProvider::OpenAi, 'test-key-not-a-real-credential');

        return $firm->fresh(['firmSettings', 'aiSettings']);
    }

    /**
     * @return array<int, AiProviderKeyStatus>
     */
    private function statuses(Firm $firm): array
    {
        return $this->runWithFirmContext($firm, fn () => FirmAiProviderKey::query()
            ->where('firm_id', $firm->id)
            ->orderBy('id')
            ->pluck('status')
            ->all());
    }

    public function test_an_active_credential_resolves_to_a_usable_adapter(): void
    {
        $firm = $this->firmWithCredential();

        $this->assertTrue($this->resolver()->hasActiveCredential($firm));
        $this->assertNotNull($this->resolver()->adapterFor($firm));
        $this->assertNotNull($this->keys()->activeKeyFor($firm, AiProvider::OpenAi));
    }

    public function test_importing_a_replacement_supersedes_the_old_key_as_rotated(): void
    {
        $firm = $this->firmWithCredential();
        $original = $this->keys()->activeKeyFor($firm, AiProvider::OpenAi);

        $replacement = $this->keys()->import($firm, AiProvider::OpenAi, 'second-key-not-a-real-credential');

        $this->assertSame(
            [AiProviderKeyStatus::Rotated, AiProviderKeyStatus::Active],
            $this->statuses($firm),
            'A replacement is a rotation: the old key is superseded, not revoked.',
        );

        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame($replacement->id, $this->keys()->activeKeyFor($firm, AiProvider::OpenAi)->id);
    }

    public function test_a_rotated_key_is_never_used_as_the_active_one(): void
    {
        $firm = $this->firmWithCredential();
        $original = $this->keys()->activeKeyFor($firm, AiProvider::OpenAi);

        $this->keys()->import($firm, AiProvider::OpenAi, 'second-key-not-a-real-credential');

        $this->assertNotSame($original->id, $this->keys()->activeKeyFor($firm, AiProvider::OpenAi)->id);

        $rotated = $this->runWithFirmContext($firm, fn () => $original->fresh());
        $this->assertSame(AiProviderKeyStatus::Rotated, $rotated->status);
        $this->assertNotNull($rotated->rotated_at, 'A rotation records when it happened.');
    }

    public function test_revoking_marks_the_key_revoked_and_implies_no_replacement(): void
    {
        $firm = $this->firmWithCredential();

        $revoked = $this->keys()->revoke($firm, AiProvider::OpenAi);

        $this->assertSame(1, $revoked);
        $this->assertSame([AiProviderKeyStatus::Revoked], $this->statuses($firm), 'Revocation must not invent a successor key.');
        $this->assertNull($this->keys()->activeKeyFor($firm, AiProvider::OpenAi));
    }

    public function test_a_revoked_credential_leaves_the_firm_with_no_usable_provider(): void
    {
        $firm = $this->firmWithCredential();

        $this->keys()->revoke($firm, AiProvider::OpenAi);

        $this->assertFalse($this->resolver()->hasActiveCredential($firm));
        $this->assertNull($this->resolver()->adapterFor($firm), 'A revoked credential must not build an adapter.');
    }

    public function test_revocation_is_idempotent(): void
    {
        $firm = $this->firmWithCredential();

        $this->keys()->revoke($firm, AiProvider::OpenAi);
        $secondCall = $this->keys()->revoke($firm, AiProvider::OpenAi);

        $this->assertSame(0, $secondCall);
        $this->assertSame([AiProviderKeyStatus::Revoked], $this->statuses($firm));
    }

    public function test_active_key_lookup_ignores_both_non_active_states(): void
    {
        // The firm's full history: one rotated, one revoked, nothing usable.
        $firm = $this->firmWithCredential();
        $this->keys()->import($firm, AiProvider::OpenAi, 'second-key-not-a-real-credential');
        $this->keys()->revoke($firm, AiProvider::OpenAi);

        $this->assertSame(
            [AiProviderKeyStatus::Rotated, AiProviderKeyStatus::Revoked],
            $this->statuses($firm),
        );

        $this->assertNull($this->keys()->activeKeyFor($firm, AiProvider::OpenAi));
        $this->assertFalse($this->resolver()->hasActiveCredential($firm));
    }

    public function test_a_firm_can_recover_by_importing_again_after_revoking(): void
    {
        $firm = $this->firmWithCredential();
        $this->keys()->revoke($firm, AiProvider::OpenAi);

        $this->keys()->import($firm, AiProvider::OpenAi, 'third-key-not-a-real-credential');

        // The revoked row stays revoked — importing after a revocation does not
        // retroactively turn it into a rotation.
        $this->assertSame([AiProviderKeyStatus::Revoked, AiProviderKeyStatus::Active], $this->statuses($firm));
        $this->assertTrue($this->resolver()->hasActiveCredential($firm));
    }

    public function test_one_firms_revocation_does_not_disable_another_firms_credential(): void
    {
        $firmA = $this->firmWithCredential();
        $firmB = $this->firmWithCredential();

        $this->keys()->revoke($firmA, AiProvider::OpenAi);

        $this->assertFalse($this->resolver()->hasActiveCredential($firmA));
        $this->assertTrue($this->resolver()->hasActiveCredential($firmB));
    }
}
