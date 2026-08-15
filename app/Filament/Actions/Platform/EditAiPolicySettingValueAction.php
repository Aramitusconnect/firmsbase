<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\AiPolicySetting;
use App\Models\PlatformAdmin;
use App\Services\AiPolicySettingService;
use App\Services\Configuration\AiPolicyDefinitionRegistry;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * EditAiPolicySettingValueAction — AiPolicySettingResource's row
 * action. Value is edited as raw JSON text (this table's own column is
 * `value_json`, an arbitrary/nested policy config blob — there is no
 * fixed schema to build a structured form against, and inventing one
 * would silently constrain what this table is allowed to hold). The
 * submitted text is strictly json_decode()-validated before being
 * handed to AiPolicySettingService::set() — a malformed submission is
 * rejected with a validation error, never silently coerced or stored
 * as a raw string.
 *
 * Calls AiPolicySettingService::set($key, $value, $actor) — the actor
 * is always supplied here (this is the one and only UI call site), so
 * every edit through this action is always audited.
 */
class EditAiPolicySettingValueAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'editAiPolicySettingValue';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Edit Value');
        $this->icon(Heroicon::OutlinedPencilSquare);
        $this->color('primary');

        $this->fillForm(fn (AiPolicySetting $record): array => [
            'value_json' => json_encode($record->value_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);

        // A governed key has a dedicated, safer control elsewhere and is
        // not editable here at all. Hiding the action is a courtesy —
        // AiPolicySettingService refuses the write regardless.
        $this->visible(fn (AiPolicySetting $record): bool => ! app(AiPolicyDefinitionRegistry::class)->isGoverned($record->key));

        $this->schema([
            Placeholder::make('key_status')
                ->label('About this key')
                ->content(function (AiPolicySetting $record): string {
                    $registry = app(AiPolicyDefinitionRegistry::class);

                    return $registry->isRecognized($record->key)
                        ? ($registry->find($record->key)['description'] ?? '')
                        : 'This key is not read by any service in this codebase. Editing it changes stored data only — it has no known effect on platform behaviour.';
                }),
            Textarea::make('value_json')
                ->label('Value (JSON)')
                ->required()
                ->rows(8)
                ->helperText('Must be valid JSON — this is validated before saving.'),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Edit AI Policy Setting Value');

        $this->action(function (AiPolicySetting $record, array $data, AiPolicySettingService $settingService, PlatformStaffAccessPolicyService $accessPolicy): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            if (! $accessPolicy->canManageAiPolicySettings($admin)->allowed) {
                Notification::make()->title('You are not authorized to manage AI policy settings.')->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($admin);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $decoded = json_decode((string) ($data['value_json'] ?? ''), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Notification::make()->title('Invalid JSON')->body(json_last_error_msg())->danger()->send();

                return;
            }

            $fresh = AiPolicySetting::query()->find($record->getKey());

            if ($fresh === null) {
                Notification::make()->title('That AI policy setting could not be found.')->danger()->send();

                return;
            }

            try {
                $updated = $settingService->set($fresh->key, $decoded, $admin);
            } catch (\InvalidArgumentException $e) {
                // Covers both a governed key (which must be operated
                // from its own control) and a value whose type does not
                // match what the key's real consumer expects.
                Notification::make()->title('Could not update AI policy setting')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()
                ->title('AI policy setting updated')
                ->body("Key: {$updated->key}.")
                ->success()
                ->send();
        });
    }
}
