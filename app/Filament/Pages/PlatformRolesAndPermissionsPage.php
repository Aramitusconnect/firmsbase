<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PlatformRoleCode;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * PlatformRolesAndPermissionsPage — FirmsVault Admin Control Center
 * "Roles and Permissions" resource. Named/shaped as a Page rather than
 * a Resource, per this checkpoint's own explicit judgment call:
 * PlatformRole is a pure grant/assignment table over a fixed enum (see
 * that model's own docblock — "No uuid — not addressed individually"),
 * not a resource with an independent lifecycle of its own (no
 * Create/Edit/Delete of a "role" as a first-class record — the 9 roles
 * ARE PlatformRoleCode, a closed enum, not database rows), so a
 * read-focused Page fits this data shape better than a Resource whose
 * conventions (index/view routes over a queryable table of role
 * records) don't really apply here.
 *
 * Three things shown, per the mission brief:
 *  1. The 9 PlatformRoleCode cases with what each one actually grants.
 *     Descriptions are NOT free-hand prose — capabilitiesForRole()
 *     reads PlatformStaffAccessPolicyService's own private role-set
 *     constants directly via reflection (never hand-copied/duplicated
 *     text that could silently drift from that service's real,
 *     current behavior) and pairs each constant with a short label
 *     drawn from that constant's own docblock. ReadOnlyAuditor's
 *     blanket "may never mutate" rule is a genuine exception — it is
 *     not one of the *_ROLES constants (it lives in canMutate()'s own
 *     logic instead) — added as one explicit, clearly-labeled extra
 *     line grounded in that method's own docblock ("Blanket rule 9: a
 *     read_only_auditor may never mutate data, regardless of any other
 *     role also held"), not invented.
 *  2. Current PlatformAdmin -> role assignments — queried directly
 *     from `platform_roles` (the same underlying table
 *     PlatformAdministratorResource's own roles() eager-load reads),
 *     not a redundant second aggregation service.
 *  3. Read-only usage counts — how many currently-ACTIVE
 *     (is_active = true AND an unrevoked grant) admins hold each role,
 *     the same "active" definition PlatformRoleService::
 *     wouldLeaveNoActiveSuperAdmin() uses.
 *
 * Gate/visibility judgment call (explicitly flagged, not silently
 * decided): the WHOLE page — including the role catalog/description
 * section, which in isolation is not sensitive — is gated behind
 * PlatformStaffAccessPolicyService::canManageRoles() (SuperAdmin-only),
 * the same gate the mission brief names for "any visibility of the
 * assignment/management view". The brief notes a narrower, role-catalog
 * -only view "could reasonably be visible more broadly" (e.g. to
 * SecurityAuditor for compliance review) — deliberately NOT built as a
 * separate, more-broadly-visible page in this checkpoint: no PLATFORM_
 * ADMINISTRATION_ROLES-style existing grant covers "view the role
 * catalog" today, inventing one would be a new access-control decision
 * beyond this checkpoint's brief, and the marginal value of a
 * definitions-only page is limited (the same information already lives
 * in this class's own PlatformRoleCode enum reference and the design
 * docs) versus the cost of a second gate to maintain and test. Left
 * as a candidate future enhancement if a real broader-audience need
 * materializes, not a silent omission.
 */
class PlatformRolesAndPermissionsPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Roles & Permissions';

    protected static ?string $title = 'Roles & Permissions';

    protected static ?string $slug = 'roles-and-permissions';

    /**
     * Constant name (on PlatformStaffAccessPolicyService) => short,
     * docblock-derived capability label. Only the LABEL text is
     * hand-curated here (a concise paraphrase of that constant's own
     * docblock); the actual ROLE MEMBERSHIP for each capability is
     * always read live via reflection in capabilitiesForRole() below,
     * so this page cannot silently drift from that service's real,
     * current role sets.
     */
    private const CAPABILITY_CONSTANTS = [
        'CLIENT_AND_MATTER_DATA_ROLES' => 'Client and matter data',
        'DOCUMENT_CONTENT_ROLES' => 'Legal document contents (unconditional)',
        'DOCUMENT_CONTENT_ROLES_REQUIRING_GOVERNED_ACCESS' => 'Legal document contents (only during an approved, governed support-access session)',
        'PLATFORM_BILLING_ROLES' => 'Platform billing',
        'SECURITY_LOG_ROLES' => 'Security logs',
        'PLATFORM_ADMINISTRATION_ROLES' => 'Platform administration oversight (Firms / Firm Users lists)',
        'FIRM_MANAGEMENT_ROLES' => 'Firm status management (mutating)',
        'PLATFORM_ADMINISTRATOR_MANAGEMENT_ROLES' => 'Platform administrator management (activate/deactivate, MFA reset)',
        'ROLE_MANAGEMENT_ROLES' => 'Role/permission catalog management (grant/revoke roles)',
    ];

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canManageRoles($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        $usageCounts = $this->activeAdminCountsByRole();
        $assignments = $this->currentAssignments();

        return $schema->components([
            Section::make('Role Catalog')
                ->description('What each of the 9 fixed platform-staff roles grants, per PlatformStaffAccessPolicyService.')
                ->schema(collect(PlatformRoleCode::cases())
                    ->map(fn (PlatformRoleCode $role) => Section::make(Str::headline($role->value))
                        ->compact()
                        ->schema([
                            Text::make('Active admins holding this role: '.($usageCounts[$role->value] ?? 0)),
                            UnorderedList::make(
                                empty($this->capabilitiesForRole($role))
                                    ? [Text::make('No access grants defined for this role within PlatformStaffAccessPolicyService.')->color('gray')]
                                    : collect($this->capabilitiesForRole($role))->map(fn (string $capability) => Text::make($capability))->all()
                            ),
                        ]))
                    ->all()),
            Section::make('Current Assignments')
                ->description('Every currently-active (unrevoked) PlatformAdmin -> role grant.')
                ->schema([
                    UnorderedList::make(
                        $assignments->isEmpty()
                            ? [Text::make('No active role assignments.')->color('gray')]
                            : $assignments->map(fn (array $row) => Text::make(
                                "{$row['name']} ({$row['email']}) — ".Str::headline($row['role_code'])
                            ))->all()
                    ),
                ]),
        ]);
    }

    /**
     * @return array<string>
     */
    private function capabilitiesForRole(PlatformRoleCode $role): array
    {
        $reflection = new ReflectionClass(PlatformStaffAccessPolicyService::class);
        $capabilities = [];

        foreach (self::CAPABILITY_CONSTANTS as $constantName => $label) {
            $roles = $reflection->getReflectionConstant($constantName)->getValue();

            if (in_array($role, $roles, true)) {
                $capabilities[] = $label;
            }
        }

        if ($role === PlatformRoleCode::ReadOnlyAuditor) {
            $capabilities[] = 'Blanket rule (canMutate()): may never mutate data, regardless of any other role also held';
        }

        return $capabilities;
    }

    /**
     * @return array<string, int>
     */
    private function activeAdminCountsByRole(): array
    {
        return PlatformRole::query()
            ->join('platform_admins', 'platform_admins.id', '=', 'platform_roles.platform_admin_id')
            ->whereNull('platform_roles.revoked_at')
            ->where('platform_admins.is_active', true)
            ->select('platform_roles.role_code', DB::raw('count(*) as active_admin_count'))
            ->groupBy('platform_roles.role_code')
            ->pluck('active_admin_count', 'role_code')
            ->all();
    }

    /**
     * Reuses the same underlying `platform_roles`/`platform_admins`
     * data PlatformAdministratorResource's own roles() eager-load
     * reads — a light, direct query here rather than a redundant
     * second aggregation service, per the mission brief's own "don't
     * requery redundantly if avoidable" instruction.
     *
     * @return Collection<int, array{name: string, email: string, role_code: string}>
     */
    private function currentAssignments(): Collection
    {
        return PlatformRole::query()
            ->with('platformAdmin:id,name,email')
            ->whereNull('revoked_at')
            ->get()
            ->filter(fn (PlatformRole $grant) => $grant->platformAdmin !== null)
            ->map(fn (PlatformRole $grant): array => [
                'name' => $grant->platformAdmin->name,
                'email' => $grant->platformAdmin->email,
                'role_code' => $grant->role_code->value,
            ])
            ->sortBy('name')
            ->values();
    }
}
