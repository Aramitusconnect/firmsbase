<?php

namespace Database\Factories;

use App\Enums\OffboardingExportStatus;
use App\Models\OffboardingExport;
use App\Models\OffboardingRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OffboardingExport>
 */
class OffboardingExportFactory extends Factory
{
    protected $model = OffboardingExport::class;

    public function definition(): array
    {
        return [
            'offboarding_request_id' => OffboardingRequest::factory(),
            'deletion_request_id' => null,
            'export_job_id' => null,
            'status' => OffboardingExportStatus::Pending,
            'package_manifest_json' => null,
        ];
    }

    public function forOffboardingRequest(OffboardingRequest $request): static
    {
        return $this->state(fn () => ['offboarding_request_id' => $request->id]);
    }

    public function verified(): static
    {
        return $this->state(fn () => [
            'status' => OffboardingExportStatus::Verified,
            'package_manifest_json' => ['clients', 'matters', 'documents', 'invoices', 'trust_ledger_entries_summary', 'timeline_events'],
            'generated_at' => now(),
            'verified_at' => now(),
        ]);
    }
}
