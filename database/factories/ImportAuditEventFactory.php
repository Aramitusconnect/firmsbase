<?php

namespace Database\Factories;

use App\Enums\ImportAuditEventType;
use App\Models\ImportAuditEvent;
use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportAuditEvent>
 */
class ImportAuditEventFactory extends Factory
{
    protected $model = ImportAuditEvent::class;

    public function definition(): array
    {
        return [
            'import_batch_id' => ImportBatch::factory(),
            'event_type' => ImportAuditEventType::BatchCreated->value,
        ];
    }
}
