<?php

namespace Database\Factories;

use App\Enums\PdfViewEventAction;
use App\Enums\PdfViewerViewerType;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PdfViewEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PdfViewEvent>
 */
class PdfViewEventFactory extends Factory
{
    protected $model = PdfViewEvent::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'viewer_type' => PdfViewerViewerType::FirmUser->value,
            'viewer_firm_user_id' => fn (array $attributes) => FirmUser::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'source_document_type' => SignatureSourceDocumentType::Document->value,
            'document_id' => fn (array $attributes) => Document::factory()->create(['firm_id' => $attributes['firm_id']])->id,
            'action' => PdfViewEventAction::Opened->value,
            'occurred_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'viewer_firm_user_id' => FirmUser::factory()->create(['firm_id' => $firm->id])->id,
            'document_id' => Document::factory()->create(['firm_id' => $firm->id])->id,
        ]);
    }

    public function action(PdfViewEventAction $action): static
    {
        return $this->state(fn () => ['action' => $action->value]);
    }
}
