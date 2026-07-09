<?php

namespace Database\Factories;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'matter_id' => null,
            'client_id' => null,
            'document_request_item_id' => null,
            'status' => DocumentStatus::Uploaded,
            'scan_status' => DocumentScanStatus::Pending,
            'scan_result_detail' => null,
            'scanned_at' => null,
            'storage_disk' => 'local',
            'storage_path' => 'documents/'.$this->faker->uuid().'.pdf',
            'original_filename' => $this->faker->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(1024, 5_000_000),
            'file_hash' => hash('sha256', $this->faker->uuid()),
            'encryption_key_id' => null,
            'uploaded_by' => null,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_reason' => null,
            'replaces_document_id' => null,
            'expires_at' => null,
        ];
    }

    public function clean(): static
    {
        return $this->state(fn () => [
            'scan_status' => DocumentScanStatus::Clean,
            'scanned_at' => now(),
        ]);
    }

    public function infected(): static
    {
        return $this->state(fn () => [
            'scan_status' => DocumentScanStatus::Infected,
            'scanned_at' => now(),
            'status' => DocumentStatus::Rejected,
            'rejected_reason' => 'Virus scan detected malware: eicar-test-signature',
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'scan_status' => DocumentScanStatus::Clean,
            'scanned_at' => now(),
            'status' => DocumentStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    /**
     * Section 39A-3C — documents has FORCE ROW LEVEL SECURITY active,
     * so every INSERT (test or app) must run under the row's own
     * app.current_firm_id context. Same pattern as ClientFactory
     * (Section 39A-3A) and FirmUserFactory (Section 39A-3B): reads the
     * firm_id the factory itself already resolved, sets the
     * PostgreSQL session setting only (never PHP-memory
     * TenantContextResolver state, which BelongsToTenant's global
     * scope reads — leaving that active would leak an implicit
     * firm_id constraint into unrelated queries), and deliberately
     * leaves it set afterward for the common "create then read" test
     * pattern.
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
}
