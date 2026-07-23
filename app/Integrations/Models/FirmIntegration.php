<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\HealthSummaryState;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Firm;
use App\Models\FirmUser;
use Database\Factories\FirmIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * FirmIntegration — the per-firm connection instance to a registered
 * provider (checkpoint-00-final-specification.md §5 table #2;
 * domain-model-and-rls-classification.md §2). Direct firm-owned,
 * modeled explicitly on App\Models\EmailAccount: same
 * connected_by_firm_user_id actor-column shape, same plain-string
 * `status` column cast to an application-level enum
 * (App\Integrations\Enums\ConnectionStatus — the exact 5-case enum
 * already defined at Checkpoint 1, not a new one), same dual-ID
 * (bigint id for FKs / uuid for public exposure) design via
 * HasPublicUuid (this codebase's actual UUID convention — NOT
 * Eloquent's built-in HasUuids, and NOT a manual Str::uuid() `creating`
 * listener; see app/Models/Concerns/HasPublicUuid.php's own docblock
 * for why HasUuids is deliberately not used here).
 *
 * newFactory() override is REQUIRED (STANDING CONVENTION,
 * checkpoint-00-final-specification.md §6): the default
 * Model::resolveFactoryName() only special-cases the literal
 * `App\Models\` prefix, so a model under `App\Integrations\Models\`
 * would otherwise look for a nonexistent
 * `Database\Factories\Integrations\Models\FirmIntegrationFactory`.
 *
 * Compensating control for the disclosed connected_by_firm_user_id
 * bare-FK gap (checkpoint-03-security-review.md ADDENDUM): firm_users
 * carries only UNIQUE(user_id, firm_id), not UNIQUE(firm_id, id), so
 * the composite FK the cross-cutting design principle would otherwise
 * require is not achievable without a separate migration on
 * firm_users itself — out of scope for this checkpoint, per the
 * coordinator's accepted decision. This model's `saving` listener
 * below is the actual defense-in-depth substitute: whenever
 * connected_by_firm_user_id is set (on create OR update), it verifies
 * — by fetching the referenced firm_users row WITHOUT the tenant
 * global scope (so this check does not depend on, or get silently
 * masked by, whatever tenant context happens to be active) — that its
 * real firm_id equals this row's own firm_id, before allowing the
 * save to proceed. This never widens tenant isolation (the FORCE RLS
 * policy on firm_integrations remains the actual isolation boundary
 * for this table, independent of this column) — it only prevents a
 * narrower audit-attribution-integrity gap: a firm_integrations row
 * that would otherwise silently record a different firm's user as
 * having connected it.
 */
class FirmIntegration extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'firm_integrations';

    protected $fillable = [
        'firm_id',
        'integration_provider_id',
        'external_account_id',
        'display_label',
        'status',
        'scopes_granted_json',
        'connected_by_firm_user_id',
        'connected_at',
        'disconnected_at',
        'last_health_check_at',
        'last_health_status',
        'error_reason',
        'webhook_routing_token',
    ];

    /**
     * Checkpoint 9 addition (frozen-design-post-security-review.md §9;
     * agent-9h-architecture-security-review.md §7.1): one-line,
     * additive-only defense-in-depth fix. `webhook_routing_token` is a
     * routing identifier, not a signature secret — possession alone
     * never authorizes processing — but every other genuinely-sensitive-
     * shaped column on a sibling model in this domain
     * (IntegrationCredential.encrypted_payload_ciphertext,
     * IntegrationOAuthState.opaque_token_hash/verifier_ciphertext, the
     * routing-index sibling model's own hashed-token column) is
     * already `$hidden`; this model was the one structural
     * inconsistency. Zero behavioral impact on any existing caller — no
     * caller relies on this surviving `->toArray()`/`->toJson()`.
     */
    protected $hidden = ['webhook_routing_token'];

    protected static function booted(): void
    {
        static::saving(function (FirmIntegration $model): void {
            $model->assertConnectedByFirmUserBelongsToSameFirm();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'scopes_granted_json' => 'array',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_health_check_at' => 'datetime',
            // CHECKPOINT 8 addition (agent-8f-health-state-design.md §1,
            // agent-8h-architecture-security-review.md §4.1's explicit
            // "implementer's discretion" allowance): last_health_status
            // is now written exclusively by HealthStateService as a
            // denormalized cache of the SAME HealthSummaryState value
            // it persists to integration_connection_health.summary_state
            // — casting it here clarifies the read path without any
            // schema/column change.
            'last_health_status' => HealthSummaryState::class,
        ];
    }

    protected static function newFactory(): FirmIntegrationFactory
    {
        return FirmIntegrationFactory::new();
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function integrationProvider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class);
    }

    public function connectedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'connected_by_firm_user_id');
    }

    /**
     * The compensating, application-level control substituting for the
     * disclosed connected_by_firm_user_id bare-FK gap (see class
     * docblock). Deliberately looks up the referenced firm_users row
     * WITHOUT the BelongsToTenant global scope, so the check reflects
     * that row's real, unfiltered firm_id rather than silently passing
     * (via a "not found" false negative) whenever no tenant context, or
     * the wrong tenant context, happens to be active. firm_users' own
     * FORCE RLS policy may still narrow the underlying DB read to the
     * active session's firm — that is an additional, independent layer,
     * not a substitute for this explicit comparison.
     */
    private function assertConnectedByFirmUserBelongsToSameFirm(): void
    {
        if (empty($this->connected_by_firm_user_id)) {
            return;
        }

        if (empty($this->firm_id)) {
            throw new RuntimeException(
                'firm_integrations.firm_id must be set before connected_by_firm_user_id can be validated.'
            );
        }

        $firmUser = FirmUser::query()
            ->withoutGlobalScope('tenant')
            ->find($this->connected_by_firm_user_id);

        if ($firmUser === null || (int) $firmUser->firm_id !== (int) $this->firm_id) {
            throw new RuntimeException(
                'connected_by_firm_user_id must reference a firm_users row belonging to the same firm_id '.
                'as this firm_integrations row (disclosed compensating control for the connected_by_firm_user_id '.
                'bare-FK gap — see checkpoint-03-security-review.md ADDENDUM).'
            );
        }
    }
}
