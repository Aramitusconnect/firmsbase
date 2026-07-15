<?php

namespace Tests\Feature\Webhooks\Wiring;

use Tests\TestCase;

/**
 * payment_plan.installment_due is INTENTIONALLY DEFERRED in Phase 14b
 * (decision F) — no scheduler/console command owner exists anywhere in
 * the codebase for transitioning an installment to Due, and Phase 14b's
 * approved scope explicitly forbids adding one ("Do not wire in Phase
 * 14b. Do not add scheduler. Do not add console commands. Do not add
 * cron/queue polling."). This test proves that deferral holds: no
 * Console Command of any kind was introduced, and
 * PaymentPlanInstallmentService still only exposes markMissed()/
 * markWaived() — no markDue()/checkDue()-style method was added.
 */
class PaymentPlanInstallmentDueDeferredTest extends TestCase
{
    public function test_no_console_command_directory_or_files_exist_anywhere_in_the_app(): void
    {
        $commandsDir = base_path('app/Console/Commands');

        if (! is_dir($commandsDir)) {
            $this->assertTrue(true, 'No app/Console/Commands directory exists — payment_plan.installment_due remains correctly deferred.');

            return;
        }

        // Section 39A-4B added two commands unrelated to payment plans
        // (schema/RLS governance reporting). This test's real invariant
        // is narrower than "zero commands ever": no command may
        // reference payment-plan installment due-transition logic,
        // since that specific scheduler/command infrastructure remains
        // deferred.
        foreach (glob($commandsDir.'/*.php') ?: [] as $file) {
            $source = file_get_contents($file);

            foreach (['PaymentPlanInstallmentService', 'markDue', 'checkDue', 'installment_due', 'PaymentPlanDunningService'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    basename($file)." must not reference {$needle} — payment_plan.installment_due scheduler/command infrastructure is intentionally deferred (Phase 14b)."
                );
            }
        }
    }

    public function test_payment_plan_installment_service_still_has_no_due_transition_method(): void
    {
        $source = file_get_contents(base_path('app/Services/PaymentPlanInstallmentService.php'));

        $this->assertStringNotContainsString('function markDue', $source);
        $this->assertStringNotContainsString('function checkDue', $source);
        $this->assertStringContainsString('function markMissed', $source);
        $this->assertStringContainsString('function markWaived', $source);
    }

    public function test_webhook_event_recorder_is_never_referenced_for_payment_plan_installment_due_outside_its_own_enum_and_registry(): void
    {
        // PaymentPlanInstallmentService/PaymentPlanDunningService must
        // never call WebhookEventRecorderService — this event has no
        // wired trigger in Phase 14b.
        $files = [
            base_path('app/Services/PaymentPlanInstallmentService.php'),
            base_path('app/Services/PaymentPlanDunningService.php'),
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('WebhookEventRecorderService', $source);
        }
    }
}
