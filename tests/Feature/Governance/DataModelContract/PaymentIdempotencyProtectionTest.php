<?php

namespace Tests\Feature\Governance\DataModelContract;

use App\Models\Payment;
use Tests\TestCase;

/**
 * PaymentIdempotencyProtectionTest — regression test proving Section
 * 26 did not touch the payments idempotency mechanism in any way.
 */
class PaymentIdempotencyProtectionTest extends TestCase
{
    public function test_payment_model_still_has_idempotency_key_fillable(): void
    {
        $payment = new Payment();

        $this->assertContains('idempotency_key', $payment->getFillable());
    }

    public function test_payments_migration_still_declares_idempotency_key_column(): void
    {
        $path = database_path('migrations/2026_07_06_700009_create_payments_table.php');
        $this->assertFileExists($path);

        $source = file_get_contents($path);

        $this->assertStringContainsString("\$table->string('idempotency_key')", $source);
    }

    public function test_payments_migration_still_declares_the_partial_unique_index(): void
    {
        $path = database_path('migrations/2026_07_06_700009_create_payments_table.php');
        $source = file_get_contents($path);

        $this->assertStringContainsString('CREATE UNIQUE INDEX', $source);
        $this->assertStringContainsString('payments_one_per_firm_idempotency_key', $source);
        $this->assertStringContainsString('firm_id, idempotency_key', $source);
        $this->assertStringContainsString('WHERE idempotency_key IS NOT NULL', $source);
    }

    public function test_payment_migration_file_was_not_modified_by_section_26(): void
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- database/migrations/2026_07_06_700009_create_payments_table.php'
        ));

        $this->assertSame('', $changed);
    }

    public function test_payment_model_file_was_not_modified_by_section_26(): void
    {
        $changed = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files --modified --others --exclude-standard -- app/Models/Payment.php'
        ));

        $this->assertSame('', $changed);
    }
}
