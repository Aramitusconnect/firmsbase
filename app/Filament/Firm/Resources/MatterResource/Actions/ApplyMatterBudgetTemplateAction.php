<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterResource\Actions;

use App\Models\Firm;
use App\Models\Matter;
use App\Models\MatterBudgetTemplate;
use App\Services\MatterBudget\MatterBudgetAccessPolicyService;
use App\Services\MatterBudget\MatterBudgetService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * ApplyMatterBudgetTemplateAction — Predictive Matter Budget Alerts,
 * item 19. "Apply Budget Template" — offered, never silently applied
 * (the spec's own explicit instruction). Routes through
 * MatterBudgetService::applyTemplate(), which itself snapshots the
 * template rather than linking the Matter to a mutable row (see that
 * service's own docblock).
 */
class ApplyMatterBudgetTemplateAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'applyMatterBudgetTemplate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Apply Budget Template');
        $this->icon('heroicon-o-calculator');
        $this->schema([
            Select::make('template_id')
                ->label('Template')
                ->options(fn (Matter $record): array => MatterBudgetTemplate::query()
                    ->where('firm_id', $record->firm_id)
                    ->where('active', true)
                    ->pluck('name', 'id')->all())
                ->required()
                ->searchable(),
            Textarea::make('change_reason')
                ->label('Reason')
                ->rows(2)
                ->helperText('Required only if this matter already has a budget.'),
        ]);

        $this->visible(function (Matter $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null && app(MatterBudgetAccessPolicyService::class)->canReviseMatterBudget($firmUser->role);
        });

        $this->action(function (Matter $record, array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            try {
                app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, function () use ($record, $data, $firmUser) {
                    $firm = Firm::query()->findOrFail($firmUser->firm_id);
                    $template = MatterBudgetTemplate::query()->where('firm_id', $firm->id)->findOrFail($data['template_id']);

                    app(MatterBudgetService::class)->applyTemplate(
                        $firm, $record->fresh(), $template, $firmUser,
                        changeReason: $data['change_reason'] ?? null,
                    );
                });
            } catch (Throwable $e) {
                Notification::make()->title('Could not apply template')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Budget applied')->success()->send();
        });
    }
}
