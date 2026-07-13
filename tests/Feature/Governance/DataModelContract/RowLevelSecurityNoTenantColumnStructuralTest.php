<?php

namespace Tests\Feature\Governance\DataModelContract;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RowLevelSecurityNoTenantColumnStructuralTest — the static,
 * migration-schema-parsing proof (NOT a live database query) that
 * module_catalog and readiness_scorecard_components — the two Wave 1A
 * (Section 39A-4B) EXEMPT_TABLES additions — carry no firm-referencing
 * column at all. This is the "confirming no tenant-specific rows"
 * evidence the human required for these two exemptions, produced by
 * parsing each table's own create_*_table migration's Schema::create()
 * column-definition block directly, exactly like every other
 * governance test in this directory (see
 * MigrationExpandContractDisciplineTest for the same brace-counting
 * extraction technique) — never by connecting to a live database.
 */
class RowLevelSecurityNoTenantColumnStructuralTest extends TestCase
{
    /**
     * @return array<int, array{0: string, 1: string}> [table, migration filename glob]
     */
    public static function newExemptionTableProvider(): array
    {
        return [
            'module_catalog' => ['module_catalog', '*_create_module_catalog_table.php'],
            'readiness_scorecard_components' => ['readiness_scorecard_components', '*_create_readiness_scorecard_components_table.php'],
        ];
    }

    #[DataProvider('newExemptionTableProvider')]
    public function test_new_exemption_table_has_no_firm_referencing_column(string $table, string $migrationGlob): void
    {
        $matches = glob(database_path('migrations/'.$migrationGlob)) ?: [];

        $this->assertCount(1, $matches, "Expected exactly one create_{$table}_table migration.");

        $source = file_get_contents($matches[0]);
        $this->assertNotFalse($source);

        $columnBlock = $this->extractSchemaCreateBlock($source, $table);

        $this->assertNotNull(
            $columnBlock,
            "Could not locate Schema::create('{$table}', ...) block in {$matches[0]}."
        );

        // No firm_id column of any nullability.
        $this->assertDoesNotMatchRegularExpression(
            '/[\'"]firm_id[\'"]/',
            $columnBlock,
            "{$table} must not declare a firm_id column (structural proof for its EXEMPT_TABLES exemption)."
        );

        // No FK pointed at firms via ->constrained('firms') or
        // ->references(...)->on('firms'), under any column name.
        $this->assertDoesNotMatchRegularExpression(
            '/constrained\(\s*[\'"]firms[\'"]/',
            $columnBlock,
            "{$table} must not declare a foreign key constrained against firms."
        );

        $this->assertDoesNotMatchRegularExpression(
            '/->on\(\s*[\'"]firms[\'"]/',
            $columnBlock,
            "{$table} must not declare a foreign key referencing firms."
        );

        // General sweep: no column name containing "firm" anywhere in
        // the create block (catches firm_uuid, owning_firm_id, etc.,
        // not just the literal firm_id spelling).
        $this->assertDoesNotMatchRegularExpression(
            '/\$table->[a-zA-Z]+\([\'"][a-zA-Z_]*firm[a-zA-Z_]*[\'"]/i',
            $columnBlock,
            "{$table} must not declare any firm-referencing column."
        );
    }

    /**
     * Extracts the source text between `Schema::create('$table', function
     * (Blueprint $table) {` and its matching closing `});` by
     * brace-counting, mirroring MigrationExpandContractDisciplineTest's
     * extractMethodBody() technique.
     */
    private function extractSchemaCreateBlock(string $source, string $table): ?string
    {
        $needle = "Schema::create('".$table."'";

        $offset = strpos($source, $needle);

        if ($offset === false) {
            return null;
        }

        $braceStart = strpos($source, '{', $offset);

        if ($braceStart === false) {
            return null;
        }

        $depth = 1;
        $length = strlen($source);
        $pos = $braceStart + 1;

        while ($pos < $length && $depth > 0) {
            if ($source[$pos] === '{') {
                $depth++;
            } elseif ($source[$pos] === '}') {
                $depth--;
            }
            $pos++;
        }

        if ($depth !== 0) {
            return null;
        }

        return substr($source, $braceStart + 1, $pos - $braceStart - 2);
    }
}
