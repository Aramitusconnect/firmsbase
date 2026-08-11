# Mission 2 — MyAttorney Marketplace Core: Domain Design

Written before implementation, per this mission's own instruction to audit
first. Synthesizes the fresh repository audit (4 parallel investigations)
into concrete build decisions. Every decision below traces back to either
an existing convention (reuse) or an explicit gap this mission fills (new).

## Namespace

New marketplace-specific models/services live under `App\Marketplace\*`
(`App\Marketplace\Models`, `App\Marketplace\Services`, `App\Marketplace\Enums`),
mirroring the existing `App\Integrations\*` convention exactly (same reason:
a large, cohesive subsystem with its own lifecycle, distinct from
`App\Models`'s tenant-application-data). Models outside `App\Models\` need
a `newFactory()` override, per `FirmIntegration`'s own established pattern.

Two exceptions, placed in `App\Models` alongside `PracticeArea` because
they are genuinely reusable platform reference data, not directory-specific:
- **`PracticeArea`** — reused directly, not duplicated (see below).
- **`Language`** — new, but shaped as general reference data, not
  marketplace-only, matching where `PracticeArea` already lives.

## Reuse decisions

| Concern | Decision | Why |
|---|---|---|
| Practice area taxonomy | **Reuse and extend `App\Models\PracticeArea`** (add `slug`, `is_marketplace_visible`, `sort_order`, `synonyms` json) | Already the exact "global platform catalog, not per-firm free text" shape section 12 requires. A second, divergent taxonomy would let a firm's internal specialization drift from what's searchable — one canonical list is correct. |
| Language | **New `App\Models\Language`** | `FirmSettings.default_language`/`Client.preferred_language` are raw `string(10)` today — no FK, nothing to reuse. New model, existing columns untouched. |
| Slugs | **New `HasMarketplaceSlug` concern** | No slug mechanism exists anywhere in this codebase. Built once, used by `DirectoryFirm` and `DirectoryAttorney`. |
| Nullable tenant-Firm linkage | **Modeled on `FirmIntegration.connected_by_firm_user_id`**: nullable FK, `nullOnDelete()`, app-level consistency check outside the tenant scope — not a DB-level composite FK | `Firm` has no RLS/tenant scope of its own (it IS the tenancy boundary), so `DirectoryFirm.firm_id` is a plain nullable FK to `firms.id`; no composite-FK gymnastics needed the way `FirmIntegration` needed them for `firm_users`. |
| CSV import (batch/mapping/validation/preview/audit/rollback layers) | **Reuse `ImportBatchService`, `ImportMappingService`, `ImportRowValidationService`, `ImportPreviewService`, `ImportAuditService`, `ImportRollbackService` as-is**; extend `ImportEntityType`/`ImportSourceType` enums with new cases; add `DirectoryFirm`/`DirectoryOffice`/`DirectoryAttorney` branches to `ImportDuplicateDetectionService` and `ImportApplyService` | These six services are generic by construction (entity type is just an enum switch input to two of them). Building a parallel import system would be pure duplication for zero benefit — the "hardcoded per entity type" layers need new `match` branches either way, in a new system or this one. |
| CSV-file-to-array ingestion (upload, size cap, encoding, formula-injection neutralization) | **New** — genuinely doesn't exist anywhere in this codebase (only CSV *export* writers exist). Built once as a small `MarketplaceCsvIngestionService`, feeding its parsed array into the reused `ImportBatchService::stageRows()`. | Confirmed absent by the audit; not a reuse opportunity. |
| Real malware scanning | **Reuse the `VirusScanner` interface/binding directly** (not `DocumentSecurityService`, which is tightly coupled to `Firm`/`Matter`/`Client`/the `documents` table). New, small `ClaimEvidenceSecurityService`/`ScanClaimEvidenceJob` mirroring `DocumentSecurityService`/`ScanDocumentJob`'s exact shape (private-storage-first, `Pending` status, queued scan job with explicit tenant/claim context, single `applyScanResult()`-style transition point, no public URL, `isUsable()` gate before any release) — **only if/when evidence upload actually ships**. See "Deferred" below. | Reusing the interface (the actual scanning contract Mission 1C built and proved) satisfies "use the real quarantine/malware scanner architecture, do not bypass scanning" without coupling claim evidence to the `documents` table's own hardened, already-tested RLS/authorization model, which was designed for Matter/Client documents specifically. |
| Audit events | **`PlatformAdminAuditEventRecorder::recordPlatformEvent()`** (firm-less path) for PlatformAdmin-actor marketplace events (claim approved/rejected, verification changed, duplicate merged, publish/unpublish, import performed). **`FirmUserAuditEventRecorder::record()`** (built in Mission 1C) for FirmUser-actor events tied to their own tenant Firm (claim initiated, profile self-edited) | Both already exist, both fit exactly — a claim is always initiated by a real FirmUser inside their own tenant Firm's context, so it genuinely belongs in that Firm's `security_events`, not a firm-less platform log. Neither `DomainEvent` (feeds the automation engine, mandates a `Firm`, wrong purpose) nor `TimelineEvent` (per-firm business timeline, wrong purpose) fits. |
| RLS / tenancy | **None on marketplace directory tables.** Every new table registered in `RowLevelSecurityCoverageMappingService::EXEMPT_TABLES` (with metadata, following the established newer-entry convention) — same treatment as `practice_areas`/`plans`/`matter_types`. Authorization for "claimed Firm manages its own listing" is a new explicit app-level policy service (`DirectoryProfileAccessPolicyService`), never inferred from a submitted Firm ID. | Confirmed via audit: this is the repo's own established pattern for platform-global, non-tenant tables, and structural tests (`RowLevelSecurityPreparationTest`) enforce every table without `firm_id` is accounted for in this registry — omitting it fails CI, not just a style nit. |
| Admin panel | **New Resources/Pages/Actions under the existing `app/Filament/Resources`/`app/Filament/Pages`/`app/Filament/Actions/Platform` trees, auto-discovered by the existing `AdminPanelProvider`** — no new panel. Authorization via new capability methods on `PlatformStaffAccessPolicyService` (`canManageDirectoryListings`, `canReviewClaims`, `canApproveVerification`, `canMergeDuplicates`, `canImportDirectoryData`), each backed by its own role allowlist, following `PracticeAreaResource`'s inline `canAccess()` style. High-risk mutating Actions use `StepUpAuthentication::mergeInto($this, [...], 'platform_admin')`, following `EnterSupportAccessSessionAction`'s exact shape. | Directly satisfies section 56/57's "do not create a new panel, reuse canonical step-up." |
| Claimed-Firm profile management | **New Page under the existing `app/Filament/Firm/Pages/`**, reusing the Firm panel's existing auth/session/MFA — not a second login. Authorized by `DirectoryProfileAccessPolicyService` checking an `APPROVED` claim linking `auth()->user()->activeFirmUser()->firm` to the target `DirectoryFirm`. | Directly satisfies section 60's explicit "app.firmsvault.com → MyAttorney Profile, do not send Firm users to a second unrelated login." |
| Public MyAttorney routes | **Plain Laravel routes/Blade+Livewire under the already-reserved `Route::domain($hosts->myAttorneyHost())` group in `routes/web.php`**, replacing today's "coming soon" catch-all — not a Filament panel (public, mostly unauthenticated, SEO-indexable pages don't fit Filament's admin-UX-oriented panel model, and the marketing-site precedent is exactly this shape: a plain domain-scoped route group). | Matches section 2's "reuse the canonical FirmsBase Laravel application... distinct public product surface" and the only existing precedent (the marketing route group) for a public, non-panel surface. |
| Session/cookie isolation | **`ConfigurePanelSessionCookie::class.':myattorney'`** applied to the MyAttorney route group (cookie `firmsvault-myattorney-session`, `__Host-` prefixed when secure, domain-less, path `/`) — needed for CSRF on claim/correction forms even though most pages are public/unauthenticated reads. | Guarantees byte-for-byte the same isolation guarantee Firm/Client/Admin already have, using the exact existing middleware — never a new cookie mechanism. |
| SEO indexability | **Deliberate code change** to `AddSearchIndexingHeader`: today it's a single-host allowlist (`marketingHost()` only); extend to an explicit indexable-hosts list including `myAttorneyHost()`. No existing flag to flip — audit confirmed none exists. | The middleware's own docblock already anticipated this ("never publish misleading MyAttorney structured data before the product exists") — this mission is that conscious change. |
| Rate limiting | **Inline `throttle:N,1` route middleware** per endpoint (matching the OAuth-initiate/Plaid-exchange precedent of "add throttle explicitly because this mutates state and had none before"), plus a **new `ClaimInitiationThrottleService`** (identity-keyed, modeled on `AccountLoginThrottleService`'s shape) for claim-specific abuse limits beyond plain IP throttling. | No central `RateLimiter::for` registry exists in this codebase to add to — inline route throttling is the established idiom. |
| CSP | **No change** unless a concrete need arises. V1 has no maps, no external JS/CDN dependency (section 5/80: Google/maps optional, off by default) — existing global policy already covers `'self'` scripts/styles, which is all MyAttorney's own Blade/Livewire pages need. | Section 78: narrow additions only, never broad/speculative ones. |

## New tables (all registered in `RowLevelSecurityCoverageMappingService::EXEMPT_TABLES`)

- `directory_firms` — the marketplace listing itself. Nullable `firm_id` FK to `firms`.
- `firm_offices` — offices for a `directory_firms` row (note: distinct name from the tenant `Firm` model's own `address_line1`/etc. columns, which stay untouched).
- `directory_attorneys` — public attorney identity, independent of `FirmUser`/`User`.
- `directory_attorney_firm` — Attorney↔Firm relationship with state (current/former/pending/disputed/unpublished) and metadata.
- `languages` — global reference data.
- `directory_firm_languages`, `directory_attorney_languages` — pivots.
- `directory_firm_practice_areas`, `directory_attorney_practice_areas` — pivots against the existing (extended) `practice_areas` table.
- `directory_claims` — claim lifecycle.
- `directory_verifications` — multi-dimensional verification records.
- `directory_profile_versions` — lightweight version/history for public-profile changes.
- `directory_correction_requests` — correction/removal workflow.
- `directory_duplicate_reviews` — duplicate-candidate review queue.
- `directory_marketplace_analytics_events` — privacy-conscious aggregate analytics.

`practice_areas` itself gets an additive migration (new nullable columns only,
no existing column touched) rather than a new table.

## Profile levels and capabilities (sections 15-19, 67)

Three explicit, orthogonal concepts, never conflated:
- **Profile level** (`DirectoryFirmProfileLevel` enum: `PublicListing`, `ClaimedProfile`, `VerifiedMember`) — derived from claim + membership state, not stored as an independent free-standing flag a caller could desync.
- **Capabilities** (`MarketplaceCapability` enum + a small `MarketplaceCapabilityService`) — `ProfileManagement`, `ClaimManagement`, `MemberBadge`, plus Mission-3-reserved-but-unimplemented capability names (`ConsultationRequests`, `SecureIntake`, `Scheduling`, `LeadDelivery`) so the vocabulary exists without the behavior.
- **Badges** (`MarketplaceBadge` enum) — `PublicListing`, `ClaimedProfile`, `FirmAuthorityVerified`, `AttorneyIdentityVerified`, `FirmsVaultMember`. No vague trust language, ever (section 19).

## Deferred within Mission 2 (documented, not silently dropped)

- **Claim evidence file upload** — the claim workflow ships with non-file
  verification paths (FirmOwner-authority fast-track for an authenticated
  tenant Firm user whose Firm name/domain matches, plus manual SuperAdmin
  review as the universal fallback) per section 21's own listed methods.
  File-upload evidence is architecturally prepared for (the scanner-reuse
  plan above) but not built in this pass, per sections 78-79's "avoid
  public file uploads unless genuinely necessary." Marked
  `INTENTIONALLY_DEFERRED` in the final report, not silently missing.
- **Individual attorney public profile pages** (`/attorneys/{slug}`) —
  the schema supports them from day one (`DirectoryAttorney` has its own
  slug), but whether V1 exposes standalone pages vs. attorneys only
  appearing nested inside Firm profiles is decided during the M2-4
  checkpoint itself, once real fixture data shows whether there's enough
  factual content per attorney to justify a real indexable page (section
  42's own instruction: "do not fabricate attorney biographies... from
  incomplete data").

## Test namespace

New tests live under `tests/Feature/Marketplace/` (mirroring
`tests/Feature/Integrations/`), organized by checkpoint sub-area
(`Directory/`, `Claims/`, `Verification/`, `Search/`, `Import/`, `Seo/`,
`Security/`) so the section 82-88 lettered test matrix maps cleanly onto
real file paths in the final report.
