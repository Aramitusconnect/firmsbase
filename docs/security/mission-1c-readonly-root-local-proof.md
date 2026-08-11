# Mission 1C — ECS Read-Only-Root-Filesystem Local Proof

Task M1C-V8. Mission 1B built the Terraform for `readonlyRootFilesystem`
(`infrastructure/ecs/modules/ecs_service/main.tf`,
`readonly_root_filesystem_enabled` in `environments/staging/variables.tf`,
default `false`) but never ran it — this sandbox has no real AWS
access (see `mission-1c-environment-constraints.md`), so it cannot be
proven against real Fargate. This document is the closest available
substitute: a real `docker run --read-only` proof against an
already-built FirmsBase application image, replicating the exact
mechanism (`readonlyRootFilesystem` + per-directory empty ephemeral
volume mounts) the Terraform describes.

## Method

- **Image**: `firmsbase:trixie-distroless-candidate` (already present
  in this sandbox's Docker cache — not built fresh; the shared disk
  had no headroom for a fresh multi-stage build of this Dockerfile at
  proof time, see `mission-1c-environment-constraints.md`). Confirmed
  via `docker inspect` to be a genuine FirmsBase application image
  (`org.opencontainers.image.source: https://github.com/Aramitusconnect/firmsbase`,
  revision `0632c23`) with the same architecture the current
  Dockerfile documents: distroless runtime, `WORKDIR /var/www/html`,
  non-root `1000:1000`, `docker/entrypoint.sh` as `ENTRYPOINT`. The
  revision is older than this mission's own HEAD — a real re-run
  against a freshly built image from the exact current commit is a
  `BLOCKED_DEPENDENCY` (no disk headroom to build), but the
  filesystem-permission mechanism under test (`readonlyRootFilesystem`
  + named ephemeral-volume mounts) is infrastructure-level and has not
  changed between that revision and HEAD.
- **Command**: `docker run --rm --read-only --user 1000:1000` with one
  `--tmpfs .../path:rw,mode=1777` per entry in
  `local.readonly_root_writable_paths`
  (`infrastructure/ecs/modules/ecs_service/main.tf`) — a `tmpfs` mount
  is the closest available local stand-in for Fargate's own empty,
  task-scoped ephemeral volume; both present as an empty, writable
  directory at container start, hiding whatever the image had at that
  path.

## Results

**1. Every documented writable path is genuinely writable** with its
own explicit mount, exactly as the Terraform intends:

```
OK: storage/framework/cache is writable
OK: storage/framework/sessions is writable
OK: storage/framework/testing is writable
OK: storage/framework/views is writable
OK: storage/logs is writable
OK: bootstrap/cache is writable
```

**2. `--read-only` is genuinely enforced, not a silent no-op** — every
other path was tested and correctly rejected a write:

```
OK: app correctly rejected write
OK: config correctly rejected write
OK: public correctly rejected write
OK: vendor correctly rejected write
OK: . correctly rejected write   (WORKDIR itself)
```

**3. The application boots successfully under these exact
constraints**: `php artisan --version` → `Laravel Framework 13.18.1`,
run with `--read-only` and only the six tmpfs mounts present. No
permission error anywhere in PHP's own bootstrap path (autoloader,
config loading, service-provider registration).

**4. A full web-role boot was attempted and failed on an unrelated,
pre-existing, correctly-firing guard** — `docker/entrypoint.sh`'s own
required-environment-variable check (`APP_ENV DB_CONNECTION DB_HOST
DB_DATABASE DB_USERNAME DB_PASSWORD`), which this sandbox cannot supply
(no real database — see environment-constraints doc). This is not a
read-only-root failure: the entrypoint reached and correctly enforced
its own startup guard, which itself required no filesystem writes
beyond what was already proven writable above.

## Finding: `/tmp` is not covered by the current 6-path mount list

**5. `/tmp` was tested and is NOT writable** under `--read-only` with
only the six documented tmpfs mounts present:

```
NOTE: /tmp is NOT writable under read-only root with only the 6 explicit mounts
```

This matters because PHP's own request-handling layer — independent
of anything Laravel's `CACHE_STORE`/`SESSION_DRIVER` config controls —
writes uploaded file bytes to a temp file under `upload_tmp_dir`
(defaults to the OS temp directory, `/tmp`) during every multipart
`POST` **before application code ever runs**. `docs/ecs/
container-architecture.md`'s existing "Temp directory handling"
section states: *"PHP's `sys_temp_dir`... [is] redirected off local
disk entirely once `CACHE_STORE`/`SESSION_DRIVER` point at Redis in
staging/production."* That claim is accurate for Laravel's own
cache/session storage, but does not cover PHP's built-in
`upload_tmp_dir` handling, which those two env vars do not touch. This
app has a live document-upload feature
(`DocumentSecurityService::upload()`); `readonly_root_filesystem_enabled`
defaults to `false` today (confirmed unchanged by this mission — see
the closure matrix), so this gap is **not yet live in any real
environment**, but it would silently break every file upload the
moment that flag is flipped to `true` without also addressing this.

**This mission does not fix it.** Two credible fixes exist — add
`/tmp` as a 7th named ephemeral-volume mount (mirroring the existing
six, verified against real Fargate's actual empty-volume ownership
semantics — untested here, since it depends on AWS behavior this
sandbox cannot reach), or set `upload_tmp_dir`/`sys_temp_dir` in
`php.ini` to redirect into one of the six already-covered,
already-`chown`'d paths (e.g. `storage/framework/cache`). Choosing and
implementing either is a deliberate follow-up for whoever actually
flips `readonly_root_filesystem_enabled` in a real environment, not a
speculative Terraform change made against infrastructure this session
cannot verify — matching this mission's own "validate and prove, do
not redesign working architecture" mandate. Recorded in the closure
matrix and the final report as a **BLOCKING finding for the flag
flip**, not a completed fix.

## What this proof does and does not establish

- **Establishes**: the `readonlyRootFilesystem` + six-named-ephemeral-
  volume mechanism itself works exactly as designed — writable paths
  stay writable, everything else genuinely locks down, and the
  application's own bootstrap path has zero dependency on any path
  outside those six.
- **Does not establish**: real AWS Fargate's exact ownership/
  permission behavior for a freshly mounted empty ephemeral volume
  (this proof used `tmpfs` with an explicit `mode=1777` as the closest
  local stand-in, not a literal reproduction of Fargate's own volume
  driver) — genuinely `BLOCKED_DEPENDENCY` on real AWS access, per
  `mission-1c-environment-constraints.md`. A real staging run before
  flipping `readonly_root_filesystem_enabled = true` remains necessary
  regardless of this local proof's result.
