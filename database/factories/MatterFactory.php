<?php

namespace Database\Factories;

use App\Enums\MatterStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\PracticeArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Matter>
 */
class MatterFactory extends Factory
{
    protected $model = Matter::class;

    public function definition(): array
    {
        $practiceArea = PracticeArea::factory()->create();

        return [
            'firm_id' => Firm::factory(),
            'client_id' => Client::factory(),
            'primary_practice_area_id' => $practiceArea->id,
            'matter_type_id' => MatterType::factory()->forPracticeArea($practiceArea),
            'pinned_template_pack_version_id' => null,
            'status' => MatterStatus::Draft,
            'stage' => null,
            'assigned_attorney_id' => null,
            'opened_at' => null,
            'closed_at' => null,
        ];
    }

    /**
     * Ties both the matter AND its nested client to the given firm —
     * the plain definition() above creates an independent random
     * client via Client::factory(), which would otherwise leave the
     * client on a different firm than the matter.
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'client_id' => Client::factory()->forFirm($firm),
        ]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => ['firm_id' => $client->firm_id, 'client_id' => $client->id]);
    }

    public function status(MatterStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
