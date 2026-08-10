<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MatterBudgetTemplateResource\Actions;

use App\Models\Firm;
use App\Models\MatterBudgetTemplate;
use App\Services\MatterBudget\MatterBudgetTemplateService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * DuplicateMatterBudgetTemplateAction — item 18's own explicit
 * "duplicate" requirement, routed through
 * MatterBudgetTemplateService::duplicate() (never a bare Eloquent
 * replicate()) so the copy goes through the exact same save-time
 * validation and authorization gate as any other template create.
 */
class DuplicateMatterBudgetTemplateAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'duplicateMatterBudgetTemplate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Duplicate');
        $this->icon('heroicon-o-document-duplicate');
        $this->schema([
            TextInput::make('name')->label('New template name')->required(),
        ]);

        $this->action(function (MatterBudgetTemplate $record, array $data): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            try {
                app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    fn () => app(MatterBudgetTemplateService::class)->duplicate(
                        Firm::query()->findOrFail($firmUser->firm_id),
                        $record,
                        $firmUser,
                        $data['name'],
                    ),
                );
            } catch (Throwable $e) {
                Notification::make()->title('Could not duplicate')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Template duplicated')->success()->send();
        });
    }
}
