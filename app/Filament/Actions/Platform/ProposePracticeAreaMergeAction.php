<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\Configuration\PracticeAreaMergeProposalService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * ProposePracticeAreaMergeAction — builds and displays the mission
 * section 36 merge evidence package for a candidate pair. It is a
 * READ-ONLY analysis action.
 *
 * IT CANNOT EXECUTE A MERGE, BY CONSTRUCTION. There is no merge
 * execution path anywhere in this codebase to call — no canonical
 * practice-area merge service has ever been written, and
 * PracticeAreaMergeProposalService deliberately has no execute()/
 * merge() method rather than a flag-guarded one. Mission sections
 * 36/96 make owner approval unconditional for any real existing-data
 * merge, and the safest expression of that is a capability that simply
 * does not exist: adding it later requires a deliberate code change,
 * which is exactly the review checkpoint the approval gate is for.
 *
 * The optional per-firm impact scan is opt-in because it is
 * O(number of firms) — see PracticeAreaDependencyAnalysisService. It is
 * the only way to count tenant-owned references honestly, since those
 * tables are FORCE-RLS protected and a platform session's count of them
 * would silently read 0.
 */
class ProposePracticeAreaMergeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'proposePracticeAreaMerge';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Propose merge');
        $this->icon(Heroicon::OutlinedArrowsPointingIn);
        $this->color('gray');

        $this->modalHeading('Propose a practice area merge');
        $this->modalDescription(
            'Builds the evidence package required before any merge could be considered. '
            .'This action never merges anything — merging real existing data requires separate owner approval, '
            .'and no merge execution path exists in this codebase.'
        );
        $this->modalSubmitActionLabel('Build proposal');

        $this->schema([
            Select::make('target_id')
                ->label('Merge this practice area INTO')
                ->helperText('The surviving canonical practice area. The record you are viewing would be the source.')
                ->required()
                ->searchable()
                ->native(false)
                ->options(fn (PracticeArea $record): array => PracticeArea::query()
                    ->whereKeyNot($record->getKey())
                    ->orderBy('name')
                    ->get(['id', 'name', 'code'])
                    ->mapWithKeys(fn (PracticeArea $pa): array => [$pa->id => $pa->name.' ('.$pa->code.')'])
                    ->all()),
            Toggle::make('scan_tenant_scoped')
                ->label('Include per-firm impact scan')
                ->helperText('Counts matters, firm enablements, budget templates and marketplace intakes across firms. Required for a complete picture, but scans every firm — slower on a large platform.')
                ->default(false),
            Placeholder::make('execution_notice')
                ->hiddenLabel()
                ->content('This produces a proposal only. Nothing is written, reassigned, deactivated or deleted.'),
        ]);

        $this->action(function (array $data, PracticeArea $record, PlatformStaffAccessPolicyService $accessPolicy): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            // Read-only, but still gated: dependency and usage counts
            // across the platform are not information every staff role
            // should be able to enumerate.
            $decision = $accessPolicy->canManagePracticeAreaCatalog($admin);

            if (! $decision->allowed) {
                Notification::make()->title('Not permitted')->body($decision->reason)->danger()->send();

                return;
            }

            $target = PracticeArea::query()->find($data['target_id'] ?? null);

            if ($target === null) {
                Notification::make()->title('That target practice area could not be found.')->danger()->send();

                return;
            }

            try {
                $proposal = app(PracticeAreaMergeProposalService::class)->buildProposal(
                    $record,
                    $target,
                    scanTenantScoped: (bool) ($data['scan_tenant_scoped'] ?? false),
                );
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not build proposal')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()
                ->title('Merge proposal built — owner approval required')
                ->body(self::summarize($proposal))
                ->warning()
                ->persistent()
                ->send();
        });
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private static function summarize(array $proposal): string
    {
        $lines = [
            sprintf(
                'Source: %s (%s) → Target: %s (%s).',
                $proposal['source']['name'],
                $proposal['source']['code'],
                $proposal['target']['name'],
                $proposal['target']['code'],
            ),
            'Evidence: '.$proposal['evidence_strength'].'.',
        ];

        if ($proposal['duplicate_evidence'] !== []) {
            $lines[] = 'Matched because: '.implode('; ', $proposal['duplicate_evidence']).'.';
        }

        $lines[] = 'Semantically identical: '.$proposal['semantically_identical'].' — '.$proposal['semantically_identical_note'];

        $sourceDeps = $proposal['dependencies']['source'];

        $lines[] = 'Source global references — '.collect($sourceDeps['global'])
            ->map(fn (array $row): string => $row['label'].': '.$row['count'])
            ->implode(', ').'.';

        if ($sourceDeps['tenant_scanned']) {
            $lines[] = sprintf(
                'Per-firm scan: %d firm(s) affected across %d of %d firm(s) scanned%s. ',
                $sourceDeps['firms_affected'],
                $sourceDeps['firms_scanned'],
                $sourceDeps['firms_total'],
                $sourceDeps['capped'] ? ' (CAPPED — not every firm was scanned)' : '',
            ).collect($sourceDeps['tenant'])
                ->map(fn (array $row): string => $row['label'].': '.$row['count'])
                ->implode(', ').'.';
        } else {
            $lines[] = 'Tenant-owned references were NOT counted — re-run with the per-firm impact scan enabled for a complete picture.';
        }

        $lines[] = 'Alias redirect: '.$proposal['alias_redirect_behavior'];
        $lines[] = 'Source after merge: '.$proposal['source_post_merge_state'];
        $lines[] = 'Rollback: '.$proposal['rollback_limitations'];
        $lines[] = 'MERGE SAFE: NO — '.$proposal['merge_safe_reason'];
        $lines[] = 'OWNER APPROVAL REQUIRED: YES. Executed: NO.';

        return implode(' ', $lines);
    }
}
