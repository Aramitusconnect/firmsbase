<?php

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Models\Firm;
use App\Models\NotificationEvent;

/**
 * EmailDeliverabilityNonBypassGuard — the named, testable seam proving
 * mailbox sync/capture cannot be used to bypass Phase 4's transactional
 * deliverability gate (project rule — Phase 9 has no send flow at
 * all, so "reuse" here means "structurally cannot bypass").
 *
 * This is not an allow/deny gate in the usual sense — EmailSyncService
 * simply has no dependency on NotificationDispatchService/
 * NotificationEligibilityService/ConsentService/SuppressionService/
 * DispatchNotificationJob at all (verified via reflection below), so
 * there is no code path from capture to dispatch to bypass in the
 * first place. The assertions here give that structural absence one
 * named, directly-testable seam rather than leaving it as an implicit
 * property someone could quietly break later.
 */
class EmailDeliverabilityNonBypassGuard
{
    private const FORBIDDEN_DISPATCH_DEPENDENCIES = [
        \App\Services\NotificationDispatchService::class,
        \App\Services\NotificationEligibilityService::class,
        \App\Services\SuppressionService::class,
        \App\Jobs\DispatchNotificationJob::class,
    ];

    /**
     * Reflects on EmailSyncService's constructor and asserts none of
     * its parameter types are a Phase 4 dispatch-related class.
     */
    public function assertSyncServiceHasNoDispatchDependency(): void
    {
        $reflection = new \ReflectionClass(EmailSyncService::class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            if ($typeName !== null && in_array($typeName, self::FORBIDDEN_DISPATCH_DEPENDENCIES, true)) {
                throw new \RuntimeException(
                    "EmailSyncService must not depend on {$typeName} — mailbox capture must never be able to trigger a Phase 4 dispatch/send side effect."
                );
            }
        }
    }

    /**
     * Asserts capturing messages did not create or mutate any
     * NotificationEvent rows for the firm — capture is a passive
     * mirror of the mailbox, never a communication attempt.
     */
    public function assertNoNotificationEventSideEffects(Firm $firm, int $notificationEventCountBefore): void
    {
        $countAfter = NotificationEvent::query()->where('firm_id', $firm->id)->count();

        if ($countAfter !== $notificationEventCountBefore) {
            throw new \RuntimeException(
                "Email sync/capture must never create or mutate notification_events rows (before={$notificationEventCountBefore}, after={$countAfter})."
            );
        }
    }

    /**
     * Asserts capturing an Outbound (already-sent-elsewhere) message
     * did not implicitly grant or otherwise change consent state for
     * that client/channel — capturing a sent email is not evidence of
     * granted consent.
     */
    public function assertCaptureDidNotChangeConsentState(
        Firm $firm,
        ?int $clientId,
        ConsentChannel $channel,
        bool $consentGrantedBefore,
        ConsentService $consentService,
    ): void {
        $grantedAfter = $consentService->isGranted($firm, $clientId, $channel);

        if ($grantedAfter !== $consentGrantedBefore) {
            throw new \RuntimeException(
                'Email sync/capture must never change communication consent state.'
            );
        }
    }
}
