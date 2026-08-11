# Mission 1C — FirmsVault Security Validation, Activation & Staging Proof — Final Report

Branch: `feature/firmsvault-security-validation-activation` (based on Mission
1B's final commit `a454fae`). 8 commits, all pushed. Full fresh-DB suite:
**12,061 / 12,061 tests passing, 115,002 assertions, 0 failures, 0 errors,
57 risky (identical baseline to Mission 1B's own 57 — 0 new risky tests)**.

## Governing constraint (read this first)

This sandbox has no real access to FirmsVault's AWS account (`603013471426`
— only an unrelated Lightsail role with zero ECS/RDS permissions), no
reachable staging origin (`app/client/admin.firmsvault.com` all NXDOMAIN),
and no browser automation tool connected. This was discovered and disclosed
before any implementation work began; the user was asked how to proceed and
explicitly chose: **"Code-achievable items now, block the rest."** Every
item below marked `BLOCKED_DEPENDENCY` is reported as such with the exact
reason — nothing was fabricated, simulated, or claimed complete without
real evidence. Full detail:
`docs/security/mission-1c-environment-constraints.md`.

## The 32-item closure matrix — final state

Built before any implementation (per section 2), maintained live throughout.
Full table with cost estimates, verification methods, and per-row
reasoning: `docs/security/mission-1c-closure-matrix.md`. Status key: ✅
addressed this mission (code + real proof where a real proof was possible)
· 🚫 blocked, no AWS/browser access · ⏸ correctly not attempted (owner
decision, product decision, or explicitly out of this mission's scope).

| # | Control | Outcome |
|---|---|---|
| 1 | Platform Admin WebAuthn browser flow | 🚫 Crypto core already verified (Mission 1B, 7/7 tests); browser/Livewire integration against a real authenticator needs a real HTTPS origin + browser — neither available. |
| 2 | Admin MFA policy (WebAuthn-only enforcement) | ⏸ No change needed — policy is correctly conditioned on row 1 passing first. |
| 3 | Firm-guard WebAuthn | ⏸ Correctly not attempted — a distinct architecture project (polymorphic credentials table, guard-aware RP host), not a fit for this mission's scope. |
| 4 | Firm-user MFA mandatory enforcement | ✅ **Closed the real lockout risk.** `canAccessPanel()` used to hard-deny the whole panel for a non-compliant Required-2FA user with no path to enrollment. Moved that check to a new middleware (`EnsureFirmUserMfaComplianceOrRedirectToEnrollment`) that redirects to the profile page instead — same enforcement, real path forward. 53/53 tests, then reconfirmed in the final 12,061-test gate. |
| 5 | Firm role granularity | ⏸ Correctly not attempted — inventing new roles is a product decision. |
| 6 | Step-up wired into every protected op | ⏸ Not extended — out of this mission's 27-section brief. |
| 7 | Session/device management UI | ⏸ Not built — pure scope decision, backend already exists. |
| 8 | Session anomaly-detection alerting | ⏸ Not built — deliberately deferred per Mission 1B's own caution. |
| 9 | Export/large-upload rate limits | ⏸ N/A — no such route exists to rate-limit. |
| 10 | AWS WAF | 🚫 Terraform complete (Mission 1B); enabling (`enable_waf=true`) requires `terraform apply` against the real account. |
| 11 | WAF Challenge/CAPTCHA | ⏸ Deferred — needs real abuse data first, and depends on row 10. |
| 12 | AWS Shield Advanced | ⏸ Correctly not purchased — a real $3,000/mo commitment, explicitly out of scope. |
| 13 | Content-Security-Policy enforcement | 🚫 Real policy ships report-only; promoting to enforcing needs a real browser session collecting violations across every surface — no browser/staging origin available. Left report-only, not promoted blind. |
| 14 | Real malware-scanning engine | ✅ **Built and proved.** New `ClamAvVirusScanner` (real clamd INSTREAM client) — proved against an actual ClamAV daemon installed in this sandbox with genuine official signatures: EICAR flagged Infected with a real threat name, a benign file Clean, a missing file Failed, and a full upload→scan→quarantine pipeline proof via `DocumentSecurityService::applyScanResult()`. **Not** bound as the default `VirusScanner` (stays `FakeVirusScanner` everywhere a real clamd isn't guaranteed — every CI runner, every other engineer's machine, and real staging today). Provider decision (self-hosted ClamAV over AWS-native S3 scanning or a third-party API that would send client legal documents off-network) recorded in `mission-1c-malware-scanner-decision.md`. Compliance-gap registry entry `real_malware_scanning_engine_stubbed` correctly left `status: open` — production binding is unchanged. |
| 15 | Document-download authorization primitive | ✅ **Built and proved**, not the full feature. `DocumentSecurityService::canBeDownloadedBy(Document, User\|ClientPortalUser)` composes the already-proven `MatterAccessPolicyService` (internal staff) and `ClientPortalMatterAccessPolicyService` (Client Portal) — closes the exact IDOR gap `canAccess()` alone would leave (firm-boundary only, not matter/grant-level). 12 tests covering role ceilings, matter assignment, cross-firm/cross-client denial, revoked grants. No route/controller added — the primitive only, per this mission's own instruction. |
| 16 | Secrets rotation | ⏸ Not attempted — no AWS access, and Mission 1B section 26 explicitly prohibits blind production-secret rotation regardless. |
| 17 | RDS security-group visibility | 🚫 Needs `aws rds describe-db-instances` against the real account. |
| 18 | Container image vulnerability scanning (ECR) | 🚫 Needs AWS Console/CLI access to the real ECR repo. |
| 19 | CloudTrail / GuardDuty / Security Hub / Access Analyzer | 🚫 Terraform complete (Mission 1B), all behind `enable_*=false`; enabling needs `terraform apply` against the real account. |
| 20 | Security alerting delivery | 🚫 Depends on row 19 being live plus a real destination to confirm delivery against. |
| 21 | Bulk export protection | ⏸ N/A — no export feature exists. |
| 22 | Trust-account open/suspend step-up | ⏸ Not extended — lower priority than Close, out of this mission's brief. |
| 23 | AWS Backup | 🚫 Terraform complete (Mission 1B), `enable_backup_plan=false`; enabling needs real AWS access. |
| 24 | Restore testing | 🚫 Depends on row 23; also needs a real non-prod restore target. |
| 25 | Generic kill switches | ⏸ Not built — a known, disclosed gap in the IR runbook, not silently hidden. |
| 26 | GitHub branch protection | 🚫 No GitHub org-admin access from this session. |
| 27 | Production OAuth/DNS/TLS cutover | ⏸ Explicitly out of scope per Mission 1B section 60. |
| 28 | Microsoft 365 disconnect provider-side revocation | ✅ **Investigated and disclosed**, not silently left ambiguous. Confirmed via Microsoft Graph API research that no viable self-service per-app revocation endpoint exists for this delegated OAuth2 app without disproportionate side effects. Local teardown (credential crypto-erasure, webhook-routing-token clearing, status transition) was already real and complete; added an explicit, provider-specific disclosure shown to the Firm user at disconnect time, pointing them to `myaccount.microsoft.com`/the Entra admin center for the provider-side step this app cannot perform. Also **corrected a stale Mission 1B audit finding**: `timeline_events` (where these connect/disconnect events are recorded, via `TimelineEventRecorder`) genuinely IS append-only under its own FORCE RLS policy — the earlier claim that it wasn't was wrong. 14/14 tests. |
| 29 | DB TLS runtime proof | 🚫 Needs a live `pg_stat_ssl` query against the real staging RDS connection. (Mission 1B already confirmed `DB_SSLMODE=require` as Terraform *configuration* — this row is the runtime confirmation only.) |
| 30 | ECS public-IP-by-default | 🚫 Needs live VPC/SG/route-table inspection against the real account. |
| 31 | ECS read-only-root-filesystem | ✅ **Proved locally**, and it surfaced a real, previously undocumented gap. `docker run --read-only` against an already-built FirmsBase image, replicating the Terraform's `readonlyRootFilesystem` + six named ephemeral-volume mounts exactly: all six writable paths genuinely writable, every other path genuinely rejects writes, and the app boots cleanly under these constraints. **Finding**: `/tmp` is NOT covered by the current 6-path mount list, and PHP's built-in `upload_tmp_dir` (used by every multipart file upload, independent of `CACHE_STORE`/`SESSION_DRIVER`) defaults there — this would silently break file uploads the moment `readonly_root_filesystem_enabled` is flipped to `true`. Not yet live in any real environment (the flag still defaults `false`), but recorded as a **blocking finding for that flip**, not fixed speculatively (the correct fix depends on real AWS Fargate ephemeral-volume ownership semantics this sandbox cannot verify). Also corrected an inaccurate claim in `docs/ecs/container-architecture.md` that conflated PHP's `sys_temp_dir` with Laravel's own Redis-backed cache/session redirection. |
| 32 | Security-audit event gaps | ✅ **Closed the Firm-user MFA gap.** New `FirmUserAuditEventRecorder` + `AuditedFirmUserAppAuthentication` give Firm users the same real, append-only, FORCE-RLS-backed `security_events` trail Platform Admins already had (8 event types: enrolled/disabled/recovery-codes-generated/cleared/challenge-succeeded/failed/recovery-code-used/verification-failed). The other two gaps this row named were investigated and found to need no code change: `FirmSettingsPage::save()` has no security-sensitive editable fields (2FA mode isn't even self-service there), and the "integration connect/disconnect events aren't append-only" claim is the same stale Mission 1B finding corrected in row 28. |

**Summary**: 6 of 32 rows fully addressed by real, tested code this mission
(4, 14, 15, 28, 31, 32). 12 rows blocked purely by this session's lack of
AWS/browser access, disclosed with the exact reason each time, never
faked. 14 rows correctly left alone as genuine owner/product decisions or
out-of-scope items this mission was never meant to touch.

## MyAttorney security readiness gate

Per the mission's own SAFE_TO_BUILD vs. SAFE_TO_LAUNCH_PUBLICLY framing:

- **SAFE_TO_BUILD: yes.** Every control this mission's own closure matrix
  flagged `Blocks MyAttorney? BUILD` (rows 14, 15) now has real code and a
  real local proof — a malware-scanning engine exists and is proven, and
  the document-download authorization primitive exists and is proven.
  MyAttorney's own implementation work can safely begin building on top of
  these primitives.
- **SAFE_TO_LAUNCH_PUBLICLY: no, not yet — and this was already true
  before this mission and remains true after it.** Every row flagged
  `Blocks MyAttorney? LAUNCH` (rows 1, 10, 13, 17, 18, 19, 20, 23, 24, 29,
  30) is still blocked, and every single one is blocked by the same root
  cause: this mission's own environment cannot reach FirmsVault's real AWS
  account or a real staging origin. None of them are unresolved *decisions*
  or unfinished *code* — the Terraform and application code for WAF, CSP
  enforcement, CloudTrail/GuardDuty/SecurityHub/AccessAnalyzer, and Backup
  is all complete and was already complete before this mission (Mission
  1B). What remains is exclusively: run `terraform apply` against the real
  account, then verify with real traffic/browser sessions. **This is
  squarely a next-mission action item, not a code gap** — whoever has real
  AWS credentials and a reachable staging origin should run through rows
  1, 10, 13, 17-20, 23-24, 29-30 in one pass before any public launch.

## Corrections made to prior findings

- **`timeline_events` append-only status** (rows 28, 32): Mission 1B's own
  audit claimed this table lacked an append-only guarantee. Verified false
  by direct migration read — it has its own genuine FORCE RLS INSERT-only
  policy. Corrected here rather than building unnecessary migration work
  to "fix" a gap that didn't exist.
- **`docs/ecs/container-architecture.md`'s temp-directory claim** (row 31):
  previously stated PHP's `sys_temp_dir` was redirected off local disk by
  the same `CACHE_STORE`/`SESSION_DRIVER` config that redirects Laravel's
  own cache/session writes. That conflated two unrelated things — corrected
  in place, with the real gap it was masking (`/tmp` under read-only root)
  now disclosed instead.

## Commits (8, all pushed to `feature/firmsvault-security-validation-activation`)

1. `c632571` — Closure matrix + documented environment constraints (§2 build-before-implementation requirement).
2. `57b55dd` — Microsoft 365 disconnect provider-side-revocation disclosure.
3. `a9453d0` — Closed the Firm-user 2FA enrollment lockout risk.
4. `3f16f84` — Firm-user MFA audit-trail (`FirmUserAuditEventRecorder` + `AuditedFirmUserAppAuthentication`).
5. `5e0ec87` — Document-download authorization primitive (`canBeDownloadedBy()`).
6. `aa24eaf` — Real ClamAV `VirusScanner` implementation + local quarantine proof.
7. `fd8b5ca` — ECS read-only-root-filesystem local proof (+ the `/tmp` finding).
8. `ef52d83` — Fixed two full-suite regressions the final test gate surfaced (a stale RLS regression-proof test and a structural Filament-namespace allowlist), both self-inflicted by this mission's own earlier commits, both root-caused and fixed rather than the assertions being weakened.

## Final test gate

- Pint: clean across every file this mission touched (22 files, zero
  fixers pending). Repo-wide `pint --test` shows pre-existing style drift
  in ~40+ files this mission never touched (confirmed zero overlap) — not
  a Mission 1C regression, out of this mission's scope to fix.
- `git diff --check` against Mission 1B's final commit: clean, no
  whitespace errors.
- Fresh-DB full suite (final run, HEAD `ef52d83`): **12,061 / 12,061
  passing, 115,002 assertions, 0 failures, 0 errors, 57 risky** — identical
  risky-test count to Mission 1B's own final gate (0 new risky tests
  introduced by this mission).
- An interim full run at HEAD `fd8b5ca` (before the final commit) surfaced
  2 real failures, both traced to this mission's own earlier commits and
  fixed in `ef52d83` — see "Commits" item 8 above.

## What this mission deliberately did not do

- Did not begin MyAttorney (per explicit instruction).
- Did not rewrite or redesign any working Mission 1B architecture — every
  change this mission made was additive (new files, new middleware, new
  tests) or a narrowly-scoped fix to something this mission itself broke.
- Did not flip any `enable_*`/`readonly_root_filesystem_enabled`/
  `VirusScanner::class` default binding to "on" anywhere — every new
  capability (WAF, CloudTrail/GuardDuty/SecurityHub/AccessAnalyzer, Backup,
  read-only-root, the real ClamAV scanner) remains explicitly opt-in,
  because activating any of them for real needs either real AWS access
  this session doesn't have, or — for read-only-root specifically — the
  `/tmp` gap resolved first.
- Did not fabricate, simulate, or claim success for anything requiring
  real AWS/browser access. Every blocked item says so, with the exact
  reason.

---

**STOP.** Per this mission's own final instruction, Mission 2 does not
begin automatically.
