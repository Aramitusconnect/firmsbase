<?php

namespace Database\Factories;

use App\Enums\AiMode;
use App\Enums\PaymentMode;
use App\Enums\TwoFactorMode;
use App\Models\Firm;
use App\Models\FirmSettings;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<FirmSettings>
 */
class FirmSettingsFactory extends Factory
{
    protected $model = FirmSettings::class;

    /**
     * Section 39A-3L, Checkpoint 18 — context-hold pattern (matching
     * every prior FORCE-RLS factory since 39A-3A): groups resolved
     * models by firm_id and activates the matching PostgreSQL session
     * context per group before inserting, so a bare
     * FirmSettings::factory()->create() works correctly even called
     * from outside any already-active tenant context.
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
            'payment_mode' => PaymentMode::OperatingPaymentsOnly,
            'trust_iolta_protection' => true,
            'ai_mode' => AiMode::Disabled,
            'client_2fa_mode' => TwoFactorMode::Optional,
            'portal_frontend_mode' => null,
            'state_jurisdiction' => null,
            'default_language' => 'en',
            'branding_settings_json' => [],
            'security_settings_json' => [],
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }
}
