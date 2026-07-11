<?php

namespace Database\Factories;

use App\Enums\TenantEncryptionKeyStatus;
use App\Models\Firm;
use App\Models\TenantEncryptionKey;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<TenantEncryptionKey>
 */
class TenantEncryptionKeyFactory extends Factory
{
    protected $model = TenantEncryptionKey::class;

    /**
     * Section 39A-3L, Checkpoint 16 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * TenantEncryptionKey::factory()->create() works correctly even
     * called from outside any already-active tenant context. Unlike
     * several prior checkpoints' factories, this table has no second
     * tenant-scoped foreign key (only firm_id) — Firm::factory() alone
     * cannot produce a cross-firm mismatch, so definition() itself
     * needs no change, only this create() override.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);
        $models = $results instanceof Model ? new Collection([$results]) : $results;
        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'key_version' => 1,
            'status' => TenantEncryptionKeyStatus::Active,
            'encrypted_key' => fn () => Crypt::encryptString(base64_encode(random_bytes(32))),
            'destroyed_at' => null,
            'destruction_request_id' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function rotated(): static
    {
        return $this->state(fn () => ['status' => TenantEncryptionKeyStatus::Rotated]);
    }

    public function destroyed(): static
    {
        return $this->state(fn () => [
            'status' => TenantEncryptionKeyStatus::Destroyed,
            'destroyed_at' => now(),
        ]);
    }
}
