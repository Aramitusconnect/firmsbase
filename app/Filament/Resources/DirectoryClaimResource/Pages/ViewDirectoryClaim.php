<?php

declare(strict_types=1);

namespace App\Filament\Resources\DirectoryClaimResource\Pages;

use App\Filament\Actions\Platform\ApproveDirectoryClaimAction;
use App\Filament\Actions\Platform\MarkClaimUnderReviewAction;
use App\Filament\Actions\Platform\RejectDirectoryClaimAction;
use App\Filament\Actions\Platform\RequireClaimEvidenceAction;
use App\Filament\Actions\Platform\RevokeDirectoryClaimAction;
use App\Filament\Resources\DirectoryClaimResource;
use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Models\DirectoryClaim;
use App\Services\CanonicalUrlService;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * ViewDirectoryClaim — MyAttorney SuperAdmin console professionalization
 * mission (MYAT4). Upgraded from a flat field list into a real review
 * workspace (Listing / Claimant / Evidence / Decision History), per
 * this mission's own spec section 6A, using data that already exists
 * on DirectoryClaim/DirectoryFirm/FirmUser — no new schema.
 *
 * "Evidence" is honestly text-only (claim_basis submitted by the
 * claimant, reviewer_notes recorded via Request More Information) —
 * this mission's own discovery pass confirmed there is deliberately no
 * file-upload evidence column on directory_claims (see that table's
 * migration docblock), so this section does not fabricate a document
 * list that doesn't exist.
 *
 * MyAttorney final hardening mission, finding 1: `claimant` (a
 * FirmUser) and everything reached through it (`.user.name`,
 * `.user.email`, `.role`) is genuinely, correctly unresolvable in this
 * PlatformAdmin context — firm_users carries permanent FORCE RLS, and
 * its own self-lookup policy only matches `app.current_user_id`
 * against a real authenticated Firm user's session, never a
 * PlatformAdmin's. Preserved conclusion from the prior reconciliation
 * report: CLAIMANT_IDENTITY_LIMITED_BUT_ACCEPTABLE — the Claiming
 * Firm's real legal name, the claim basis, and the full decision
 * history all resolve correctly and are enough to review a claim. The
 * fix here is UI honesty only, never an RLS bypass: the three
 * claimant-identity fields below show CLAIMANT_IDENTITY_RESTRICTED_NOTE
 * (with a tooltip explaining why) instead of the generic '—' every
 * other genuinely-empty field on this page uses, so "architecturally
 * unavailable" is never visually indistinguishable from "left blank."
 */
class ViewDirectoryClaim extends ViewRecord
{
    protected static string $resource = DirectoryClaimResource::class;

    private const CLAIMANT_IDENTITY_RESTRICTED_NOTE = 'Restricted by tenant isolation';

    private const CLAIMANT_IDENTITY_TOOLTIP = 'Claimant user identity is tenant-protected and is not exposed through the platform marketplace review context.';

    protected function getHeaderActions(): array
    {
        return [
            MarkClaimUnderReviewAction::make(),
            RequireClaimEvidenceAction::make(),
            ApproveDirectoryClaimAction::make(),
            RejectDirectoryClaimAction::make(),
            RevokeDirectoryClaimAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Listing')
                ->columns(2)
                ->schema([
                    TextEntry::make('directoryFirm.display_name')->label('Firm/Listing'),
                    TextEntry::make('directoryFirm.phone')->label('Phone')->placeholder('—'),
                    TextEntry::make('directoryFirm.website')->label('Website')->placeholder('—'),
                    TextEntry::make('directoryFirm.source_type')
                        ->label('Source/Provenance')
                        ->formatStateUsing(fn ($state) => $state !== null ? Str::headline($state->value) : '—'),
                    TextEntry::make('public_profile')
                        ->label('Public Profile')
                        ->state(fn (DirectoryClaim $record) => app(CanonicalUrlService::class)->myAttorneyFirmUrl($record->directoryFirm->slug))
                        ->url(fn (DirectoryClaim $record) => app(CanonicalUrlService::class)->myAttorneyFirmUrl($record->directoryFirm->slug))
                        ->openUrlInNewTab(),
                ]),
            Section::make('Claimant')
                ->columns(2)
                ->schema([
                    TextEntry::make('claimant.user.name')
                        ->label('Claimant Name')
                        ->placeholder(self::CLAIMANT_IDENTITY_RESTRICTED_NOTE)
                        ->tooltip(self::CLAIMANT_IDENTITY_TOOLTIP),
                    TextEntry::make('claimant.user.email')
                        ->label('Claimant Email')
                        ->placeholder(self::CLAIMANT_IDENTITY_RESTRICTED_NOTE)
                        ->tooltip(self::CLAIMANT_IDENTITY_TOOLTIP),
                    TextEntry::make('firm.legal_name')->label('Claiming Firm'),
                    TextEntry::make('claimant.role')
                        ->label('Role/Relationship')
                        ->formatStateUsing(fn ($state) => $state !== null ? Str::headline($state->value) : self::CLAIMANT_IDENTITY_RESTRICTED_NOTE)
                        ->tooltip(self::CLAIMANT_IDENTITY_TOOLTIP),
                ]),
            Section::make('Evidence')
                ->schema([
                    TextEntry::make('claim_basis')->label('Claim Basis (submitted by claimant)')->columnSpanFull()->placeholder('None provided.'),
                    TextEntry::make('reviewer_notes')->label('Reviewer Notes / Information Requested')->columnSpanFull()->placeholder('—'),
                ]),
            Section::make('Decision History')
                ->columns(2)
                ->schema([
                    TextEntry::make('state')->badge()->formatStateUsing(fn (ClaimState $state) => Str::headline($state->value)),
                    TextEntry::make('submitted_at')->dateTime(),
                    TextEntry::make('decided_at')->dateTime()->placeholder('Not yet decided'),
                    TextEntry::make('decidedBy.name')->label('Decided By')->placeholder('—'),
                    TextEntry::make('rejection_reason')->label('Rejection Reason')->placeholder('—'),
                    TextEntry::make('revocation_reason')->label('Revocation Reason')->placeholder('—'),
                    TextEntry::make('conflictsWith.id')->label('Conflicts With Claim #')->placeholder('—'),
                ]),
        ]);
    }
}
