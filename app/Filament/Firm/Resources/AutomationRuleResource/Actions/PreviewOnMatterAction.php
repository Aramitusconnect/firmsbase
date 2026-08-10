<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\AutomationRuleResource\Actions;

use App\Models\AutomationRule;
use App\Models\Matter;
use App\Services\Automation\WorkflowPreviewService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * PreviewOnMatterAction — Zero-Click Core Workflow Automation, item
 * 24. Calls WorkflowPreviewService only (read-only, never mutates a
 * record) and surfaces the result via a Notification — no new page,
 * reusing the existing Automation UI's own row-action shape
 * (DuplicateMatterBudgetTemplateAction's precedent).
 */
class PreviewOnMatterAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'previewOnMatter';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Preview');
        $this->icon('heroicon-o-eye');
        $this->schema([
            Select::make('matter_id')
                ->label('Matter')
                ->options(fn (): array => Matter::query()
                    ->with('client')
                    ->limit(200)
                    ->get()
                    ->mapWithKeys(fn (Matter $matter): array => [
                        $matter->id => trim(($matter->client?->display_name ?? 'Matter').' — #'.$matter->id),
                    ])
                    ->all())
                ->searchable()
                ->required(),
        ]);

        $this->action(function (AutomationRule $record, array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $record->firm_id !== (int) $firmUser->firm_id) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            $matter = Matter::query()->where('firm_id', $firmUser->firm_id)->find($data['matter_id']);

            if ($matter === null) {
                Notification::make()->title('Matter not found')->danger()->send();

                return;
            }

            $preview = app(WorkflowPreviewService::class)->previewForMatter($record, $matter);

            if (! $preview['would_match']) {
                Notification::make()
                    ->title('This rule would not fire')
                    ->body($preview['blocked_reason'] ?? 'Conditions not met.')
                    ->warning()
                    ->persistent()
                    ->send();

                return;
            }

            $body = implode("\n", $preview['actions']);
            $body .= $preview['requires_approval'] ? "\n\nThis rule requires human approval before its actions run." : '';

            Notification::make()
                ->title('This rule would perform:')
                ->body($body)
                ->success()
                ->persistent()
                ->send();
        });
    }
}
