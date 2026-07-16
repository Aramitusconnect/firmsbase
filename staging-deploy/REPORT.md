# FirmsBase Staging — First ECS Deployment Package

Source commit: `008866ffe00bfd9f22c986a7e407cbe8f271b1df`
Image: `603013471426.dkr.ecr.us-east-1.amazonaws.com/firmsbase-staging@sha256:8bfd74b0b56986f426d1695e2fef69e5f8b1f77be0c9712ea9015c4946de3a4f`

**Nothing in this package has been executed.** No AWS API call was made
from this sandbox. All artifacts below are reviewable files only.

> **Superseded (historical record only, do not follow):** the digest above
> and the `create-service-web.sh` / `create-service-worker.sh` /
> `create-service-critical-worker.sh` / `create-service-scheduler.sh`
> scripts referenced throughout this report have been deleted from the
> repository — they used `--enable-execute-command false` and family-only
> task-definition references with no manifest pinning or live gates. The
> only approved runtime service workflow is
> `staging-deploy/00-http-exposure-preflight.sh` through
> `staging-deploy/07-final-runtime-verification.sh`, using the current
> approved digest in the committed `firmsbase-staging-*.json` files.

---

## 1. Secret JSON-key schema found in repository evidence

**Not provable from repository evidence.** Searched (at commit `008866f`)
every Terraform module (`infrastructure/ecs/**`), `docs/ecs/**`,
`env.ecs.example`, and repo-wide `git grep` for `firmsbase_migrator`,
`database-app`, `database-migrator`. Findings:

- No bootstrap script, Terraform `aws_secretsmanager_secret` resource, or
  doc anywhere defines the internal structure of `database-app` or
  `database-migrator`.
- The three secrets the repo *does* reference by name
  (`app_key_secret_arn`, `db_password_secret_arn`,
  `redis_auth_token_secret_arn`) are used as **bare ARNs with no
  JSON-key-extraction (`:key::`) syntax anywhere** — i.e., every proven
  secret in this codebase is a raw string, not a JSON blob.
- The checked-in Terraform (`infrastructure/ecs/environments/staging/variables.tf`)
  has only **one generic** `db_password_secret_arn` variable — no separate
  migrator variable exists in code at all. The live account's split into
  `firmsbase_app` / `firmsbase_migrator` roles and separate `database-app`
  / `database-migrator` secrets is *live infrastructure that has run ahead
  of the checked-in IaC*.

**Decision taken (unconfirmed inference, flagged in item 14):** treat
`database-app` and `database-migrator` as raw-string password secrets,
consistent with every other proven secret in this codebase. All six task
definitions use plain `valueFrom: <arn>` with no JSON-key suffix. **This is
an inference, not a proven fact — verify the actual secret structure in
Secrets Manager (structure only, not the value) before registering.**

## 2. Required environment variables by role

From `docker/entrypoint.sh` (always required, all roles):
`APP_KEY`, `APP_ENV`, `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`.

Conditionally required (when `CACHE_STORE`/`SESSION_DRIVER`/
`QUEUE_CONNECTION` == `redis`, which is true for every role here):
`REDIS_HOST` (+ `REDIS_PASSWORD` since transit encryption/auth-token is
enabled on the live Redis).

Role-specific, from `docker/commands/*.sh`:
- **worker / critical-worker**: `WORKER_CONNECTION`, `WORKER_QUEUES`,
  `WORKER_TRIES`, `WORKER_TIMEOUT`, `WORKER_SLEEP`, `WORKER_MAX_JOBS`,
  `WORKER_MAX_TIME`, `WORKER_MEMORY`, `WORKER_BACKOFF`.
- **web**: none beyond the shared set (port 8080 is fixed by `EXPOSE
  8080`/entrypoint, not an env var).
- **scheduler / migrate / maintenance**: no role-specific env vars beyond
  the shared set.

All six task definitions additionally carry standard Laravel
non-secret config (`APP_NAME`, `APP_DEBUG`, `APP_MAINTENANCE_DRIVER`,
`APP_MAINTENANCE_STORE`, `DB_PORT`, `DB_SSLMODE`, `REDIS_CLIENT`,
`REDIS_PORT`, `REDIS_CACHE_DB`, `REDIS_QUEUE_DB`, `CACHE_STORE`,
`SESSION_DRIVER`, `SESSION_SECURE_COOKIE`, `QUEUE_CONNECTION`,
`REDIS_QUEUE_CONNECTION`, `REDIS_QUEUE_RETRY_AFTER`, `LOG_CHANNEL`,
`LOG_LEVEL`, `MAIL_MAILER`) — sourced from `config/*.php` defaults and
`docs/ecs/env.ecs.example`, not guessed.

No `FILESYSTEM_DISK`/`AWS_BUCKET`/S3 variables are set — the live state
shows no S3 bucket, so none are assumed.

## 3. Six task-definition families (files generated)

| File | Command | CPU/Mem | DB role | Port |
|---|---|---|---|---|
| `firmsbase-staging-web.json` | `["web"]` | 512/1024 | firmsbase_app | 8080 |
| `firmsbase-staging-worker.json` | `["worker"]` | 512/1024 | firmsbase_app | none |
| `firmsbase-staging-critical-worker.json` | `["worker"]` (WORKER_QUEUES=trust) | 512/1024 | firmsbase_app | none |
| `firmsbase-staging-scheduler.json` | `["scheduler"]` | 256/512 | firmsbase_app | none |
| `firmsbase-staging-migrate.json` | `["migrate"]` | 512/1024 | **firmsbase_migrator** | none |
| `firmsbase-staging-maintenance.json` | `["maintenance","list"]` | 512/1024 | firmsbase_app | none |

All six: `networkMode=awsvpc`, `requiresCompatibilities=["FARGATE"]`,
`runtimePlatform={X86_64,LINUX}`, execution/task role ARNs from the
verified live state, container name `app`, the one approved image digest,
log group `/ecs/firmsbase-staging/app` (live shared group) with a unique
`awslogs-stream-prefix` per role (`web`, `worker`, `critical-worker`,
`scheduler`, `migrate`, `maintenance`). No container-level `healthCheck` on
any (see item 14). No privileged mode, no Linux capabilities added, no SSH
port, image `USER` left at the Dockerfile's `1000:1000` (no override).

## 4. Four ECS services (files generated)

`create-service-web.sh`, `create-service-worker.sh`,
`create-service-critical-worker.sh`, `create-service-scheduler.sh`. All:
cluster `firmsbase-staging-cluster`, `FARGATE`, both subnets
(`subnet-07efcb5d4bcf5aa59`, `subnet-020540b8377bb4d0e`), security group
`sg-0db14e50ea5c5466c`, `assignPublicIp=ENABLED`, deployment circuit
breaker with rollback enabled, `desiredCount=1`,
`minimumHealthyPercent=0`/`maximumPercent=100` (see item 5 rationale), and
the five required tags (`Application=FirmsBase`, `Environment=staging`,
`ManagedBy=manual-reviewed-deployment`,
`SourceCommit=008866ffe00bfd9f22c986a7e407cbe8f271b1df`,
`ImageDigest=sha256:8bfd74b...4a4f`). Only `web` carries `--load-balancers`
targeting `firmsbase-staging-tg` on container `app`, port 8080. Worker,
critical-worker, and scheduler never attach to the ALB. `migrate` and
`maintenance` are never turned into services (no `create-service-*` file
exists for either).

**Tag-length check**: AWS tag values are capped at 256 characters. The
longest value here, `ImageDigest=sha256:<64 hex chars>`, is 71 characters
total — well within the limit. No shortening needed; full digest
provenance is preserved in the tag as requested.

## 5. CPU and memory choices

Matches both the user's expected shape and the checked-in Terraform
(`infrastructure/ecs/environments/staging/main.tf`) exactly:

- **web (512/1024)**: serves HTTP synchronously behind the ALB; sized for
  request-response workloads, matches existing web module config.
- **worker / critical-worker (512/1024 each)**: queue workers process
  arbitrary job payloads (PDF/document generation per `WORKER_QUEUES`
  including `documents`), given the same headroom as web rather than the
  scheduler's minimal footprint.
- **scheduler (256/512)**: runs a single lightweight `schedule:work` loop
  with no HTTP serving and no job payload processing — smallest footprint
  of the six, matches existing Terraform.
- **migrate (512/1024)**: schema migrations can be memory-intensive
  (index builds, backfills) and are short-lived/one-off, so matching
  web/worker sizing is safe headroom rather than a cost concern.
- **maintenance (512/1024)**: ad hoc operator commands of unknown size;
  matches migrate's sizing for the same one-off-task reasoning.

`minimumHealthyPercent=0`/`maximumPercent=100` on all four services: this
is the **first-ever creation** of each service at `desiredCount=1` — there
is no existing task to keep healthy during a "deployment", so 0/100 avoids
any transient double-task requirement. This mirrors the existing
Terraform's already-declared pattern for the single-instance scheduler
service. It is not the steady-state autoscaling configuration (e.g. web's
eventual `desired_count=2`) — that is a later, separate scale-up decision
outside this initial bootstrap.

## 6. Network configuration

All tasks/services: `awsvpc` mode, both subnets
(`subnet-07efcb5d4bcf5aa59` us-east-1a, `subnet-020540b8377bb4d0e`
us-east-1b), security group `sg-0db14e50ea5c5466c` (ingress 8080 from ALB
SG only), `assignPublicIp=ENABLED`. This is required because both subnets
have `MapPublicIpOnLaunch=true`, route via an Internet Gateway, and no NAT
Gateway or provable VPC endpoints exist — Fargate tasks in this VPC need a
public IP to reach ECR/Secrets Manager/CloudWatch Logs at all.
`DISABLED` was never used anywhere in this package (verified by grep).

## 7. Migration command sequence

`migration-sequence.sh` — register only the migrate task def → `run-task`
(both subnets, ECS SG, `assignPublicIp=ENABLED`, count 1) → capture
`taskArn` → `wait tasks-stopped` → `describe-tasks` (stop code, stopped
reason, container exit code, container reason, log stream) → `logs tail`
scoped to only that task's log stream → explicit gate: proceed only on
`exitCode==0`, normal `stopCode`, clean log content, and no RLS/permission/
ownership error text; otherwise stop, do not register/create any runtime
service, do not auto-retry, report diagnostics. Uses
`firmsbase_migrator`/`database-migrator` exclusively — never
`firmsbase_app`.

## 8. Runtime service creation order

`runtime-verification-commands.sh`, one stage at a time, never all four
simultaneously: **web** (register → create → wait-stable → target-health →
synthetic `/up`+`/readyz` 200 checks) → **worker** (register → create →
wait-stable → log inspection) → **critical-worker** (register → create →
wait-stable → log inspection confirming trust-queue-only consumption) →
**scheduler** (register → create → wait-stable → 2-minute
no-restart confirmation via repeated `describe-tasks` `startedAt`
comparison) → **maintenance** (register only — never run, never a
service).

## 9. Health/readiness verification commands

Contained in `runtime-verification-commands.sh`: `aws ecs wait
services-stable`, `aws elbv2 describe-target-health`, and plain `curl`
against the ALB's HTTP-80 DNS name for `/up` and `/readyz`, expecting 200.
These are synthetic-only checks (no client data, no authenticated
traffic) — consistent with the HTTP-only gate in item 11.

## 10. CloudWatch diagnostic commands

`aws logs tail /ecs/firmsbase-staging/app --log-stream-names <role>/app/<task-id> --since <window>`
used throughout `migration-sequence.sh` and
`runtime-verification-commands.sh`, always scoped to a specific stream
(never a blanket group-wide tail), using each task def's unique
`awslogs-stream-prefix`. `rollback-containment.md` adds the equivalent
diagnostic sequence (`describe-services`, `list-tasks
--desired-status STOPPED`, `describe-tasks`, `logs tail`) for failure
investigation.

## 11. HTTPS blocker and remediation plan

Blocker: ALB has only an HTTP:80 listener; no ACM cert; **no staging
domain name was supplied and none is invented here.** Full plan
(unexecuted) in `https-remediation-plan.md`: obtain domain → request ACM
cert in us-east-1 → DNS-validate → create HTTPS:443 listener with a
current TLS policy → change HTTP:80 listener to redirect to HTTPS → point
domain at ALB → verify HTTPS `/up`+`/readyz` 200 and HTTP→HTTPS 301. Until
complete, the no-client-data/no-real-traffic gate stands; only synthetic
HTTP checks are permitted.

## 12. Rollback/containment procedure

`rollback-containment.md`: on first-deployment failure, set
`--desired-count 0` first (reversible, preserves history) → capture full
diagnostics → delete the service only after diagnostics are preserved →
never deregister task definitions until failure is root-caused →
database rollback is never automatic and requires a human-authored,
migration-specific plan; no script here ever invokes
`migrate:rollback` or runs migration commands as `firmsbase_app`.

## 13. Exact files generated

All under `staging-deploy/` (scratchpad, not committed to the repo):
- `firmsbase-staging-web.json`
- `firmsbase-staging-worker.json`
- `firmsbase-staging-critical-worker.json`
- `firmsbase-staging-scheduler.json`
- `firmsbase-staging-migrate.json`
- `firmsbase-staging-maintenance.json`
- `create-service-web.sh`
- `create-service-worker.sh`
- `create-service-critical-worker.sh`
- `create-service-scheduler.sh`
- `migration-sequence.sh`
- `runtime-verification-commands.sh`
- `https-remediation-plan.md`
- `rollback-containment.md`
- `REPORT.md` (this file)

All six JSON files pass `jq empty`. All six shell scripts pass `bash -n`.
Grep-based negative checks confirm: no secret values, no plaintext
passwords, no RDS master-secret reference, no wrong-account ARNs (only
`603013471426` appears), a single consistent image digest across every
file (the approved one — never the previous digest, which was never even
recorded in this session), no `database-app` secret inside
`firmsbase-staging-migrate.json`, no `database-migrator` secret outside of
it, no `https://` URL outside `https-remediation-plan.md`, and
`assignPublicIp=ENABLED` (never `DISABLED`) everywhere network
configuration is specified.

## 14. Unresolved questions preventing safe registration as-is

1. **Secret JSON-key schema for `database-app`/`database-migrator` is
   unproven** (item 1) — current files assume raw-string secrets by
   analogy, not by evidence. **Action before registering:** confirm the
   secret's structure (key names only, not values) in Secrets Manager.
   If they are JSON, every `valueFrom` in the affected task defs needs the
   `:key::` suffix added.
2. **Redis TLS wiring is unproven.** The live Redis has transit encryption
   enabled (TLS required to connect at all), but neither
   `config/database.php` nor any doc in this repo (`env.ecs.example`,
   `queue-and-redis-architecture.md`) shows any TLS/scheme handling for
   phpredis. The task definitions here use a bare `REDIS_HOST` value with
   no `tls://` prefix. **This is a genuine, unaddressed gap — if
   uncorrected, every role will fail to connect to Redis at all**, since
   transit encryption is mandatory on the live cluster, not optional. This
   is the single highest-risk unresolved item in this package. Recommend
   confirming with the application owner whether phpredis is configured
   for TLS anywhere (possibly at a layer not visible in this repo, e.g. a
   custom service provider) before registering any task definition.
3. **Web container-level `healthCheck` omitted.** The checked-in
   Terraform's `container_health_check_command` (`curl -f
   http://localhost:8080/up`) cannot work — the final image stage is
   distroless and has no `curl` binary. Omitted here; relying solely on
   the ALB target group's own HTTP health check, which is unaffected.
   Flagging this as a bug in the checked-in Terraform that should
   eventually be fixed there (e.g. switch to an ECS-native
   `CMD-SHELL`-free health check or drop the container health check
   entirely in favor of the target-group check, which is already what
   this package does).
4. **Log-group topology diverges from checked-in Terraform.** The
   checked-in Terraform declares per-role log groups
   (`/ecs/${name_prefix}/${role}`); live state has one shared group
   (`/ecs/firmsbase-staging/app`). This package follows the live state as
   instructed; the Terraform should eventually be updated to match, or the
   live group should be reconciled to per-role groups — a decision for the
   repo owner, not made here.
5. **DB-role Terraform divergence.** Checked-in Terraform has only one
   generic `db_password_secret_arn` and hardcodes `DB_USERNAME=firmsbase_app`
   for every role including migrate. The live account already has a
   distinct `firmsbase_migrator` role/secret. This package deliberately
   diverges from the checked-in Terraform for the migrate task definition
   only, per the live-state instructions — the IaC should be updated to
   reflect this split so future `terraform apply` runs don't regress it.

None of the above blocks producing this reviewable package, but **item 2
(Redis TLS) should be resolved before any task definition in this package
is registered**, since an unresolved TLS mismatch would cause every
non-web role (and web's cache/session backend) to fail immediately at
runtime.
