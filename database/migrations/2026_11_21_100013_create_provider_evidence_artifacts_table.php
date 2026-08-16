<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * provider_evidence_artifacts — FirmsVault Pay Gate A2 (v1.4 §42).
 *
 * ARCHITECTURE ROLE. v3.1 `ProviderEvidenceArtifact` — an evidence
 * INDEX, deliberately not an evidence store. Large provider payloads
 * must not be "sprayed through ordinary financial tables" (§42), so a
 * row here carries a storage REFERENCE plus a sha256 of the bytes, and
 * the bytes themselves live on the project's existing S3 disk under the
 * project's existing document/encryption conventions.
 *
 * WHY EXISTING SCHEMA IS INSUFFICIENT.
 * `integration_webhook_receipts` already indexes inbound webhook
 * evidence, but only inbound webhooks: it is keyed by
 * (routing_token_hash, body_hash) and has no notion of an outbound
 * provider response, a settlement file, or an outcome-lookup result,
 * and no link to a ProviderCommand. This table covers the evidence
 * classes a payment provider produces that a webhook receipt cannot
 * represent. It does not replace or duplicate that table — inbound
 * webhook receipts keep using it.
 *
 * TENANT OWNERSHIP — strictly tenant-owned. `firm_id` is NOT NULL.
 *
 * An earlier Gate A2 draft made it nullable, intending this table to
 * also hold UNRESOLVED inbound evidence (an artifact that has not yet
 * been attributed to a firm). That was wrong on two counts, and the
 * database said so immediately:
 *
 *   1. It is unimplementable under this table's own security posture.
 *      With FORCE RLS and a `WITH CHECK (firm_id = <current firm>)`
 *      policy, a NULL-firm row can never be inserted by ANY role in ANY
 *      context — NULL = anything is never true. Making it insertable
 *      would have required either a policy carve-out or a BYPASSRLS
 *      worker, both of which v1.4 §37 forbids and this repository has
 *      repeatedly and deliberately rejected.
 *
 *   2. It was unnecessary. Unresolved provider ingress ALREADY has a
 *      home: `integration_webhook_receipts`, which is Global/EXEMPT by
 *      design precisely so it can be written before tenant identity is
 *      known, and which already stores a body hash, a provider event
 *      id, a verification outcome and a retention deadline. Adding a
 *      second unresolved-ingress store would have been exactly the
 *      duplicate subsystem v1.4 §32 forbids.
 *
 * So the boundary is: unresolved ingress stays in
 * `integration_webhook_receipts` (pre-tenant, no RLS, no payload
 * bodies); this table holds only evidence that is ALREADY attributed to
 * a firm, and is therefore an ordinary FORCE-RLS tenant table. v1.4
 * §41's "do not store unresolved provider payloads in tenant-visible
 * tables" is satisfied by construction rather than by policy nuance.
 *
 * NO SECRETS. `storage_reference` is a path/key, never a credential.
 * `provider_context` is a redacted, non-secret label consistent with
 * the existing Sanitized* discipline. Card data (PAN/CVV) must never
 * reach this table, or any table (v1.4 §43).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_evidence_artifacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // NOT NULL by design — see the class docblock.
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->unsignedBigInteger('firm_integration_id')->nullable();

            $table->foreignId('integration_provider_id')->nullable()
                ->constrained('integration_providers')->restrictOnDelete();

            $table->unsignedBigInteger('provider_command_id')->nullable();

            $table->string('evidence_type');

            // Where the bytes actually live, on existing infrastructure.
            $table->string('storage_disk')->nullable();
            $table->string('storage_reference')->nullable();

            // Tamper evidence.
            $table->string('content_sha256', 64);
            $table->unsignedBigInteger('content_bytes')->nullable();

            $table->string('provider_context')->nullable();
            $table->unsignedInteger('schema_version')->default(1);

            $table->timestamp('captured_at')->useCurrent();
            $table->timestamp('retention_deadline')->nullable();
            $table->timestamp('redacted_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'evidence_type']);
            $table->index('provider_command_id');
            $table->index('content_sha256');
            $table->index('retention_deadline');
        });

        // A firm-attributed artifact must stay inside its firm.
        DB::statement(<<<'SQL'
            ALTER TABLE provider_evidence_artifacts
            ADD CONSTRAINT provider_evidence_artifacts_firm_integration_same_firm_fk
            FOREIGN KEY (firm_id, firm_integration_id)
            REFERENCES firm_integrations (firm_id, id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE provider_evidence_artifacts
            ADD CONSTRAINT provider_evidence_artifacts_command_same_firm_fk
            FOREIGN KEY (provider_command_id, firm_id)
            REFERENCES provider_commands (id, firm_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE provider_evidence_artifacts
            ADD CONSTRAINT provider_evidence_artifacts_evidence_type_values CHECK (
                evidence_type IN (
                    'provider_response',
                    'outcome_lookup',
                    'settlement_report',
                    'fee_report',
                    'inbound_event'
                )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_evidence_artifacts');
    }
};
