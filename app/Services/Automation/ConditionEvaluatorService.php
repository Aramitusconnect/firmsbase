<?php

namespace App\Services\Automation;

use App\Enums\AutomationConditionOperator;
use App\Enums\DomainEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * ConditionEvaluatorService — Event-Driven Automation Engine, item 5.
 * Evaluates an AutomationRule's conditions_json (an array of
 * {field, operator, value} clauses, AND-combined — no nested OR groups
 * in this pass, a deliberate scope limit) against a DomainEvent's own
 * payload_json.
 *
 * Every field is checked against AutomationFieldAllowlistRegistry
 * before use — never a raw Eloquent attribute lookup, never
 * ->{$field}, never reflection. `operator` must be a real
 * AutomationConditionOperator case. Both are structural rule-authoring
 * requirements: AutomationRuleService validates them at save time; this
 * service re-validates them at evaluation time as a defensive backstop
 * (only reachable if a rule row were tampered with directly) and THROWS
 * on either failure — a clear, loud, auditable failure via the
 * execution engine's own try/catch, never a silent no-op.
 *
 * A malformed/mismatched VALUE (wrong type, unparseable date, etc.) is
 * a data-dependent condition, not a structural one — fails the clause
 * (evaluates false) rather than throwing, so one payment/invoice/etc.
 * with an unexpected shape can never take down the whole sweep.
 */
class ConditionEvaluatorService
{
    /**
     * @param  array<int, array{field: string, operator: string, value: mixed}>  $conditions
     * @param  array<string, mixed>  $payload
     * @return array{matched: bool, evaluated: array<int, array{field: string, operator: string, expected: mixed, actual: mixed, result: bool}>}
     */
    public function evaluate(DomainEventType $type, array $conditions, array $payload): array
    {
        $flat = Arr::dot($payload);
        $evaluated = [];
        $matched = true;

        foreach ($conditions as $clause) {
            $field = $clause['field'] ?? null;

            if (! is_string($field) || ! AutomationFieldAllowlistRegistry::isAllowed($type, $field)) {
                throw new \InvalidArgumentException(
                    'Condition field ['.(is_string($field) ? $field : json_encode($field))."] is not on the allowlist for event type {$type->value}."
                );
            }

            $operator = AutomationConditionOperator::tryFrom((string) ($clause['operator'] ?? ''));

            if ($operator === null) {
                throw new \InvalidArgumentException('Unknown condition operator ['.(string) ($clause['operator'] ?? '').'].');
            }

            $expected = $clause['value'] ?? null;
            $actual = $flat[$field] ?? null;
            $result = $this->evaluateClause($operator, $actual, $expected);

            $evaluated[] = [
                'field' => $field,
                'operator' => $operator->value,
                'expected' => $expected,
                'actual' => $actual,
                'result' => $result,
            ];

            $matched = $matched && $result;
        }

        return ['matched' => $matched, 'evaluated' => $evaluated];
    }

    private function evaluateClause(AutomationConditionOperator $operator, mixed $actual, mixed $expected): bool
    {
        return match ($operator) {
            AutomationConditionOperator::Equals => $actual == $expected,
            AutomationConditionOperator::NotEquals => $actual != $expected,
            AutomationConditionOperator::GreaterThan => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            AutomationConditionOperator::GreaterThanOrEqual => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            AutomationConditionOperator::LessThan => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            AutomationConditionOperator::Contains => is_string($actual) && is_string($expected) && $expected !== '' && str_contains($actual, $expected),
            AutomationConditionOperator::In => is_array($expected) && in_array($actual, $expected, false),
            AutomationConditionOperator::NotIn => is_array($expected) && ! in_array($actual, $expected, false),
            AutomationConditionOperator::IsNull => $actual === null,
            AutomationConditionOperator::IsNotNull => $actual !== null,
            AutomationConditionOperator::DaysSince => $this->daysSince($actual) !== null && is_numeric($expected) && $this->daysSince($actual) >= (float) $expected,
            AutomationConditionOperator::DaysUntil => $this->daysUntil($actual) !== null && is_numeric($expected) && $this->daysUntil($actual) <= (float) $expected && $this->daysUntil($actual) >= 0,
        };
    }

    /**
     * Days elapsed since $value (a date/datetime string), signed:
     * positive when $value is in the past (the normal "overdue by N
     * days" case), negative when $value is still in the future (so
     * ">= N" for any positive N correctly never matches a date that
     * hasn't happened yet). Null for anything that doesn't parse as a
     * date — a malformed payload value fails the clause rather than
     * throwing.
     */
    private function daysSince(mixed $value): ?float
    {
        $date = $this->parseDate($value);

        return $date === null ? null : $date->diffInHours(now(), false) / 24;
    }

    /**
     * Days from now until $value, signed: positive when $value is in
     * the future (the normal "due in N days" case), negative when
     * $value is already in the past.
     */
    private function daysUntil(mixed $value): ?float
    {
        $date = $this->parseDate($value);

        return $date === null ? null : now()->diffInHours($date, false) / 24;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
