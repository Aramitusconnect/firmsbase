<?php

declare(strict_types=1);

namespace App\Filament\Resources\FirmResource\Pages;

use App\Filament\Resources\FirmResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * EditFirm — CORE SuperAdmin mission, section 19. FirmResource
 * previously had NO Create/Edit at all (see that Resource's own
 * docblock). This adds exactly ONE narrow safe-metadata edit form —
 * never a generic "edit every column" form.
 *
 * SAFE_OPERATOR_METADATA vs SYSTEM_MANAGED classification, decided by
 * reading Firm's own fillable list and docblock directly:
 *  - EDITABLE here: name, legal_name, primary_country, primary_state,
 *    default_timezone, default_currency — display/administrative
 *    metadata with no infrastructure or commercial side effect.
 *  - NEVER editable here: organization_id, billing_account_id
 *    (system identifiers), customer_type, deployment_mode, data_region
 *    (provisioning-time classification decisions with real
 *    infrastructure/compliance weight — never a casual form edit),
 *    activation_status (a lifecycle state, mutated only through its
 *    own dedicated actions/services, never a raw field write).
 *  - address_line1/address_line2/city/postal_code/phone_number are
 *    ALSO deliberately excluded — Firm's own class docblock states
 *    those five columns are "Edited exclusively through the firm-panel
 *    FirmSettingsPage (FirmOwner-only), never through a generic
 *    Resource form" — this SuperAdmin edit respects that existing,
 *    explicit architectural decision rather than adding a second write
 *    path to FirmOwner-owned self-service fields.
 *
 * Authorization: FirmResource::canEdit() gates this page via
 * PlatformStaffAccessPolicyService::canManageFirms() (see that
 * Resource's own override).
 *
 * Audit: every save writes a `firm_metadata_updated` security_events
 * row via the canonical PlatformAdminAuditEventRecorder::record() (the
 * Firm itself is the natural, correct scope for its own audit event —
 * no null-firm fallback needed here) recording exactly which fields
 * changed, never a raw before/after blob of the whole record.
 */
class EditFirm extends EditRecord
{
    protected static string $resource = FirmResource::class;

    private const EDITABLE_FIRM_FIELDS = [
        'name',
        'legal_name',
        'primary_country',
        'primary_state',
        'default_timezone',
        'default_currency',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Firm')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('legal_name')->maxLength(255),
                    TextInput::make('primary_country')->label('Country')->maxLength(2)->helperText('2-letter country code.'),
                    TextInput::make('primary_state')->label('State/Province')->maxLength(255),
                    TextInput::make('default_timezone')->label('Timezone')->maxLength(255),
                    Select::make('default_currency')
                        ->label('Currency')
                        ->options(['USD' => 'USD', 'CAD' => 'CAD', 'GBP' => 'GBP', 'EUR' => 'EUR'])
                        ->native(false),
                ]),
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Firm $record */
        $admin = Auth::guard('platform_admin')->user();
        abort_unless($admin instanceof PlatformAdmin, 403);

        $attributes = array_intersect_key($data, array_flip(self::EDITABLE_FIRM_FIELDS));

        $changed = [];
        foreach ($attributes as $field => $value) {
            if ($record->{$field} !== $value) {
                $changed[$field] = $value;
            }
        }

        $record->update($attributes);

        if ($changed !== []) {
            app(PlatformAdminAuditEventRecorder::class)->record(
                $record,
                $admin,
                'firm_metadata_updated',
                'platform_administration',
                ['firm_id' => $record->id, 'changed_fields' => array_keys($changed)],
            );
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return FirmResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
