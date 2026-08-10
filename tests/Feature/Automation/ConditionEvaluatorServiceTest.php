<?php

namespace Tests\Feature\Automation;

use App\Enums\DomainEventType;
use App\Services\Automation\ConditionEvaluatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ConditionEvaluatorServiceTest — Event-Driven Automation Engine, item
 * 5 + item 17 (security). Proves the closed condition vocabulary: all
 * 12 operators evaluate correctly (including DaysSince/DaysUntil's
 * signed-diff fix), the field allowlist is enforced per event type, an
 * unknown operator throws, and a malformed/mismatched VALUE fails the
 * clause instead of throwing (never takes down the whole sweep).
 */
class ConditionEvaluatorServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConditionEvaluatorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ConditionEvaluatorService;
    }

    private function evalOne(string $field, string $operator, mixed $value, array $payload, DomainEventType $type = DomainEventType::InvoiceOverdue): bool
    {
        return $this->service->evaluate($type, [['field' => $field, 'operator' => $operator, 'value' => $value]], $payload)['matched'];
    }

    // ------------------------------------------------------------
    // Allowlist / structural safety
    // ------------------------------------------------------------

    public function test_field_not_on_the_allowlist_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->evaluate(
            DomainEventType::InvoiceOverdue,
            [['field' => 'invoice.stripe_secret_key', 'operator' => 'equals', 'value' => 'x']],
            ['invoice' => ['stripe_secret_key' => 'sk_live_x']],
        );
    }

    public function test_field_allowed_for_a_different_event_type_is_still_rejected(): void
    {
        // 'deadline.title' is allowed for deadline_approaching, not invoice_overdue.
        $this->expectException(\InvalidArgumentException::class);

        $this->service->evaluate(
            DomainEventType::InvoiceOverdue,
            [['field' => 'deadline.title', 'operator' => 'equals', 'value' => 'x']],
            ['deadline' => ['title' => 'x']],
        );
    }

    public function test_unknown_operator_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->evaluate(
            DomainEventType::InvoiceOverdue,
            [['field' => 'invoice.days_overdue', 'operator' => 'passthru', 'value' => 7]],
            ['invoice' => ['days_overdue' => 10]],
        );
    }

    public function test_missing_field_key_in_payload_fails_the_clause_rather_than_throwing(): void
    {
        $result = $this->evalOne('invoice.days_overdue', 'greater_than', 5, ['invoice' => ['status' => 'open']]);

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------
    // Operators
    // ------------------------------------------------------------

    public function test_equals(): void
    {
        $this->assertTrue($this->evalOne('invoice.status', 'equals', 'open', ['invoice' => ['status' => 'open']]));
        $this->assertFalse($this->evalOne('invoice.status', 'equals', 'open', ['invoice' => ['status' => 'closed']]));
    }

    public function test_not_equals(): void
    {
        $this->assertTrue($this->evalOne('invoice.status', 'not_equals', 'closed', ['invoice' => ['status' => 'open']]));
        $this->assertFalse($this->evalOne('invoice.status', 'not_equals', 'open', ['invoice' => ['status' => 'open']]));
    }

    public function test_greater_than(): void
    {
        $this->assertTrue($this->evalOne('invoice.days_overdue', 'greater_than', 5, ['invoice' => ['days_overdue' => 10]]));
        $this->assertFalse($this->evalOne('invoice.days_overdue', 'greater_than', 10, ['invoice' => ['days_overdue' => 10]]));
        $this->assertFalse($this->evalOne('invoice.days_overdue', 'greater_than', 'ten', ['invoice' => ['days_overdue' => 10]]));
    }

    public function test_greater_than_or_equal(): void
    {
        $this->assertTrue($this->evalOne('invoice.days_overdue', 'greater_than_or_equal', 10, ['invoice' => ['days_overdue' => 10]]));
        $this->assertFalse($this->evalOne('invoice.days_overdue', 'greater_than_or_equal', 11, ['invoice' => ['days_overdue' => 10]]));
    }

    public function test_less_than(): void
    {
        $this->assertTrue($this->evalOne('invoice.days_overdue', 'less_than', 20, ['invoice' => ['days_overdue' => 10]]));
        $this->assertFalse($this->evalOne('invoice.days_overdue', 'less_than', 5, ['invoice' => ['days_overdue' => 10]]));
    }

    public function test_contains(): void
    {
        $this->assertTrue($this->evalOne('invoice.bucket', 'contains', 'day', ['invoice' => ['bucket' => '61-90 days']]));
        $this->assertFalse($this->evalOne('invoice.bucket', 'contains', 'week', ['invoice' => ['bucket' => '61-90 days']]));
        // Empty needle never vacuously matches.
        $this->assertFalse($this->evalOne('invoice.bucket', 'contains', '', ['invoice' => ['bucket' => '61-90 days']]));
    }

    public function test_in(): void
    {
        $this->assertTrue($this->evalOne('invoice.status', 'in', ['open', 'overdue'], ['invoice' => ['status' => 'overdue']]));
        $this->assertFalse($this->evalOne('invoice.status', 'in', ['open', 'overdue'], ['invoice' => ['status' => 'paid']]));
    }

    public function test_not_in(): void
    {
        $this->assertTrue($this->evalOne('invoice.status', 'not_in', ['paid', 'void'], ['invoice' => ['status' => 'overdue']]));
        $this->assertFalse($this->evalOne('invoice.status', 'not_in', ['paid', 'void'], ['invoice' => ['status' => 'paid']]));
    }

    public function test_is_null(): void
    {
        $this->assertTrue($this->evalOne('matter.id', 'is_null', null, ['matter' => ['id' => null]]));
        $this->assertFalse($this->evalOne('matter.id', 'is_null', null, ['matter' => ['id' => 5]]));
    }

    public function test_is_not_null(): void
    {
        $this->assertTrue($this->evalOne('matter.id', 'is_not_null', null, ['matter' => ['id' => 5]]));
        $this->assertFalse($this->evalOne('matter.id', 'is_not_null', null, ['matter' => ['id' => null]]));
    }

    public function test_days_since_matches_a_past_date_and_not_a_future_one(): void
    {
        $tenDaysAgo = now()->subDays(10)->toIso8601String();
        $tenDaysFromNow = now()->addDays(10)->toIso8601String();

        $this->assertTrue($this->evalOne('invoice.days_overdue', 'days_since', 7, ['invoice' => ['days_overdue' => $tenDaysAgo]]));
        $this->assertFalse($this->evalOne('invoice.days_overdue', 'days_since', 7, ['invoice' => ['days_overdue' => $tenDaysFromNow]]));
    }

    public function test_days_until_matches_a_future_date_within_window_and_not_a_past_one(): void
    {
        $inFiveDays = now()->addDays(5)->toIso8601String();
        $fiveDaysAgo = now()->subDays(5)->toIso8601String();

        $this->assertTrue($this->evalOne(
            'deadline.due_at', 'days_until', 7, ['deadline' => ['due_at' => $inFiveDays]], DomainEventType::DeadlineApproaching,
        ));
        $this->assertFalse($this->evalOne(
            'deadline.due_at', 'days_until', 7, ['deadline' => ['due_at' => $fiveDaysAgo]], DomainEventType::DeadlineApproaching,
        ));
    }

    public function test_days_since_with_unparseable_date_fails_the_clause_rather_than_throwing(): void
    {
        $result = $this->evalOne('invoice.days_overdue', 'days_since', 7, ['invoice' => ['days_overdue' => 'not-a-date']]);

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------
    // Multi-clause AND semantics
    // ------------------------------------------------------------

    public function test_multiple_clauses_are_and_combined(): void
    {
        $payload = ['invoice' => ['status' => 'overdue', 'days_overdue' => 10]];

        $result = $this->service->evaluate(DomainEventType::InvoiceOverdue, [
            ['field' => 'invoice.status', 'operator' => 'equals', 'value' => 'overdue'],
            ['field' => 'invoice.days_overdue', 'operator' => 'greater_than_or_equal', 'value' => 7],
        ], $payload);

        $this->assertTrue($result['matched']);
        $this->assertCount(2, $result['evaluated']);

        $result2 = $this->service->evaluate(DomainEventType::InvoiceOverdue, [
            ['field' => 'invoice.status', 'operator' => 'equals', 'value' => 'overdue'],
            ['field' => 'invoice.days_overdue', 'operator' => 'greater_than_or_equal', 'value' => 30],
        ], $payload);

        $this->assertFalse($result2['matched']);
    }

    public function test_empty_conditions_always_match(): void
    {
        $result = $this->service->evaluate(DomainEventType::InvoiceOverdue, [], ['invoice' => ['status' => 'overdue']]);

        $this->assertTrue($result['matched']);
        $this->assertSame([], $result['evaluated']);
    }
}
