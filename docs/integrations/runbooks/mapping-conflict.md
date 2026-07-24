# Runbook: Mapping Conflict

## Symptom

A sync operation fails with `ExternalMappingConflictException` — a local record is already mapped to a *different* external object for the same connection than the one the current operation is trying to map it to.

## Real source involved

`IntegrationExternalMappingService::recordMapping()`, `ExternalMappingConflictException`, the `integration_external_mappings_local_unique` and `integration_external_mappings_external_unique` partial unique indexes.

## Diagnosis

This is a genuine data-integrity conflict, distinct from an ordinary duplicate. `recordMapping()`'s internal logic already resolves the *first* case gracefully (same external object mapped again — `firstOrCreate()`-style, returns the existing mapping). `ExternalMappingConflictException` is thrown specifically for the *second*, harder case: this exact local record is already mapped to a **different** external object for this connection — never silently swallowed the way an ordinary duplicate-insert catch would swallow it, because silently swallowing it here could mean data intended for one external record silently gets associated with the wrong one.

## Required role

Platform-plane investigation: SupportAgent+ with active support-access session, or SuperAdmin/PlatformAdmin/ImplementationSpecialist — this requires understanding which mapping is correct, a judgment call that should not be made by an automated process.

## Approved interface

No dedicated Filament UI exists for directly editing `integration_external_mappings` rows. Investigation is via direct, read-only inspection of the `integration_external_mappings` table for the connection in question (through governed platform access, never a raw unscoped query — see [suspected-cross-firm-access.md](suspected-cross-firm-access.md) for the prohibition on bypassing RLS "just to look").

## Steps

1. Identify the conflicting mapping: the local record id, the external object id it's currently mapped to, and the external object id the failed operation was trying to map it to instead.
2. Determine which mapping is actually correct — this requires domain knowledge of the specific local/external record pair, not something this framework can infer automatically. This is why the exception is never auto-resolved.
3. If the existing mapping is correct and the new operation was wrong (e.g. a duplicate/incorrect sync attempt), no database change is needed — the conflict correctly prevented a bad write.
4. If the existing mapping is actually stale/incorrect and needs to be corrected, this requires an engineering-assisted data correction — there is no operator-facing "repair this mapping" action in the framework today.

## Prohibited actions

Never manually delete or overwrite an `integration_external_mappings` row to force a new mapping through without first confirming which side is actually correct — doing so risks silently misassociating a firm's data with the wrong external record.

## Evidence to capture

Firm id, connection id, local record type/id, both external object ids (existing and attempted), which operation triggered the attempted remapping.

## Escalation condition

Any mapping conflict where the correct resolution isn't immediately obvious from the data itself should be escalated to engineering — this is a data-integrity question, not a routine operational one.

## Recovery verification

Subsequent sync operations against the same local/external record pair complete without a repeat `ExternalMappingConflictException`, using whichever mapping was determined to be correct.
