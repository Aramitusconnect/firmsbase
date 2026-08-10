<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\AutomationRuleResource\Pages;

use App\Filament\Firm\Resources\AutomationRuleResource;
use App\Models\Firm;
use App\Services\Automation\AutomationTemplateCatalog;
use App\Services\Automation\AutomationTemplateInstallService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

/**
 * ListAutomationRules — Event-Driven Automation Engine, item 16. The
 * "Install Starter Template" header action turns a selection from
 * AutomationTemplateCatalog into completely normal, inspectable,
 * editable, disableable AutomationRule rows (AutomationTemplateInstallService,
 * which itself routes through AutomationRuleService — never a hidden
 * hardcoded workflow a firm user cannot see or turn off).
 */
class ListAutomationRules extends ListRecords
{
    protected static string $resource = AutomationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('installTemplates')
                ->label('Install Starter Template')
                ->icon('heroicon-o-sparkles')
                ->schema([
                    CheckboxList::make('templates')
                        ->label('Templates')
                        ->options(fn (): array => collect(AutomationTemplateCatalog::templates())
                            ->mapWithKeys(fn (array $template, string $key): array => [$key => "{$template['name']} — {$template['description']}"])
                            ->all())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $firmUser = Auth::user()?->activeFirmUser();

                    if ($firmUser === null) {
                        Notification::make()->title('Not permitted')->danger()->send();

                        return;
                    }

                    app(TenantContextService::class)->runWithFirmContext((int) $firmUser->firm_id, function () use ($data, $firmUser) {
                        $firm = Firm::query()->findOrFail($firmUser->firm_id);
                        $installer = app(AutomationTemplateInstallService::class);

                        foreach ($data['templates'] as $templateKey) {
                            $installer->install($firm, $firmUser, $templateKey);
                        }
                    });

                    Notification::make()->title('Template(s) installed')->success()->send();
                }),
            CreateAction::make(),
        ];
    }
}
