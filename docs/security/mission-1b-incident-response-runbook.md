# Mission 1B (Extreme Security Hardening) — Incident Response Runbook

Operational runbook, section 51. Concise by design — this is a
containment/recovery checklist for whoever is on point during a real
incident, not a policy document. It references real mechanisms that
exist in this codebase today; where a control this runbook depends on
is `EXTERNAL_CONFIGURATION_REQUIRED` or `OWNER_DECISION_REQUIRED` (see
the Mission 1B final report), that is called out explicitly rather than
assumed.

**Not legal advice.** This runbook does not make breach-notification
timing/scope claims — those are a legal/compliance decision for the
firm's own counsel and any applicable regulatory framework, made by
people, not encoded here.

## General principles, every scenario

1. **Contain first, investigate second, recover third.** Don't let
   root-cause curiosity delay stopping active damage.
2. **Preserve evidence before you clean up.** `security_events` is
   append-only (INSERT-only RLS policy, no UPDATE/DELETE even for
   privileged roles — see `RowLevelSecurityCoverageMappingService`) —
   it survives your response by construction. Export/snapshot anything
   else you're about to delete or overwrite (a compromised admin's
   session rows, a suspicious file) before acting on it if you can do
   so without further delaying containment.
3. **Every containment action taken must itself be audited** — this
   runbook's own actions all go through mechanisms that already log to
   `security_events` or `PlatformAdminAuditEventRecorder` (Toggle
   admin status, kill switches, impersonation revocation). Don't
   bypass them with a manual DB edit unless containment is failing
   through the normal path.
4. **Escalation**: this runbook does not define an on-call rotation or
   paging policy (no such infra exists in this repository to
   reference) — that is an `OWNER_DECISION_REQUIRED` item. Until one
   exists, the Platform Admin(s) with SuperAdmin role are the
   containment authority.

---

## 1. Suspected Firm-user or Client Portal-user account takeover

**Signals:** repeated failed logins now visible via
`AccountLoginThrottleService`'s throttled state, an unfamiliar
IP/device on a sensitive action, a user reporting they didn't perform
an action.

**Containment:**
- Force a password reset for the account (existing password-reset
  flow — the reset itself invalidates the old password immediately).
- Revoke all of that user's sessions: `app(App\Services\Security\
  SessionRevocationService::class)->revokeAllSessionsFor($user,
  'web')` (or `'client'` for Client Portal) via `php artisan tinker`
  or a dedicated Action if built later — deletes every session row for
  that guard+user, immediate effect (see `SessionRevocationServiceTest`).
- If the user is a Firm member: consider whether their `FirmUser`
  membership should be suspended (`FirmUserStatus::Suspended`) pending
  investigation — blocks tenant-context login attempts via
  `LoginPolicyService::canAttemptFirmLogin()`'s ACTIVE-only check.

**Evidence:** `security_events` rows for `login_failed`/
`login_succeeded` on that account (guard, IP, user agent, timestamp).

**Recovery:** new password set by the legitimate user through the
normal reset flow; re-enable membership once cleared.

---

## 2. Platform Admin account compromise

**Signals:** a WebAuthn/TOTP MFA event the admin didn't perform, an
admin action the admin denies taking, a leaked admin credential.

**Containment, in order:**
1. **Deactivate the admin immediately**:
   `TogglePlatformAdminActiveStatusAction` (Platform Administrators
   resource → target admin → Deactivate). This now does two things at
   once (Mission 1B): flips `is_active=false` (denies `canAccessPanel()`
   on the next request) **and** calls `SessionRevocationService` to
   delete every one of that admin's session rows immediately — no
   window where an already-open session keeps working.
   `PlatformRoleService::wouldLeaveNoActiveSuperAdmin()` will block
   this if it's the sole active SuperAdmin — grant SuperAdmin to
   another trusted admin first if that happens.
2. **Remove their MFA credentials** so a re-activation later requires
   fresh enrollment: `ResetPlatformAdminMfaAction` for TOTP, or delete
   their `WebauthnCredential` rows directly for passkeys/security keys
   (each deletion is itself step-up-gated per-credential, see
   `DisableWebAuthnCredentialAction` — a SuperAdmin doing this on
   another admin's behalf may need a direct DB action if that admin
   cannot self-service; treat this as a documented emergency exception,
   audited via `PlatformAdminAuditEventRecorder`).
3. **Review recent impersonation history**: `SupportAccessSession`
   rows actor'd by this admin — any in-progress session should be
   revoked via `SupportAccessSessionService::revoke()`, which is
   already audited and bounded (auto-expiring `expires_at`).

**Evidence:** every admin action already flows through
`PlatformAdminAuditEventRecorder` — pull the full action history for
the compromised admin's ID before/during containment.

**Recovery:** re-activate only after credential rotation, fresh MFA
enrollment (WebAuthn preferred per section 4/5), and a real explanation
of how the compromise happened.

---

## 3. OAuth integration credential leak (Google Workspace / Microsoft 365 / Plaid)

**Signals:** a leaked `integration_credentials` value, evidence of
provider-side unauthorized access, a provider security notification.

**Containment:**
1. **Flip the provider kill switch immediately**:
   `ProviderKillSwitchResource` → create a `LEVEL_PROVIDER`-scope kill
   switch for the affected `ProviderKey` (`googleworkspace`/
   `microsoft365`/`plaid`). `ProviderRequestExecutor::send()` checks
   this on every outbound call for every operation of that provider —
   this is the fastest full-stop available and requires no code
   deploy.
2. **Revoke the credential at the provider** (Google/Microsoft admin
   console token revocation, Plaid dashboard item removal) — this
   application's own token storage being disabled doesn't revoke
   provider-side access on its own. Note the audit's disclosed
   Microsoft 365 limitation: it does **not** implement
   `SupportsDisconnectContract` — provider-side revocation for that
   integration must happen directly in the Microsoft admin console,
   not through this app.
3. **Rotate the OAuth client secret** for the affected provider
   (`EXTERNAL_CONFIGURATION_REQUIRED` — done in each provider's own
   developer console, then the new secret pushed to AWS Secrets
   Manager, never committed to the repository).

**Evidence:** `integration_provider_webhook_subscriptions`/audit trail
for the connection; provider-side access logs (external to this app).

**Recovery:** re-enable the kill switch only after the secret is
rotated and re-provisioned via Secrets Manager.

---

## 4. AWS credential leak (IAM user/role key, ECS task role, CI/CD credential)

**Signals:** a key committed to a public repo, unexpected AWS API
activity, a secret-scanning alert.

**Containment:**
1. **Deactivate/delete the leaked IAM credential immediately** in the
   AWS Console/CLI — this is the single highest-priority action and
   cannot wait for anything else in this list.
2. If it was a long-lived IAM user access key (this application's
   CI/CD is OIDC-only per this mission's audit — no static keys exist
   in GitHub Actions today, but a leaked human IAM user key is still
   possible): rotate/delete it and review CloudTrail (once enabled —
   see the Mission 1B final report's CloudTrail status) for actions
   taken with it.
3. If an ECS task role's temporary credentials were somehow exposed
   (e.g., via an SSRF reaching the instance metadata service): those
   expire on their own (typically within hours) but review what the
   compromised task could reach — task roles in this Terraform are
   scoped per-service (execution vs. task role separated, see this
   mission's ECS/IAM findings) rather than one broad role.
4. **STOP and escalate to the account owner** for any credential
   rotation touching production IAM policy or trust relationships —
   per this mission's own stop conditions, destructive/production IAM
   changes are not something to improvise mid-incident without owner
   sign-off on the specific change.

**Evidence:** CloudTrail (once enabled), AWS GuardDuty findings (once
enabled), IAM Access Analyzer findings for the affected principal.

---

## 5. Ransomware / destructive activity (mass deletion, mass encryption, mass data mutation)

**Signals:** sudden abnormal volume of deletions/updates, files
becoming inaccessible, a ransom note/message.

**Containment:**
1. **Revoke sessions and deactivate the account(s) performing the
   destructive activity** (see scenarios 1/2 above, whichever applies)
   — stop the bleeding before anything else.
2. **Do not restore over production yet** — first determine the blast
   radius (which tables/tenants/time window) using `security_events`
   and application logs, since restoring immediately can overwrite the
   only evidence of what happened and how.
3. Engage AWS Backup / RDS point-in-time recovery for restoration
   planning (see this mission's backup/restore posture findings) —
   restoration itself happens in a **non-production** environment
   first for verification, per section 50's own instruction, never
   directly over production as the first step.
4. If the activity is FORCE-RLS-scoped to specific tenants: RLS
   containment (Firm-user suspension, tenant-level lockout) is the
   fastest partial stop while a full restore is planned.

**Evidence:** `security_events`, database WAL/backup timestamps
bracketing the event, CloudWatch logs for the time window.

**Recovery:** planned, verified restore (non-production test first) —
this is an `OWNER_DECISION_REQUIRED` action given its destructive
potential if done wrong.

---

## 6. Database compromise (unauthorized direct DB access, not through the application)

**Signals:** RDS access from an unexpected source, a credential used
outside the application's own connection pattern, `pg_stat_activity`
showing an unfamiliar role/session.

**Containment:**
1. Rotate the compromised database credential immediately (Secrets
   Manager) — the application picks up the new credential on its next
   connection cycle.
2. Review RDS security-group ingress — this mission's audit confirmed
   RDS has no Terraform representation in this repository (it predates
   this IaC) and is only partially security-group-visible; tightening
   or reviewing its actual live ingress rules is an AWS Console/CLI
   action, `EXTERNAL_CONFIGURATION_REQUIRED`.
3. Confirm FORCE RLS wasn't bypassed — no `BYPASSRLS`/`SECURITY
   DEFINER` mechanism exists anywhere in this schema (confirmed by
   this mission's adversarial RLS review), so a compromise that
   respects normal `psql` connection semantics still can't read across
   tenants without also holding a role with `BYPASSRLS`, which no
   application role has.

**Evidence:** RDS audit logs (if enabled — verify via the AWS Console,
this is not something the application layer can confirm),
CloudTrail for any `rds:*` API calls.

---

## 7. Cross-tenant data exposure (a Firm sees another Firm's data)

**Signals:** a user report, an automated RLS/tenant-boundary test
failure, an anomalous cross-firm query in logs.

**Containment:**
1. Identify the code path immediately — is FORCE RLS active on the
   affected table (`RowLevelSecurityCoverageMappingService::
   forcedTables()`)? If not, this is a genuine coverage gap requiring
   an emergency FORCE RLS migration, not a bypass-of-something-working.
2. If FORCE RLS is active but a specific service/job is missing tenant
   context (the exact class of bug `ScanDocumentJob` had before this
   mission fixed it): the safe emergency mitigation is disabling that
   specific feature/queue (Laravel queue `pause`/worker stop) while a
   fix is prepared — not a broad database change.
3. Determine what was actually exposed (which tenant's data, to whom,
   for how long) before deciding on next steps — this scopes both the
   technical fix and any downstream notification decision (a decision
   for the firm's own legal/compliance function, not this runbook).

**Evidence:** application logs for the affected request(s),
`security_events` for any RLS denial-spike signal (see this mission's
governance findings for how that's surfaced).

---

## 8. Malicious file upload

**Signals:** a malware-scan hit (once real scanning is enabled — see
the Mission 1B final report's file-security findings; today's scanner
is a disclosed stub, `FakeVirusScanner`), a suspicious filename/MIME
mismatch, a user report.

**Containment:**
1. The uploaded `Document` row's `scan_status` marks it
   quarantined/blocked — do not manually flip it to allow access
   before genuine scanning confirms it's safe.
2. Identify and quarantine (do not delete outright — evidence)
   anything downloaded from the same source before the block took
   effect.
3. If the upload surface itself needs disabling entirely as an
   emergency measure (e.g., an exploit in the upload pipeline itself,
   not just one malicious file): the one real upload surface today is
   `PlaidUploadFallbackPage` (Client Portal) — disabling it means a
   code-level feature flag or route-level block, since no generic
   "pause all uploads" kill switch exists yet (a gap this runbook
   surfaces rather than silently assumes is covered — see the Mission
   1B final report).

**Evidence:** the stored file itself (private disk, never served
before scanning per this mission's zero-trust upload findings), the
uploading user's identity and session.

---

## 9. Webhook abuse (forged/replayed webhook traffic, signature-verification bypass attempt)

**Signals:** a spike in webhook-signature failures (already logged per
this mission's webhook audit findings), an unusual volume from one
source, a provider security notice.

**Containment:**
1. The existing HMAC verification + ±300s replay window + atomic
   idempotency (`INSERT...ON CONFLICT DO NOTHING`) already reject
   forged/replayed payloads by construction — confirm the failures are
   actually being rejected, not silently accepted, before assuming a
   real bypass occurred.
2. If a real signature-verification bypass is found (not just a spike
   of correctly-rejected forgeries): this is a code-level emergency fix,
   not something an operational kill switch alone resolves — the
   `ProviderKillSwitch` (scenario 3 above) can still stop a specific
   provider's webhook ingestion while the fix ships, since it's checked
   on the outbound path; inbound webhook processing itself is not yet
   gated by the same kill switch (another gap this runbook surfaces —
   see the Mission 1B final report's "pause webhook processing" kill-
   switch status).
3. Rotate the affected provider's webhook signing secret if forgery
   (not just volume) is confirmed.

**Evidence:** `integration_webhook_receipts` (already logs
denylist-scrubbed audit detail per this mission's audit), the specific
signature-verification failure reason.

---

## 10. Payment credential compromise (payment gateway API key, bank/trust routing detail)

**Signals:** an unexpected transaction, a leaked gateway secret, a
provider fraud alert.

**Containment:**
1. Rotate the payment gateway API key immediately at the provider,
   then in Secrets Manager (`EXTERNAL_CONFIGURATION_REQUIRED` for the
   provider-side step).
2. Freeze payment-processing actions for the affected Firm(s) pending
   review — this mission does not redesign accounting rules (section
   47's own scope boundary), so this is an operational pause, not a
   change to reconciliation/ledger logic.
3. Review Trust-account-adjacent activity specifically — Trust
   accounting has its own FORCE-RLS-protected tables and dual-approval
   (`HighRiskChangeRequest`) workflow for the most sensitive changes
   already; confirm nothing bypassed that workflow.

**Evidence:** payment gateway's own transaction/audit log (external),
`OperatingJournalRecorderService`/ledger entries for the affected
Firm(s) in the incident window.

---

## Known gaps this runbook surfaces (not silently assumed covered)

- No generic "pause all webhook processing" or "pause all uploads"
  kill switch exists yet — only the per-provider `ProviderKillSwitch`
  (outbound path) and per-feature code-level disabling.
- No IP/CIDR block kill switch exists at the application layer — this
  is an infrastructure-level action (WAF IP-set rule, security group)
  once `module.waf` (this mission) is actually enabled in an
  environment, not something the application itself can do today.
- Session revocation (`SessionRevocationService`, this mission) is
  currently wired into exactly one trigger point
  (`TogglePlatformAdminActiveStatusAction`'s deactivate path) — Firm-
  user and Client Portal-user revocation (scenario 1 above) must be
  invoked manually via `php artisan tinker` today, not yet its own UI
  action. Extending it to a proper Firm-user-facing "revoke sessions"
  action is a reasonable follow-up, not built in this checkpoint.
- No on-call/paging/escalation policy exists in this repository to
  reference — `OWNER_DECISION_REQUIRED`.
