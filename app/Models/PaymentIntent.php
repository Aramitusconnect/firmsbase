<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentIntentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PaymentIntent — FirmsVault Pay Gate A2. An INSTRUCTION to collect a
 * specific amount, not a record of money received (that remains
 * App\Models\Payment, untouched).
 *
 * FREEZE SEMANTICS (v1.4 §17). Once status leaves Draft, the MATERIAL
 * fields listed in self::MATERIAL_FIELDS can never change again. The
 * guard below is the application half; the database half is the
 * `payment_intents_freeze_consistency` CHECK plus `material_fingerprint`,
 * a sha256 over the material values captured at freeze time, so a
 * divergence is detectable even if a row were mutated by something that
 * bypassed Eloquent entirely.
 *
 * A change to a frozen intent is expressed by SUPERSEDING it — creating
 * a new intent that points back at this one — never by rewriting
 * history (PaymentIntentService::supersede()).
 */
class PaymentIntent extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    /**
     * The material (economic) fields. Immutable once frozen, and the
     * exact input set to the material fingerprint.
     *
     * `status`, `frozen_at`, the supersede pointers and
     * `material_fingerprint` are deliberately absent: they are lifecycle
     * metadata, not the economic instruction.
     */
    public const MATERIAL_FIELDS = [
        'firm_id',
        'client_id',
        'matter_id',
        'invoice_id',
        'amount_cents',
        'currency',
        'purpose',
    ];

    protected $fillable = [
        'firm_id',
        'client_id',
        'matter_id',
        'invoice_id',
        'amount_cents',
        'currency',
        'purpose',
        'status',
        'frozen_at',
        'supersedes_payment_intent_id',
        'superseded_by_payment_intent_id',
        'superseded_at',
        'material_fingerprint',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'status' => PaymentIntentStatus::class,
            'frozen_at' => 'datetime',
            'superseded_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $intent) {
            // A Draft intent may still be edited freely. Anything else
            // may only have its lifecycle metadata touched.
            $original = $intent->getOriginal('status');
            $wasDraft = $original === PaymentIntentStatus::Draft->value
                || $original === PaymentIntentStatus::Draft;

            if ($wasDraft) {
                return;
            }

            $changedMaterial = array_intersect(
                array_keys($intent->getDirty()),
                self::MATERIAL_FIELDS,
            );

            if ($changedMaterial !== []) {
                throw new \LogicException(
                    'payment_intents: a frozen intent is immutable — refusing to change material field(s) ['
                    .implode(', ', $changedMaterial).']. Supersede the intent instead of rewriting it.'
                );
            }
        });
    }

    /**
     * The deterministic fingerprint of this intent's material values.
     * Recomputable at any time, so drift is detectable.
     */
    public function computeMaterialFingerprint(): string
    {
        $material = [];

        foreach (self::MATERIAL_FIELDS as $field) {
            $value = $this->getAttribute($field);
            $material[$field] = $value === null ? null : (string) $value;
        }

        return hash('sha256', json_encode($material, JSON_THROW_ON_ERROR));
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentIntentAllocation::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_payment_intent_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_payment_intent_id');
    }
}
