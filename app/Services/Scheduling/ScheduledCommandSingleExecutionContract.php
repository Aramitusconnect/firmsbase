<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

/**
 * The explicit, reviewable answer to one question: if two scheduler hosts run
 * at the same minute, which scheduled commands must still execute only once?
 *
 * This exists because the EC2 -> ECS cutover readiness assessment found the
 * schedule used `withoutOverlapping()` 18 times and `onOneServer()` zero
 * times. Those solve different problems: `withoutOverlapping()` stops a task
 * overlapping ITSELF, `onOneServer()` stops the same task running on MULTIPLE
 * HOSTS. During any window where the EC2 and ECS schedulers both exist, only
 * the second one is protection.
 *
 * The mapping is deliberately data, not a blanket rule, so adding a scheduled
 * command forces a visible risk decision instead of inheriting one.
 * ScheduledCommandSingleExecutionContractTest asserts the real schedule in
 * bootstrap/app.php matches this table in both directions.
 *
 * Risk classes:
 *   P0 duplicate execution could move money, touch trust/IOLTA state, issue a
 *      provider command, or otherwise cause irreversible external effect
 *   P1 duplicate execution causes meaningful business or audit side effects
 *   P2 operationally undesirable but idempotent and self-correcting
 *   P3 harmless, or duplication is actually the desired behaviour
 *
 * IMPORTANT — necessary but NOT sufficient. `onOneServer()` is a cache lock: it
 * only excludes across hosts when those hosts share one lock store AND one key
 * namespace (see SHARED_LOCK_STORE_REQUIREMENTS). Where a command's own domain
 * layer already claims work atomically that is recorded in `layer_2`, so a
 * reviewer can see what survives a scheduler misconfiguration — and which
 * commands have nothing behind it.
 */
final class ScheduledCommandSingleExecutionContract
{
    /**
     * Settings that MUST be identical on every scheduler host during any
     * overlap window, or `onOneServer()` silently degrades to "both hosts run
     * it" — the lock just lands in two namespaces.
     *
     * CACHE_PREFIX is the sharp edge: config/cache.php derives it from APP_NAME
     * when unset, so two hosts on the same Redis but with a different APP_NAME
     * do not share locks.
     *
     * @var list<string>
     */
    public const SHARED_LOCK_STORE_REQUIREMENTS = [
        'CACHE_STORE',
        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_DB',
        'CACHE_PREFIX',
        'APP_NAME',
    ];

    /**
     * @return array<string, array{risk: string, on_one_server: bool, without_overlapping: bool, layer_2: string, rationale: string}>
     */
    public static function definitions(): array
    {
        return [
            'integrations:outbox:dispatch' => [
                'risk' => 'P0',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'IntegrationOutboxEventService::claim() — FOR UPDATE SKIP LOCKED with lock_token and RETURNING.',
                'rationale' => 'Dispatches provider commands to external systems. Layer 2 makes double-processing of a row impossible, but an external provider command is irreversible and should not rely on one defence.',
            ],
            'integrations:sync:retry-poll' => [
                'risk' => 'P0',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'Per-connection sync jobs carry their own cursor/claim handling.',
                'rationale' => 'Re-drives provider sync attempts; duplicate polling can duplicate outbound provider calls.',
            ],
            'integrations:webhooks:renew-subscriptions' => [
                'risk' => 'P0',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'None beyond the per-subscription expiry-window check.',
                'rationale' => 'Issues renewal calls to Microsoft Graph. Duplicate renewals are duplicate commands against a third party.',
            ],
            'automation:events:dispatch' => [
                'risk' => 'P1',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'DomainEventClaimService — FOR UPDATE SKIP LOCKED.',
                'rationale' => 'Drives the automation pipeline, which produces customer-visible notifications.',
            ],
            'automation:actions:dispatch' => [
                'risk' => 'P1',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'AutomationActionExecutionClaimService — FOR UPDATE SKIP LOCKED.',
                'rationale' => 'Executes automation actions; same reasoning as the event dispatcher.',
            ],
            'automation:sweep:invoice-overdue' => [
                'risk' => 'P1',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'NONE ATOMIC — DomainEvent::exists() check-then-record; no unique index on domain_events.',
                'rationale' => 'Billing-adjacent and customer-visible: a duplicate InvoiceOverdue event becomes a duplicate overdue notification. The existence check is not atomic, so this depends on single execution.',
            ],
            'automation:sweep:deadlines' => [
                'risk' => 'P1',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'NONE ATOMIC — same check-then-record shape as the invoice sweep.',
                'rationale' => 'Duplicate deadline events become duplicate notifications about legal deadlines.',
            ],
            'automation:sweep:matter-budgets' => [
                'risk' => 'P1',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'NONE ATOMIC.',
                'rationale' => 'Budget threshold alerts are financial signals shown to firms; duplicates erode trust in the alerting.',
            ],
            'automation:sweep:document-request-reminders' => [
                'risk' => 'P1',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'NONE ATOMIC — existence check before reminder emission.',
                'rationale' => 'Duplicate reminders are sent to clients.',
            ],
            'automation:sweep:leverage-recommendations' => [
                'risk' => 'P1',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'NONE ATOMIC.',
                'rationale' => 'Produces firm-visible recommendations; duplicates are user-visible noise.',
            ],
            'integrations:retention:sweep' => [
                'risk' => 'P1',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'Retention deletion bounded by its own age predicate.',
                'rationale' => 'Deletes data on a retention schedule; deletion is irreversible and concurrent sweeps should not race.',
            ],
            'marketplace:intakes:retention:sweep' => [
                'risk' => 'P1',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'Age-predicate bounded, same shape as the integrations retention sweep.',
                'rationale' => 'Deletes marketplace intake records on a retention schedule.',
            ],
            'integrations:platform-overview:refresh' => [
                'risk' => 'P2',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'Idempotent snapshot rebuild into a summary table.',
                'rationale' => 'Recomputing the same snapshot twice is correct but wasteful; single execution avoids doubling query load for no benefit.',
            ],
            'integrations:platform-provider-health:refresh' => [
                'risk' => 'P2',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'Idempotent snapshot rebuild.',
                'rationale' => 'Same as the platform overview refresh.',
            ],
            'health:checks:run' => [
                'risk' => 'P2',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'Each run records its own health_checks rows.',
                'rationale' => 'Duplicate runs double-write health rows and make Service Health noisier, but nothing is corrupted.',
            ],
            'marketplace:analytics:prune' => [
                'risk' => 'P2',
                'on_one_server' => true,
                'without_overlapping' => true,
                'layer_2' => 'Age-predicate bounded prune.',
                'rationale' => 'Pruning the same rows twice is a no-op, but concurrent prunes waste work.',
            ],
            'scheduler:heartbeat:record' => [
                'risk' => 'P3',
                'on_one_server' => false,
                'without_overlapping' => true,
                'layer_2' => 'Not applicable — an observability signal, not business state.',
                'rationale' => 'Intentionally allowed to run on every scheduler host. If both EC2 and ECS schedulers are live this is the signal that reveals it; single-serving it would mask a split-brain rather than prevent one.',
            ],
        ];
    }

    /** @return list<string> */
    public static function commandsRequiringSingleServer(): array
    {
        return array_keys(array_filter(
            self::definitions(),
            static fn (array $d): bool => $d['on_one_server'],
        ));
    }

    /** @return list<string> */
    public static function commandsWithRisk(string $risk): array
    {
        return array_keys(array_filter(
            self::definitions(),
            static fn (array $d): bool => $d['risk'] === $risk,
        ));
    }

    /**
     * Commands whose only real protection is the scheduler itself — nothing
     * atomic behind them. These make a shared, correctly-namespaced lock store
     * a hard cutover prerequisite rather than a nice-to-have.
     *
     * @return list<string>
     */
    public static function commandsWithoutAtomicLayer2(): array
    {
        return array_keys(array_filter(
            self::definitions(),
            static fn (array $d): bool => str_starts_with($d['layer_2'], 'NONE ATOMIC'),
        ));
    }
}
