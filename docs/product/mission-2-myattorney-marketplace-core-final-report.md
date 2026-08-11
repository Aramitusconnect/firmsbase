# Mission 2 — MyAttorney Marketplace Core — Final Report

Branch: `feature/myattorney-marketplace-core`. 15 checkpoints, 15 logical
commits, all pushed. Final fresh-DB full suite (run twice — the second run
confirming the first run's two fixes): **12,314 / 12,314 tests passing,
117,720 assertions, 0 failures, 0 errors, 57 risky** (identical to the
baseline established across every prior mission on this codebase — 0 new
risky tests introduced by Mission 2).

## Governing constraint (read this first)

Mission 1C classified MyAttorney: **SAFE_TO_BUILD_MYATTORNEY = YES,
SAFE_TO_LAUNCH_MYATTORNEY_PUBLICLY = NO.** Every checkpoint below was built
against that boundary, not around it — most visibly in checkpoint 12, where
the design doc's own literal instruction ("unconditionally add
`myAttorneyHost()` to the indexable-hosts list") was deliberately not
followed as written; see that checkpoint's own entry below for why. **This
report does not close that boundary.** MyAttorney search-engine indexing
remains config-gated off by default in every environment, including
production (`MYATTORNEY_PUBLIC_INDEXING_ENABLED`, default `false`), and
nothing in this mission points real DNS at MyAttorney publicly, advertises
a launch, or declares launch-readiness. Flipping that flag, and any
decision to publicly announce MyAttorney, is an explicit, separate,
owner-approved step this mission does not take.

## The 15 checkpoints — final state

| # | Checkpoint | Commit | Outcome |
|---|---|---|---|
| 1 | Marketplace domain/schema foundation | `85a902f` | ✅ `directory_firms`, `firm_offices`, `directory_attorneys`, `directory_attorney_firm` — the core schema, all registered Global/RLS-exempt from day one. |
| 2 | Practice area taxonomy + languages | `41a0883` | ✅ Extended the existing `practice_areas` table (slug/synonyms/is_marketplace_visible) rather than a second taxonomy; new `languages` reference table. Seeded a real 16-entry practice-area catalog via migration — the seed that checkpoint 15 later found colliding with an unrelated pre-existing test's hardcoded fixture (see below). |
| 3 | Profile levels + capability model | `8e84cae` | ✅ `DirectoryFirmProfileLevel` always *derived* from `is_claimed`/`is_marketplace_member` (section 15) — never its own stored, driftable column. `MarketplaceCapabilityService`/`MarketplaceBadgeService` established as the only place a badge/capability is computed. |
| 4 | Public Firm/Attorney profile routes + views | `341ae9a` | ✅ Real `/firms/{slug}`/`/attorneys/{slug}` pages replacing the Mission 1 "coming soon" placeholder. `PublicFirmProfile`/`PublicAttorneyProfile` DTOs established as the *only* shape a public view may read — a rule every later checkpoint held to. |
| 5 | Search + deterministic ranking | `bfc6df7` | ✅ `MarketplaceSearchService`/`MarketplaceRankingService` — no opaque AI scoring, no pay-to-rank (subscription/membership never a scoring input), ties break on `id` ascending for reproducible ordering. Fixed a real bug: `consultation_modes` was Postgres `json` (no equality operator), breaking `SELECT DISTINCT` — converted to `jsonb`. |
| 6 | Claim workflow | `dee9905` | ✅ Full `directory_claims` lifecycle (Pending→Approved/Rejected/Revoked/Disputed/Expired), same-firm duplicate rejected outright, cross-firm conflict lands both claims in Disputed pending SuperAdmin resolution — never an automatic winner. Fixed a closure-variable capture bug caught by the real test run, not assumed from reading the code. |
| 7 | Verification model | `7cca698` | ✅ `directory_verifications` — a deliberate SuperAdmin action taken after reviewing real evidence, never inferred from claim approval alone (claiming and verification are architecturally distinct badges, section 19). |
| 8 | Correction/removal workflow + profile versioning | `0048cd1` | ✅ Genuinely public, unauthenticated correction-report form; `directory_profile_versions` as an append-only history of public-profile content changes. Found and fixed a real middleware-priority bug: `ConfigurePanelSessionCookie` was losing to Laravel's `$middlewarePriority` ordering on plain `web`-group routes — fixed globally in `bootstrap/app.php`, re-verified against the entire 569-file Security suite since the fix was global. |
| 9 | CSV import + provenance + duplicate detection | `f534ff5` | ✅ Deliberately **did not** reuse the existing Firm-tenant-scoped `import_batches`/`ImportApplyService` pipeline as the design doc's own plan suggested — that pipeline's own docblock guarantees every row it creates belongs to the importing batch's own Firm, which is architecturally incompatible with importing platform-Global `directory_firms` rows with no real tenant owner. Built a disclosed, deliberately parallel pipeline (`directory_import_batches`/`directory_import_rows`) instead, documented in the new migration's own docblock — this mission's clearest example of catching and correctly resolving a design-doc/reality mismatch through disclosure rather than forced reuse. Source-precedence rule implemented: a claimed listing, or one verified/firm-confirmed more recently than the import batch itself, can never be silently overwritten by an older CSV row. |
| 10 | Claimed-Firm profile self-service management | `1957ada` | ✅ New Firm-panel page reusing the Firm panel's own existing auth/session/MFA — never a second login. Every platform-managed column (`is_claimed`, `firm_id`, `publication_state`, …) has no code path a forged submission could reach; a profile edit records a `directory_profile_versions` row and a real `FirmUserAuditEventRecorder` event. |
| 11 | SuperAdmin marketplace controls | `2e65695` | ✅ 4 new Admin Resources + 15 new Actions. Step-up gating applied to exactly the mission's own named high-risk list (approve/revoke claim, verify/revoke authority, remove listing, change member state) via the canonical `StepUpAuthentication` service — never an ad-hoc password check. Caught and self-corrected a real bug before any test was even written: `StepUpAuthentication::protect()` *replaces* an action's existing `->schema()` rather than appending to it — `::mergeInto()` used instead everywhere a step-up action also needs a domain field (e.g. a required reason). A genuine `formatStateUsing` closure-parameter-name bug (`$type` instead of `$state`) caused a real 500 on `DirectoryCorrectionRequestResource`, caught by the actual page render, not assumed from reading the code. |
| 12 | SEO / sitemap / structured data | `ec332b3` | ✅ Chunked sitemap index (never a single flat file — the sitemap protocol's own 50k-URL cap, and this is an unboundedly-growing public directory), host-aware `robots.txt`, schema.org JSON-LD + canonical + Open Graph tags. **Design deviation, disclosed:** the design doc's own literal instruction was to unconditionally make `myAttorneyHost()` indexable — doing that verbatim would have made MyAttorney indexable in every environment including production, directly conflicting with Mission 1C's own SAFE_TO_LAUNCH_MYATTORNEY_PUBLICLY = NO boundary (a higher-priority governing constraint than a design doc's literal text). Added `CanonicalUrlService::myAttorneyIndexingEnabled()`, backed by a new config flag defaulting `false` everywhere including production — building the SEO surface and actually letting search engines index it are two different decisions; only the former is this checkpoint's job. |
| 13 | Privacy-conscious analytics foundation | `794ca05` | ✅ `directory_marketplace_analytics_events` — privacy-conscious *by omission*: no IP address, no session/cookie id, no user agent, no referrer, no actor of any kind (confirmed via audit that no IP-anonymization utility exists anywhere in this codebase; the correct choice was to never collect what isn't needed, not a half-measure like hashing). `recordSearchPerformed()` only ever persists coarse, already-public taxonomy facets — never the free-text query a visitor typed. |
| 14 | Security/accessibility/performance hardening + full test matrix | `8ea538d` | ✅ An audit of checkpoints 4–13 found accessibility already solid and no SQL-injection surface, but four real, confirmed gaps, all fixed: every public MyAttorney route was completely unthrottled (added IP-keyed `throttle:`); `MarketplaceSearchService::candidates()` had no upper bound (added a deterministic `MAX_CANDIDATES` cap); a genuine JSON-LD script-injection vector (`JSON_UNESCAPED_SLASHES` had disabled the escaping that neutralizes an embedded `</script>` — switched to `JSON_HEX_TAG` et al.); a real N+1 on the search-ranking path (batched the verification-badge lookup). New `tests/Feature/Marketplace/Security/` — the design doc's own previously-empty, planned test namespace. |
| 15 | Existing-system regression gate + final report + STOP | `dc198f8` + this report | ✅ First whole-repo fresh-DB run (12,314 tests) surfaced two real regressions no narrower per-checkpoint sweep had exercised together — see below. Both fixed, confirmed clean on a second full run. |

## The two regressions checkpoint 15 found and fixed

Neither was found by any of checkpoints 5–14's own scoped regression sweeps
(each deliberately broad but never whole-repo) — exactly the class of gap
the mission's own "final gate" checkpoint exists to catch:

1. **`FirmIntegrationSuperAdminBoundaryStructuralTest`** (an unrelated,
   pre-existing Mission 1C structural firewall protecting the admin-panel/
   Firm-panel boundary) — its own cascade allowlist was updated for
   checkpoint 11's new Admin surface, but checkpoint 13 added a second,
   later Admin page (`PlatformMarketplaceAnalyticsPage.php`) that was never
   added to that same allowlist. Fixed by extending the allowlist with the
   same pattern every prior addition already uses.
2. **`PracticeAreaTest::test_code_is_unique`** (a pre-existing, unrelated
   test) hardcoded `'immigration'` as its own duplicate-key fixture value.
   Checkpoint 2's own marketplace practice-area catalog migration
   permanently seeds a real `'immigration'` row into every migrated
   database — a genuine regression this mission's own seed data caused,
   not flakiness. Fixed by changing the fixture to a code that can never
   collide with the real catalog.

## RLS / governance registry — final counts

Every new marketplace table (16 across the mission) registered in
`RowLevelSecurityCoverageMappingService::EXEMPT_TABLES` with real metadata
— none carry a `firm_id` column, all platform-Global. Counts moved
`EXEMPT_TABLES` 40 → 56, the `Global` classification bucket 64 → 80, and
the full table inventory 285 → 301 — each addition verified programmatically
against the live registry at the checkpoint that touched it, never copied
forward from a stale count.

## What Mission 2 deliberately did not do

- **Did not enable MyAttorney search-engine indexing anywhere** — stays
  config-gated off by default in every environment (checkpoint 12).
- **Did not point production DNS at MyAttorney publicly, advertise a
  launch, or declare launch-readiness** — none of that work exists in this
  branch.
- **Did not expose an unvalidated public upload flow** — claim-evidence
  upload was scoped out of this mission's own checkpoint list from the
  start; nothing here introduces a new upload surface.
- **Did not build browse-by-practice-area/city index pages** — the
  sitemap (checkpoint 12) enumerates only individual firm/attorney profile
  URLs plus the bare home/search page, a disclosed scope limit noted in
  that checkpoint's own commit.
- **Did not wire the new analytics retention sweep into the existing,
  unrelated `RetentionPolicyService`** — its `RetentionRecordType` enum is
  a closed taxonomy scoped to a different mission's own Phase 17 record
  types; a small, self-contained scheduled command was the correct,
  disclosed choice instead (checkpoint 13).

## STOP

Per the mission's own closing instruction: **this mission stops here.**
Mission 3 is not begun. MyAttorney is not publicly launched — indexing
stays off by default, no DNS/announcement work exists in this branch, and
the SAFE_TO_LAUNCH_MYATTORNEY_PUBLICLY = NO boundary from Mission 1C
remains exactly where this mission found it. Further work (Mission 3, or a
decision to flip the indexing flag and launch) requires explicit owner
review and approval.
