<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CredentialTypeNeverInPolicyRegressionTest — Checkpoint 7's bright-line
 * regression guard (reviews/checkpoint-07/frozen-design-post-security-review.md
 * §7): the Checkpoint 0 §11 "candidate replacement" carve-out
 * (`credential_type = 'webhook_signing_secret' AND webhook_routing_token
 * = current_setting(...)`) is permanently retired, never implemented.
 * A direct `pg_get_expr()`-based string search across every RLS policy
 * in the live PostgreSQL catalog — never a code grep — proves the
 * literal substring `credential_type` never appears in any policy
 * definition, on `integration_credentials` or anywhere else.
 */
final class CredentialTypeNeverInPolicyRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_policy_on_integration_credentials_references_credential_type(): void
    {
        $rows = DB::select(
            "select polname,
                    pg_get_expr(polqual, polrelid) as using_expr,
                    pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy
             where polrelid = 'integration_credentials'::regclass"
        );

        $this->assertNotEmpty($rows, 'integration_credentials must have at least its base tenant-isolation policy.');

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('credential_type', (string) $row->using_expr, "Policy {$row->polname}'s USING clause must never reference credential_type.");
            $this->assertStringNotContainsString('credential_type', (string) ($row->with_check_expr ?? ''), "Policy {$row->polname}'s WITH CHECK clause must never reference credential_type.");
        }
    }

    public function test_integration_credentials_has_exactly_one_policy_this_checkpoint(): void
    {
        $rows = DB::select("select policyname from pg_policies where tablename = 'integration_credentials'");

        $this->assertCount(
            1,
            $rows,
            'Checkpoint 7 must add no new policy to integration_credentials at all — the retired credential_type carve-out must not silently reappear as a second policy.'
        );
        $this->assertSame('integration_credentials_tenant_isolation', $rows[0]->policyname);
    }

    /**
     * Whole-database sweep — the strongest form of this guard: no RLS
     * policy anywhere in the entire schema, on any table, may ever
     * reference `credential_type`, not merely on `integration_credentials`
     * itself.
     */
    public function test_no_row_level_security_policy_anywhere_in_the_database_references_credential_type(): void
    {
        $rows = DB::select(
            "select polrelid::regclass::text as table_name,
                    polname,
                    pg_get_expr(polqual, polrelid) as using_expr,
                    pg_get_expr(polwithcheck, polrelid) as with_check_expr
             from pg_policy"
        );

        $this->assertNotEmpty($rows, 'The database must have at least some RLS policies at this point in the test suite.');

        $violations = [];

        foreach ($rows as $row) {
            $using = (string) ($row->using_expr ?? '');
            $withCheck = (string) ($row->with_check_expr ?? '');

            if (str_contains($using, 'credential_type') || str_contains($withCheck, 'credential_type')) {
                $violations[] = "{$row->table_name}.{$row->polname}";
            }
        }

        $this->assertEmpty($violations, 'No RLS policy anywhere may reference credential_type: '.implode(', ', $violations));
    }

    public function test_the_credential_type_column_is_never_part_of_any_create_policy_sql_statement(): void
    {
        // Defense-in-depth structural note: RLS predicates are proven
        // above via pg_policy directly (the authoritative, live-catalog
        // proof). This is a narrower, source-level sanity check on top
        // of that: it isolates just the CREATE POLICY ... SQL heredoc
        // block(s) inside each RLS migration file (not the whole file
        // — a docblock elsewhere in the same file, such as this
        // checkpoint's own §7 design-rationale prose, may legitimately
        // mention "credential_type" by name without that ever being
        // part of an actual predicate) and confirms none of them
        // contains the literal substring credential_type.
        $migrationFiles = glob(base_path('database/migrations/*.php'));
        $this->assertNotEmpty($migrationFiles);

        $violations = [];

        foreach ($migrationFiles as $file) {
            $source = file_get_contents($file);

            if ($source === false || ! str_contains($source, 'CREATE POLICY')) {
                continue;
            }

            if (preg_match_all('/CREATE POLICY.*?(?=SQL\);|$)/is', $source, $matches)) {
                foreach ($matches[0] as $policyBlock) {
                    if (str_contains($policyBlock, 'credential_type')) {
                        $violations[] = $file;
                    }
                }
            }
        }

        $this->assertEmpty($violations, 'No CREATE POLICY SQL statement may reference credential_type: '.implode(', ', $violations));
    }
}
