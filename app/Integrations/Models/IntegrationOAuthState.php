<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Database\Factories\IntegrationOAuthStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * IntegrationOAuthState — the per-attempt, server-side, single-use OAuth
 * state row (Checkpoint 5, checkpoint-00-final-specification.md §5
 * table #4; frozen-design-post-review.md; agent-h-security-architecture-review.md).
 * Direct firm-owned, matching integration_credentials' own precedent:
 * internal, single-use, security-sensitive routing state, never
 * externally addressed.
 *
 * No HasPublicUuid / no `uuid` column (Agent H review item 7, binding
 * correction to Agent D's rejected raw-UUID design): the raw `state=`
 * bearer value handed to the provider is NEVER persisted anywhere on
 * this model in any form — only `opaque_token_hash` (a sha256 digest of
 * it) is stored, and it is looked up by ordinary hash equality, never
 * exposed as a route-bindable identifier.
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6): the default
 * Model::resolveFactoryName() only special-cases the literal
 * `App\Models\` prefix, so a model under `App\Integrations\Models\`
 * would otherwise look for a nonexistent
 * `Database\Factories\Integrations\Models\IntegrationOAuthStateFactory`.
 *
 * Compensating control for the disclosed initiating_firm_user_id
 * bare-FK gap (see this table's create migration, section (d)):
 * `firm_users` carries only UNIQUE(user_id, firm_id), not
 * UNIQUE(firm_id, id), so the composite FK the cross-cutting design
 * principle would otherwise require is not achievable without a
 * separate migration on firm_users itself — out of scope for this
 * checkpoint, per Agent H's review (item 4), exactly mirroring the
 * accepted Checkpoint 3 precedent
 * (FirmIntegration::assertConnectedByFirmUserBelongsToSameFirm()). This
 * model's `saving` listener below is the actual defense-in-depth
 * substitute: whenever initiating_firm_user_id is set, it verifies — by
 * fetching the referenced firm_users row WITHOUT the tenant global
 * scope (so this check does not depend on, or get silently masked by,
 * whatever tenant context happens to be active) — that its real firm_id
 * equals this row's own firm_id, AND that its user_id equals this row's
 * own initiating_user_id, before allowing the save to proceed. The
 * second (user_id) check is a deliberate, narrow ADDITION beyond the
 * literal FirmIntegration precedent (which only checks firm_id): unlike
 * connected_by_firm_user_id, initiating_user_id is the actual identity
 * column the self-lookup RLS policy on this table
 * (integration_oauth_states_self_lookup) reads, so the two columns
 * disagreeing would silently break — not merely narrow the audit trail
 * of — the callback bootstrap lookup. This never widens tenant
 * isolation (the FORCE RLS policy on integration_oauth_states remains
 * the actual isolation boundary for this table, independent of this
 * column) — it only prevents a narrower integrity gap: a row that would
 * otherwise silently record a mismatched initiating identity pair.
 * Factory discipline: initiating_user_id/initiating_firm_user_id must
 * always be set together from the SAME FirmUser row, never
 * independently, or this listener rejects the row.
 */
class IntegrationOAuthState extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'integration_oauth_states';

    protected $fillable = [
        'firm_id',
        'firm_integration_id',
        'initiating_user_id',
        'initiating_firm_user_id',
        'opaque_token_hash',
        'redirect_uri',
        'verifier_ciphertext',
        'encryption_key_id',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'opaque_token_hash',
        'verifier_ciphertext',
    ];

    protected static function booted(): void
    {
        static::saving(function (IntegrationOAuthState $model): void {
            $model->assertInitiatingFirmUserBelongsToSameFirmAndUser();
        });
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationOAuthStateFactory
    {
        return IntegrationOAuthStateFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function firmIntegration(): BelongsTo
    {
        return $this->belongsTo(FirmIntegration::class);
    }

    public function initiatingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiating_user_id');
    }

    public function initiatingFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'initiating_firm_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /**
     * The compensating, application-level control substituting for the
     * disclosed initiating_firm_user_id bare-FK gap (see class
     * docblock). Deliberately looks up the referenced firm_users row
     * WITHOUT the BelongsToTenant global scope, so the check reflects
     * that row's real, unfiltered firm_id/user_id rather than silently
     * passing (via a "not found" false negative) whenever no tenant
     * context, or the wrong tenant context, happens to be active.
     * firm_users' own FORCE RLS policy may still narrow the underlying
     * DB read to the active session's firm — that is an additional,
     * independent layer, not a substitute for this explicit comparison.
     */
    private function assertInitiatingFirmUserBelongsToSameFirmAndUser(): void
    {
        if (empty($this->initiating_firm_user_id)) {
            return;
        }

        if (empty($this->firm_id)) {
            throw new RuntimeException(
                'integration_oauth_states.firm_id must be set before initiating_firm_user_id can be validated.'
            );
        }

        $firmUser = FirmUser::query()
            ->withoutGlobalScope('tenant')
            ->find($this->initiating_firm_user_id);

        if ($firmUser === null || (int) $firmUser->firm_id !== (int) $this->firm_id) {
            throw new RuntimeException(
                'initiating_firm_user_id must reference a firm_users row belonging to the same firm_id '.
                'as this integration_oauth_states row (disclosed compensating control for the '.
                'initiating_firm_user_id bare-FK gap — see this model\'s class docblock).'
            );
        }

        if (! empty($this->initiating_user_id) && (int) $firmUser->user_id !== (int) $this->initiating_user_id) {
            throw new RuntimeException(
                'initiating_firm_user_id must reference a firm_users row whose user_id matches this row\'s own '.
                'initiating_user_id — the two columns must always be set together from the same FirmUser row '.
                '(see this model\'s class docblock).'
            );
        }
    }
}
