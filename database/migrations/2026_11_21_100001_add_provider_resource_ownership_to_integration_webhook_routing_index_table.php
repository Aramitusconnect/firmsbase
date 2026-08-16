<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_webhook_routing_index — FirmsVault Pay Gate A2 extension
 * (Master Execution Prompt v1.4 §5/§6/§7).
 *
 * WHY EXTEND THIS TABLE RATHER THAN CREATE A NEW ONE. v1.4 §5 rules
 * that this existing table is the candidate implementation of the
 * architecture role `ProviderResourceLocator`, and §6 requires that
 * EXACTLY ONE authoritative ownership mapping exist for any provider
 * resource on the FirmsVault Pay path. Creating a second, separately
 * writable ownership table would by construction create a second
 * ownership authority — the precise thing §6 forbids and a declared
 * Gate A2 stop condition. So this table gains a second ADDRESSING MODE
 * rather than a sibling gaining a competing one.
 *
 * WHY A SECOND ADDRESSING MODE IS NEEDED AT ALL. The existing mode
 * resolves a FirmsVault-ISSUED opaque routing token
 * (`webhook_routing_token_hash`). That works when FirmsVault mints the
 * identifier. It cannot work for an external provider resource whose
 * identifier the PROVIDER mints (an identity, a merchant, a transfer,
 * a dispute) and hands back to us — there is no token to hash. The
 * repository already hit this twice and answered it with dedicated
 * per-provider side tables (`integration_gmail_mailbox_routes`,
 * `integration_plaid_item_routes`). A third bespoke table for payments
 * would entrench exactly the fragmentation §6 forbids, so this
 * migration instead generalizes the ONE authority:
 *
 *     mode A (existing, unchanged):
 *         webhook_routing_token_hash            -> (firm, connection)
 *     mode B (new):
 *         (provider, resource_type, resource_id) -> (firm, connection)
 *
 * COLUMN NULLABILITY CHANGE. `webhook_routing_token_hash` becomes
 * nullable so a mode-B row need not invent a token. This cannot affect
 * the existing read path: WebhookConnectionResolverService::
 * resolveConnectionIdentity() always compares against a real sha256
 * digest, and in SQL `NULL = <digest>` is never true, so mode-B rows
 * are invisible to the token lookup. The existing global uniqueness on
 * the hash is preserved exactly, as a PARTIAL unique index over
 * non-null values (NULLs are distinct in a plain unique index anyway;
 * the partial index states the intent explicitly and keeps the index
 * small). A CHECK constraint guarantees every row is in exactly one
 * mode, so a row can never be half-addressed or doubly-addressed.
 *
 * OWNERSHIP UNIQUENESS (§6) is the partial unique index
 * `..._resource_ownership_unique` over
 * (integration_provider_id, provider_resource_type, provider_resource_id).
 * One provider resource resolves to exactly one (firm, connection),
 * system-wide. A second firm attempting to claim the same resource
 * fails on this index — this is the database mechanism behind FV-A-039.
 *
 * OWNERSHIP IMMUTABILITY (§7) has two halves, enforced differently and
 * reported honestly:
 *   - Re-assignment BY INSERT (the realistic attack: deactivate, then
 *     let another firm claim the resource) is blocked in the DATABASE.
 *     The unique index above deliberately does NOT exclude inactive
 *     rows: an `ownership_status = 'inactive'` row still occupies the
 *     resource identity forever, so "ACTIVE -> INACTIVE" is permitted
 *     while "Firm A -> Firm B" remains impossible, and historical
 *     financial ownership stays provable.
 *   - Re-assignment BY IN-PLACE UPDATE is blocked in the APPLICATION,
 *     via the append-only guard on
 *     App\Integrations\Models\IntegrationWebhookRoutingIndex (the same
 *     `static::updating()` pattern payment_allocations and
 *     accounting_journal_entries already use). It is NOT a database
 *     trigger: this codebase has a standing zero-trigger convention
 *     (stated verbatim in
 *     2026_09_05_054001_create_integration_conflicts_table.php), and
 *     column-level `REVOKE UPDATE` would not bind the table owner,
 *     which is the role the application and the test harness both
 *     connect as. This residual gap is disclosed in the Gate A2 report
 *     rather than papered over.
 *
 * RLS. Unchanged: this table still has NO RLS and NO FORCE RLS, for
 * exactly the reasons its create migration sets out at length — it must
 * be readable BEFORE any tenant context exists. Mode B does not weaken
 * that argument: a mode-B row still carries no secret material, only a
 * provider-minted public identifier and the two identifiers the lookup
 * exists to resolve. Possession of a provider resource id still
 * authorizes nothing on its own; signature verification remains
 * separately required. This table must never become a general
 * system-role gateway to tenant financial data (v1.4 §39) — it returns
 * ONLY {firm_id, firm_integration_id, integration_provider_id} and is
 * read by exactly one resolver method.
 *
 * ROLLBACK. down() drops only what up() added and restores the original
 * NOT NULL + plain unique on the token hash. It refuses to run if any
 * mode-B row exists, because silently deleting live ownership rows
 * would destroy the audit trail that proves who owned a provider
 * resource.
 */
return new class extends Migration
{
    private const TABLE = 'integration_webhook_routing_index';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            // Provider-minted resource identity (mode B). Both null for
            // a mode-A row; both non-null for a mode-B row.
            $table->string('provider_resource_type')->nullable()->after('integration_provider_id');
            $table->string('provider_resource_id')->nullable()->after('provider_resource_type');

            // ACTIVE -> INACTIVE is permitted; the row itself is never
            // deleted, so the resource identity stays claimed forever.
            $table->string('ownership_status')->default('active');

            $table->timestamp('ownership_established_at')->nullable();
        });

        // Make the token hash optional WITHOUT weakening its uniqueness.
        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN webhook_routing_token_hash DROP NOT NULL');

        // The original unique was created by ->unique(...) as
        // integration_webhook_routing_index_webhook_routing_token_hash_unique.
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS integration_webhook_routing_index_webhook_routing_token_hash_unique');

        DB::statement(
            'CREATE UNIQUE INDEX integration_webhook_routing_index_token_hash_unique '.
            'ON '.self::TABLE.' (webhook_routing_token_hash) '.
            'WHERE webhook_routing_token_hash IS NOT NULL'
        );

        // §6: exactly one authoritative ownership mapping per provider
        // resource, system-wide. Deliberately NOT filtered on
        // ownership_status — see this migration's docblock (§7).
        DB::statement(
            'CREATE UNIQUE INDEX integration_webhook_routing_index_resource_ownership_unique '.
            'ON '.self::TABLE.' (integration_provider_id, provider_resource_type, provider_resource_id) '.
            'WHERE provider_resource_id IS NOT NULL'
        );

        // Every row is in exactly one addressing mode.
        DB::statement(<<<'SQL'
            ALTER TABLE integration_webhook_routing_index
            ADD CONSTRAINT integration_webhook_routing_index_addressing_mode CHECK (
                (
                    webhook_routing_token_hash IS NOT NULL
                    AND provider_resource_type IS NULL
                    AND provider_resource_id IS NULL
                )
                OR (
                    webhook_routing_token_hash IS NULL
                    AND provider_resource_type IS NOT NULL
                    AND provider_resource_id IS NOT NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_webhook_routing_index
            ADD CONSTRAINT integration_webhook_routing_index_ownership_status CHECK (
                ownership_status IN ('active', 'inactive')
            )
        SQL);
    }

    public function down(): void
    {
        $modeBRows = DB::table(self::TABLE)->whereNotNull('provider_resource_id')->count();

        if ($modeBRows > 0) {
            throw new RuntimeException(
                'Refusing to roll back: '.$modeBRows.' provider-resource ownership row(s) exist in '
                .self::TABLE.'. Rolling back would delete the authoritative, historically immutable '
                .'record of which firm owned which external provider resource. Resolve those rows '
                .'deliberately before rolling this migration back.'
            );
        }

        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS integration_webhook_routing_index_ownership_status');
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS integration_webhook_routing_index_addressing_mode');
        DB::statement('DROP INDEX IF EXISTS integration_webhook_routing_index_resource_ownership_unique');
        DB::statement('DROP INDEX IF EXISTS integration_webhook_routing_index_token_hash_unique');

        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN webhook_routing_token_hash SET NOT NULL');
        DB::statement(
            'ALTER TABLE '.self::TABLE.' '.
            'ADD CONSTRAINT integration_webhook_routing_index_webhook_routing_token_hash_unique '.
            'UNIQUE (webhook_routing_token_hash)'
        );

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn([
                'provider_resource_type',
                'provider_resource_id',
                'ownership_status',
                'ownership_established_at',
            ]);
        });
    }
};
