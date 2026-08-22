<?php

namespace Database\Factories;

use App\Enums\PdfViewerViewerType;
use App\Enums\PdfViewEventAction;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PdfViewEvent;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<PdfViewEvent>
 */
class PdfViewEventFactory extends Factory
{
    protected $model = PdfViewEvent::class;

    /**
     * pdf_view_events has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950034_prepare_row_level_security_
     * and_force_rls_on_pdf_view_events_table.php), so every INSERT (test
     * or app) must run under the row's own app.current_firm_id context.
     * See MatterExpenseFactory::create()'s docblock for the full
     * rationale, including why setDatabaseTenantContextForFirmId() is
     * used instead of setFirmContext()/runWithFirmContext() and why the
     * setting is deliberately left active rather than cleared.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);

        $models = $results instanceof Model ? new Collection([$results]) : $results;

        $service = new TenantContextService;

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
