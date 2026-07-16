# Secret Mapping + Redis TLS Report — FINAL

Supersedes the prior version of this file (which left `REDIS_PASSWORD` and
`firmsbase-staging-migrate.json`'s DB_* secrets pending). All secret
formats are now confirmed and applied. Nothing in this package has been
executed. No AWS access, no secret retrieval, no task-definition
registration, no migration, no service creation, no IAM change.

---

## Confirmed secret schemas (final)

| Secret | Format | Keys used |
|---|---|---|
| `app-key` | json-object | `APP_KEY` |
| `database-app` | json-object | `dbname, engine, host, password, port, username` (`engine` not injected — no repo evidence it's required) |
| `redis-auth-token` | json-object | `REDIS_PASSWORD` |
| `database-migrator` | json-object | `dbname, engine, host, password, port, username` (`engine` not injected, same reasoning) |

## Requirement-by-requirement confirmation

1. **`REDIS_PASSWORD` JSON-key selector applied consistently to all six
   definitions** — confirmed via `jq`, identical value in web, worker,
   critical-worker, scheduler, migrate, maintenance:
   `...redis-auth-token-p6rVKN:REDIS_PASSWORD::`
2. **Migrate contains no `database-app` reference** — confirmed: `grep -c
   database-app firmsbase-staging-migrate.json` → `0`.
3. **Web, worker, critical-worker, scheduler, maintenance contain no
   `database-migrator` reference** — confirmed: `0` occurrences in each of
   the five files.
4. **`APP_KEY` uses `:APP_KEY::` in all six** — confirmed via `jq`,
   identical across all six: `...app-key-QigVGy:APP_KEY::`.
5. **Runtime `database-app` JSON-key mappings preserved for all
   non-migrate roles** — confirmed unchanged in web/worker/critical-worker/
   scheduler/maintenance: `DB_HOST:host::`, `DB_PORT:port::`,
   `DB_DATABASE:dbname::`, `DB_USERNAME:username::`, `DB_PASSWORD:password::`,
   all against the `database-app` secret.
6. **`jq empty` on all six JSON files** — all six pass (`web`, `worker`,
   `critical-worker`, `scheduler`, `migrate`, `maintenance`).
7. **`bash -n` on every shell script** — all seven pass (4
   `create-service-*.sh`, `migration-sequence.sh`,
   `runtime-verification-commands.sh`, `connectivity-probes.sh`).
8. **Negative-evidence searches**:
   - Plaintext credentials: none found.
   - Old Redis host value with no `tls://` scheme (`"value": "master.firmsbase-staging-redis..."`): none found — all six now carry the `tls://` prefix.
   - Old `redis-auth-token` ARN with no JSON-key selector: none found — all six now carry `:REDIS_PASSWORD::`.
   - Previous image digest: none found — single consistent approved digest (`sha256:8bfd74b0...4a4f`) across all six files.
   - RDS master secret: none found.
   - Wrong-account ARNs: none found — every ARN uses account `603013471426`.
   - `database-migrator` in runtime (non-migrate) definitions: none found.
   - `database-app` in migrate: none found.

## Final redacted environment/secret mapping table by role

| Field | web | worker | critical-worker | scheduler | migrate | maintenance | Mapping |
|---|---|---|---|---|---|---|---|
| `APP_KEY` | secret | secret | secret | secret | secret | secret | `app-key:APP_KEY::` |
| `DB_CONNECTION` | plain `pgsql` | plain | plain | plain | plain | plain | env |
| `DB_HOST` | secret | secret | secret | secret | secret | secret | `database-app:host::` (5 roles) / `database-migrator:host::` (migrate) |
| `DB_PORT` | secret | secret | secret | secret | secret | secret | `database-app:port::` / `database-migrator:port::` |
| `DB_DATABASE` | secret | secret | secret | secret | secret | secret | `database-app:dbname::` / `database-migrator:dbname::` |
| `DB_USERNAME` | secret | secret | secret | secret | secret | secret | `database-app:username::` / `database-migrator:username::` |
| `DB_PASSWORD` | secret | secret | secret | secret | secret | secret | `database-app:password::` / `database-migrator:password::` |
| `REDIS_HOST` | plain `tls://master...` | same | same | same | same | same | env, TLS scheme prefix preserved |
| `REDIS_PORT` | plain `6379` | same | same | same | same | same | env, preserved |
| `REDIS_PASSWORD` | secret | secret | secret | secret | secret | secret | `redis-auth-token:REDIS_PASSWORD::` (all six) |
| Port mapping | 8080 | — | — | — | — | — | only web attaches to ALB |

No whole-object injection anywhere; no `engine` key injected anywhere;
`DB_USERNAME` for migrate now comes from `database-migrator:username::`
(previously a plain-env `firmsbase_migrator` value) — same effective
value, now sourced from the confirmed secret rather than hardcoded,
consistent with the migrator secret's proven schema.

## Redis TLS status (unchanged from prior report)

Still resolved via environment configuration only — `REDIS_HOST=tls://...`
recognized directly by phpredis's `connect()`, certificate/hostname
validation on by default (no context override anywhere in the repo), auth
sent only after the TLS handshake completes. No rebuild required. Live
confirmation still depends on running Probe 3 in `connectivity-probes.sh`
(no AWS access available in this workflow to run it here).

## Verdict

**READY FOR TASK-DEFINITION REGISTRATION**

All four secret schemas are now confirmed and correctly applied across
all six task definitions. All structural, negative, and cross-reference
validations pass. Redis TLS is achievable with the approved image via
environment configuration alone. Remaining risk is confined to what only
a live AWS check can close — recommend running `connectivity-probes.sh`
(especially Probe 3, the Redis TLS PING, and Probes 1–2, the DB
connectivity checks) before proceeding to `migration-sequence.sh`, exactly
as the package's own ordering already requires. Nothing in this package
has been executed, registered, or deployed.
