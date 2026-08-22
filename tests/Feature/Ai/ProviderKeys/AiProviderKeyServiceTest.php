<?php

namespace Tests\Feature\Ai\ProviderKeys;

use App\Enums\AiProvider;
use App\Enums\AiProviderKeyStatus;
use App\Models\FirmAiProviderKey;
use App\Models\User;
use App\Services\AiProviderKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * Project rules 4/5/6/7: firm-owned provider keys must be encrypted;
 * raw key returned once only; rotation marks old keys rotated, never
 * silently deletes; one active key per firm/provider (DB-level partial
 * unique index).
 */
class AiProviderKeyServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    public function test_generate_returns_the_raw_key_exactly_once_and_never_persists_it(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        $result = app(AiProviderKeyService::class)->generate($firm, AiProvider::OpenAi, $user);

        $this->assertStringStartsWith('aikey_', $result['rawKey']);
        $this->assertNotSame($result['rawKey'], $result['key']->encrypted_key_ciphertext ?? null);
        $this->assertDatabaseMissing('firm_ai_provider_keys', ['encrypted_key_ciphertext' => $result['rawKey']]);
    }

    public function test_generated_key_round_trips_through_the_encryption_chain(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        $service = app(AiProviderKeyService::class);
        $result = $service->generate($firm, AiProvider::OpenAi, $user);

        $decrypted = $service->keyMaterialFor($firm, $result['key']->fresh());

        $this->assertSame($result['rawKey'], $decrypted);
    }

    public function test_encrypted_ciphertext_is_hidden_from_array_and_json_serialization(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();

        $result = app(AiProviderKeyService::class)->generate($firm, AiProvider::OpenAi, $user);
        $key = $result['key']->fresh();

        $this->assertArrayNotHasKey('encrypted_key_ciphertext', $key->toArray());
        $this->assertStringNotContainsString('encrypted_key_ciphertext', $key->toJson());
    }

    public function test_rotation_marks_the_old_key_rotated_and_never_deletes_it(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();
        $service = app(AiProviderKeyService::class);

        $original = $service->generate($firm, AiProvider::OpenAi, $user)['key'];
        $rotated = $service->rotate($firm, $original->fresh(), $user);

        $this->assertDatabaseHas('firm_ai_provider_keys', [
            'id' => $original->id,
            'status' => AiProviderKeyStatus::Rotated->value,
        ]);
        $this->assertNotNull($original->fresh()->rotated_at);
        $this->assertSame(AiProviderKeyStatus::Active, $rotated['key']->status);
        $this->assertNotSame($original->id, $rotated['key']->id);
    }

    public function test_only_one_active_key_per_firm_per_provider_is_ever_possible(): void
    {
        $firm = $this->makeAiEntitledFirm();
        $user = User::factory()->create();
        $service = app(AiProviderKeyService::class);

        $service->generate($firm, AiProvider::OpenAi, $user);
        $service->generate($firm, AiProvider::Anthropic, $user);

        $activeCount = FirmAiProviderKey::query()
            ->where('firm_id', $firm->id)
            ->where('provider', AiProvider::OpenAi->value)
            ->where('status', AiProviderKeyStatus::Active->value)
            ->count();

        $this->assertSame(1, $activeCount);

        // Rotating creates a second row for the SAME provider — the
        // partial unique index must still allow exactly one active row.
        $original = FirmAiProviderKey::query()
            ->where('firm_id', $firm->id)
            ->where('provider', AiProvider::OpenAi->value)
            ->first();

        $service->rotate($firm, $original, $user);

        $this->assertSame(2, FirmAiProviderKey::query()->where('firm_id', $firm->id)->where('provider', AiProvider::OpenAi->value)->count());
        $this->assertSame(1, FirmAiProviderKey::query()->where('firm_id', $firm->id)->where('provider', AiProvider::OpenAi->value)->where('status', AiProviderKeyStatus::Active->value)->count());
    }

    /**
     * firm_ai_provider_keys has FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950015_prepare_row_level_security_and_force_rls_on_firm_ai_provider_keys_table.php).
     * makeAiEntitledFirm($firmB) leaves the PostgreSQL session context
     * ambiently held at firmB (FirmSettingsFactory's pre-existing
     * context-hold create() override never clears it) — a bare
     * $keyA->fresh() read at that point would run under firmB's
     * context, not firmA's, and RLS would correctly return no row for
     * firmA's own key. The explicit runWithFirmContext($firmA, ...)
     * wrap below makes the read genuinely belong to firmA's context
     * instead of relying on incidental ambient state, so $keyA is
     * actually re-read before being handed to rotate($firmB, ...) —
     * the real guarantee under test (rotate() rejects a key that does
     * not belong to the given firm) is otherwise unreachable, since a
     * null $existing would fail with a TypeError before ever reaching
     * that guard.
     */
    public function test_cannot_rotate_a_key_belonging_to_another_firm(): void
    {
        $firmA = $this->makeAiEntitledFirm();
        $firmB = $this->makeAiEntitledFirm();
        $user = User::factory()->create();
        $service = app(AiProviderKeyService::class);

        $keyA = $service->generate($firmA, AiProvider::OpenAi, $user)['key'];
        $keyA = $this->runWithFirmContext($firmA, fn () => $keyA->fresh());

        $this->expectException(\RuntimeException::class);
        $service->rotate($firmB, $keyA, $user);
    }
}
