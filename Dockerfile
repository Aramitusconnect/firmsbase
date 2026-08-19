# syntax=docker/dockerfile:1
#
# FirmsBase production image. ONE image, used for every ECS role
# (web / queue worker / trust-queue worker / scheduler / migration /
# maintenance) — the role is selected at `docker run`/ECS task `command`
# time by docker/entrypoint.sh, never by building a different image. See
# docs/ecs/container-architecture.md for the full rationale.
#
# Second container-vulnerability remediation pass (see
# docs/security/ecs-image-vulnerability-exceptions.md): moves the base image
# from Debian Bookworm to Debian Trixie (13) — Trixie ships a fixed libssh2
# (1.11.1-1+deb13u1, resolving CVE-2026-55200/CVE-2025-15661/CVE-2026-55199)
# — and moves the final runtime stage to a distroless base, so Perl, apt,
# dpkg, and the rest of a full Debian userland are structurally absent from
# what ships, not merely unreachable. GD is now compiled manually without
# AVIF support (`--with-avif` is never passed, and libavif/libaom/libdav1d
# dev headers are never installed in the build stage that compiles it),
# removing the aom/libavif CVE family entirely rather than carrying it as a
# documented exception.
#
# A distroless final stage has no shell, no `env`, no package manager, and
# no way to `useradd`/`chown` at build time within that stage itself — every
# file that ships (the frankenphp binary, every compiled PHP extension,
# every transitively-required shared library, a relocated `bash` binary for
# docker/entrypoint.sh, `/etc/passwd`+`/etc/group` entries for uid/gid 1000,
# CA certificates, timezone data) is assembled and correctly owned in the
# `runtime_libs` stage below — which does have Debian tooling, since it is
# never itself shipped — then copied into the distroless stage as a single
# pre-built tree. This is the standard adaptation of FrankenPHP's own
# documented distroless pattern (https://frankenphp.dev/docs/docker/) for a
# dynamically-linked PHP build with several C-library-backed extensions,
# rather than the fully-static/scratch variant that pattern also describes
# (not used here — several extensions this app needs are not proven to
# build statically without further, riskier changes).

ARG PHP_VERSION=8.3
ARG FRANKENPHP_BASE_TAG=1-php8.3-trixie
# Pulled and inspected live against Docker Hub during this remediation pass
# (`docker pull dunglas/frankenphp:1-php8.3-trixie` +
# `docker inspect --format '{{index .RepoDigests 0}}'`) — the current
# published linux/amd64 image for this exact tag at that time. Re-verify and
# re-pin deliberately on each future remediation pass rather than floating
# silently.
ARG FRANKENPHP_BASE_DIGEST=sha256:9e733c52ad3f2279d3e7144d70e91d5cf6d16a57a6dd4725d4d7e39a09f56359
# Vite 8 / laravel-vite-plugin 3.1 / @tailwindcss/oxide require Node >=20.19
# (see package.json "engines" and docs/ecs/ec2-dependency-audit.md).
ARG NODE_VERSION=20-bookworm-slim
ARG COMPOSER_VERSION=2.7

# ---------------------------------------------------------------------------
# Stage: frontend — compile Vite/Tailwind assets. Nothing from this stage
# except the compiled public/build output ships to the runtime image.
# ---------------------------------------------------------------------------
FROM composer:${COMPOSER_VERSION} AS composer_bin

FROM node:${NODE_VERSION} AS frontend
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts
COPY vite.config.js ./
COPY resources/ resources/
COPY public/ public/
RUN npm run build

# ---------------------------------------------------------------------------
# Stage: vendor — install PHP dependencies and compile PHP extensions,
# including a manually-configured `gd` build with AVIF support deliberately
# never enabled. This stage keeps full Debian/apt/build tooling throughout —
# none of it ships; only its compiled output is copied out below.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:${FRANKENPHP_BASE_TAG}@${FRANKENPHP_BASE_DIGEST} AS vendor
COPY --from=composer_bin /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# gd is deliberately NOT in this list — built manually below, without AVIF.
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    redis \
    bcmath \
    intl \
    zip \
    opcache \
    pcntl \
    posix \
    sockets \
    soap \
    sodium \
    exif \
    igbinary

# Manual gd build: JPEG/PNG/WebP/FreeType/XPM only. `libavif-dev`/`libaom-dev`
# are never installed in this RUN, and `--with-avif` is never passed to
# `docker-php-ext-configure gd` — GD's AVIF support is opt-in only (PHP 8.1+),
# so both omissions are independently sufficient; doing both means a future
# edit that adds one back still can't silently reintroduce AVIF without also
# adding the other. `libpng16-16`/`libwebp7`/`libfreetype6`/`libxpm4`/
# `libjpeg62-turbo` (the runtime .so packages, not just `-dev`) are pulled in
# automatically as dependencies of their own `-dev` packages here — the
# `runtime_libs` stage below installs its own copies for the final image,
# independent of this build stage.
#
# Deliberately NOT purging the `-dev` packages afterward: this whole stage
# is discarded (only the compiled extension `.so` files are copied out of
# it below) so leftover `-dev` headers here cost nothing, and a real build
# proved why this matters — `apt-get purge --autoremove` on the `-dev`
# packages here also autoremoves the *runtime* `libjpeg62-turbo`/
# `libpng16-16`/`libwebp7`/`libfreetype6`/`libxpm4` packages, since apt
# considers them "automatically installed" dependencies of the `-dev`
# packages with nothing else in this stage yet depending on them — which
# breaks `gd.so`'s own dynamic linkage (confirmed: `gd_info()` becomes an
# undefined function immediately afterward, because the extension fails to
# load with its shared libraries missing, not because it failed to compile).
#
# Deliberately unpinned: this pulls Trixie's CURRENT security-patched
# package versions rather than freezing a possibly-already-vulnerable
# version at Dockerfile-authoring time. Safe because (1) the base image
# above is pinned by exact digest (FRANKENPHP_BASE_DIGEST), so the Debian
# release itself is fixed and reproducible even though individual package
# versions float within it, and (2) this entire `vendor` stage is
# discarded — only the compiled `.so` files are copied out of it below —
# so no apt/dpkg metadata or package version from this RUN ever reaches
# the shipped image.
# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get -y --no-install-recommends install \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libxpm-dev \
        libfreetype6-dev \
    && docker-php-source extract \
    && docker-php-ext-configure gd \
        --with-jpeg \
        --with-webp \
        --with-xpm \
        --with-freetype \
    && docker-php-ext-install gd \
    && docker-php-ext-enable gd \
    && docker-php-source delete

# Build-time assertion: GD must support JPEG/PNG/WebP and must NOT support
# AVIF. Fails the build (non-zero exit) if either is false.
#
# The `$...` tokens below are PHP variables inside a single-quoted `php -r`
# script, not shell variables — the single quotes are load-bearing
# (deliberately preventing the shell from expanding them before PHP ever
# sees the script); converting to double quotes would break this.
# hadolint ignore=SC2016
RUN php -r ' \
    $info = gd_info(); \
    $required = ["JPEG Support", "PNG Support", "WebP Support"]; \
    foreach ($required as $key) { \
        if (empty($info[$key])) { \
            fwrite(STDERR, "BUILD ASSERTION FAILED: gd_info()[\"$key\"] is false\n"); \
            exit(1); \
        } \
    } \
    if (function_exists("imagecreatefromavif")) { \
        fwrite(STDERR, "BUILD ASSERTION FAILED: imagecreatefromavif() exists - AVIF support was NOT supposed to be compiled in\n"); \
        exit(1); \
    } \
    fwrite(STDERR, "OK: gd has jpeg/png/webp, no avif\n"); \
'

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --no-ansi \
    --no-progress

COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && (APP_ENV=production \
        APP_KEY="base64:0000000000000000000000000000000000000000=" \
        DB_CONNECTION=sqlite \
        DB_DATABASE=":memory:" \
        php artisan package:discover --ansi || true)

# Build-time assertion: every required extension loaded, curl supports
# both http:// and https://, running as the expected extension set.
#
# Same as the gd assertion above: `$...` tokens are PHP variables inside a
# single-quoted `php -r` script, not shell variables — single-quoting is
# intentional and load-bearing here.
# hadolint ignore=SC2016
RUN php -r ' \
    $required = ["pdo_pgsql","pgsql","redis","bcmath","gd","intl","zip","pcntl","posix","sockets","soap","sodium","exif","igbinary","curl"]; \
    $missing = []; \
    foreach ($required as $ext) { \
        if (!extension_loaded($ext)) { $missing[] = $ext; } \
    } \
    if (!extension_loaded("Zend OPcache")) { $missing[] = "Zend OPcache"; } \
    if ($missing) { \
        fwrite(STDERR, "BUILD ASSERTION FAILED: missing extensions: " . implode(", ", $missing) . "\n"); \
        exit(1); \
    } \
    $curl = curl_version(); \
    $protocols = $curl["protocols"] ?? []; \
    if (!in_array("http", $protocols, true) || !in_array("https", $protocols, true)) { \
        fwrite(STDERR, "BUILD ASSERTION FAILED: curl does not support http+https: " . implode(",", $protocols) . "\n"); \
        exit(1); \
    } \
    fwrite(STDERR, "OK: all required extensions loaded, curl supports http+https (curl " . $curl["version"] . ")\n"); \
'

# ---------------------------------------------------------------------------
# Stage: runtime_libs — assembles the exact file tree the distroless final
# stage needs: compiled extensions (copied from `vendor`), the frankenphp
# binary and its full transitive shared-library closure, a relocated `bash`
# binary (docker/entrypoint.sh's interpreter — distroless has no shell of
# its own), runtime-only versions of the image-library packages GD needs,
# the fixed libssh2 package, CA certificates, timezone data, and uid/gid
# 1000 passwd/group entries. This stage has full apt/dpkg/perl — none of it
# is copied into the final image; only the specific paths named below are.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:${FRANKENPHP_BASE_TAG}@${FRANKENPHP_BASE_DIGEST} AS runtime_libs

# This stage's Debian base always has /bin/bash (confirmed: it's also
# explicitly installed as a shipped runtime package below, and the final
# distroless stage's own /bin/bash is copied FROM here via `ldd`'s
# transitive closure — bash already has to exist and work in this stage
# for that to be possible). Several RUN instructions below pipe commands
# together (e.g. `dpkg -l | grep`, `ldd ... | awk ... | grep ... | sort`);
# without pipefail, a failure in an earlier stage of such a pipeline is
# masked by the last command's exit code. Scoped to this stage only — the
# distroless final stage below has no shell at all and gets no SHELL
# directive.
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Trixie package names (differ from Bookworm's libzip4/libicu72/libavif15 —
# see docs/security/ecs-image-vulnerability-exceptions.md): libzip5,
# libicu76, and libavif/libaom are deliberately NOT installed at all (GD was
# built without AVIF support above, so nothing in this image needs them).
# libssh2-1t64 is Trixie's fixed package (1.11.1-1+deb13u1 as of this pass —
# verified below to actually be that version, not merely assumed).
#
# Deliberately unpinned, for the same reason as the `vendor` stage above:
# this installs Trixie's CURRENT security-patched package versions rather
# than a version frozen at authoring time (the libssh2 fix this whole
# remediation pass exists for is itself a "current version" fix, not a
# pinned one). Safe because (1) the base image is pinned by exact digest
# (FRANKENPHP_BASE_DIGEST), and (2) this `runtime_libs` stage is never
# shipped either — only the specific files assembled into /rootfs below
# are copied into the distroless final stage; no apt/dpkg database or
# package-version metadata from this RUN reaches the shipped image.
# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get -y --no-install-recommends install \
        libpq5 \
        libzip5 \
        libicu76 \
        libsodium23 \
        libwebp7 \
        libpng16-16 \
        libxpm4 \
        libfreetype6 \
        libjpeg62-turbo \
        libssh2-1t64 \
        bash \
        ca-certificates \
        tzdata \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Build-time assertion: the fixed libssh2 version is actually what's
# installed, and libavif/libaom/libdav1d are NOT present anywhere in this
# stage (not merely "not requested" — actually absent from dpkg's database).
RUN set -eu; \
    installed="$(dpkg-query -W -f='${Version}' libssh2-1t64)"; \
    required="1.11.1-1+deb13u1"; \
    dpkg --compare-versions "$installed" ge "$required" || { \
        echo "BUILD ASSERTION FAILED: libssh2-1t64 $installed is older than required $required" >&2; \
        exit 1; \
    }; \
    echo "OK: libssh2-1t64 $installed >= $required"; \
    if dpkg -l 2>/dev/null | grep -qiE '^ii\s+(libavif|libaom|libdav1d)'; then \
        echo "BUILD ASSERTION FAILED: an avif/aom/dav1d package is installed and should not be:" >&2; \
        dpkg -l | grep -iE '^ii\s+(libavif|libaom|libdav1d)' >&2; \
        exit 1; \
    fi; \
    echo "OK: no libavif/libaom/libdav1d package installed"

# Compiled extensions from `vendor` (built once there, including the manual
# AVIF-free gd), copied here rather than recompiled.
COPY --from=vendor /usr/local/lib/php/extensions/no-debug-zts-20230831/ /usr/local/lib/php/extensions/no-debug-zts-20230831/
COPY --from=vendor /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/

# uid/gid 1000 `app` user/group — created here (this stage has useradd),
# its /etc/passwd and /etc/group entries copied verbatim into the distroless
# final stage below (distroless has no useradd of its own).
RUN groupadd --gid 1000 app \
    && useradd --uid 1000 --gid app --no-create-home --shell /usr/sbin/nologin app

# Build-time assertion: the entries about to be copied into the distroless
# final stage really do resolve to exactly uid/gid 1000, not merely
# "whatever `useradd` picked" — this stage's `useradd`/`groupadd` calls
# above would themselves fail at build time if 1000 were already taken, but
# assert the concrete values anyway so a future edit that removes the
# explicit `--uid`/`--gid` flags still fails loudly here instead of
# silently shipping a different id.
RUN set -eu; \
    uid="$(id -u app)"; \
    gid="$(id -g app)"; \
    if [ "$uid" != "1000" ] || [ "$gid" != "1000" ]; then \
        echo "BUILD ASSERTION FAILED: app user resolved to uid=$uid gid=$gid, expected 1000/1000" >&2; \
        exit 1; \
    fi; \
    echo "OK: app user is uid=1000 gid=1000"

# Assemble the exact rootfs subset the distroless stage needs. Computes the
# full transitive shared-library closure (via `ldd`) of every binary and
# every compiled PHP extension that ships, rather than guessing a package
# list and hoping nothing is missing — the same technique, applied
# exhaustively instead of iteratively, as every prior remediation pass in
# this repository's history used to catch a missing library (see git log
# for the gd.so libpng/libXpm/libfreetype miss this exact pattern would have
# caught up front).
#
# /bin/sh (-> dash) is included alongside /bin/bash for the same reason
# `docker/entrypoint.sh` needs bash: PHP's `proc_open()`
# (used internally by Symfony Process, which Laravel's `schedule:work`
# calls once per minute-tick to spawn `schedule:run` as a child process,
# even when zero Schedule:: entries are registered) execs commands via
# `/bin/sh` through `posix_spawn()`, not through PHP itself. Proven by a
# real failed smoke test: without `/bin/sh` present, the scheduler role
# booted fine but crashed on its first internal tick with
# "proc_open(): posix_spawn() failed: No such file or directory" —
# looking exactly like a hang/crash from the smoke test's point of view
# ("scheduler did not stay running"), not an obviously missing-shell
# error, because the failure happens deep inside a framework-spawned
# subprocess rather than at container startup.
#
# Two separate symlink problems, two separate fixes:
#  1. Trixie (like the distroless target) uses merged-/usr: /bin, /lib,
#     /lib64, /sbin are symlinks to their /usr/... counterparts. A bare
#     `mkdir -p /rootfs/bin` creates a REAL directory there, colliding with
#     the distroless base image's own /bin (a real symlink) at
#     `COPY --from=runtime_libs /rootfs/ /` ("cannot copy to non-directory").
#     Fixed by rewriting only that leading path segment to its /usr/...
#     form — a plain prefix substitution, not a full symlink resolution.
#  2. Many shared libraries are THEMSELVES a versioned symlink chain (e.g.
#     `libtinfo.so.6 -> libtinfo.so.6.5`) — the dynamic linker looks up the
#     exact SONAME (`libtinfo.so.6`), so copying only the fully-resolved
#     final target (what a naive `realpath` does) leaves that exact
#     filename missing. Proven by a real failed smoke test: `/bin/bash`
#     failed with "libtinfo.so.6: cannot open shared object file" despite
#     `libtinfo.so.6.5` being present. Fixed by walking and copying every
#     hop of the symlink chain, not just its final target.
#
# binaries/extensions/all_libs are bash arrays (this stage's SHELL is
# bash, set above) rather than space-joined strings — each element is
# passed to collect_closure()/copy_with_symlink_chain() as its own exact
# argument, with no word-splitting or globbing involved at all, so no
# path (however it were named) could ever be silently mis-split or glob-
# expanded.
RUN set -eu; \
    mkdir -p /rootfs; \
    rewrite_top_level() { \
        case "$1" in \
            /bin/*)   printf '%s\n' "/usr/bin/${1#/bin/}" ;; \
            /lib64/*) printf '%s\n' "/usr/lib64/${1#/lib64/}" ;; \
            /lib/*)   printf '%s\n' "/usr/lib/${1#/lib/}" ;; \
            /sbin/*)  printf '%s\n' "/usr/sbin/${1#/sbin/}" ;; \
            *)        printf '%s\n' "$1" ;; \
        esac; \
    }; \
    copy_with_symlink_chain() { \
        current="$1"; \
        while [ -L "$current" ]; do \
            dest="$(rewrite_top_level "$current")"; \
            mkdir -p "/rootfs$(dirname "$dest")"; \
            cp -a "$current" "/rootfs$dest"; \
            target="$(readlink "$current")"; \
            case "$target" in \
                /*) current="$target" ;; \
                *)  current="$(dirname "$current")/$target" ;; \
            esac; \
        done; \
        dest="$(rewrite_top_level "$current")"; \
        mkdir -p "/rootfs$(dirname "$dest")"; \
        cp -a "$current" "/rootfs$dest"; \
    }; \
    collect_closure() { \
        ldd "$@" 2>/dev/null | awk '{print $3}' | grep -E '^/' | sort -u; \
    }; \
    binaries=(/usr/local/bin/frankenphp /usr/local/bin/php /bin/bash /bin/sh); \
    mapfile -t extensions < <(find /usr/local/lib/php/extensions/no-debug-zts-20230831 -name '*.so'); \
    mapfile -t all_libs < <(collect_closure "${binaries[@]}" "${extensions[@]}"); \
    for lib in "${all_libs[@]}"; do \
        [ -n "$lib" ] || continue; \
        copy_with_symlink_chain "$lib"; \
    done; \
    # The binaries and extension .so files themselves (not just their deps).
    for b in "${binaries[@]}"; do \
        copy_with_symlink_chain "$b"; \
    done; \
    mkdir -p /rootfs/usr/local/lib/php/extensions/no-debug-zts-20230831; \
    cp -a /usr/local/lib/php/extensions/no-debug-zts-20230831/*.so /rootfs/usr/local/lib/php/extensions/no-debug-zts-20230831/; \
    mkdir -p /rootfs/usr/local/etc/php/conf.d; \
    cp -a /usr/local/etc/php/conf.d/. /rootfs/usr/local/etc/php/conf.d/; \
    # PHP's own default runtime files (php.ini-production baseline, if the
    # base image ships one under /usr/local/etc/php) and FrankenPHP's Caddy
    # binary support files.
    if [ -d /usr/local/php ]; then \
        cp -a /usr/local/php /rootfs/usr/local/php; \
    fi; \
    # CA certs (outbound HTTPS/TLS verification) and timezone data.
    mkdir -p /rootfs/etc/ssl /rootfs/usr/share/zoneinfo; \
    cp -a /etc/ssl/certs /rootfs/etc/ssl/; \
    cp -a /usr/share/zoneinfo/. /rootfs/usr/share/zoneinfo/; \
    cp -a /etc/ca-certificates.conf /rootfs/etc/ 2>/dev/null || true; \
    # uid/gid 1000 identity — exact entries only, not the whole passwd db.
    grep -E '^(root|app):' /etc/passwd > /rootfs/etc/passwd; \
    grep -E '^(root|app):' /etc/group > /rootfs/etc/group; \
    # /tmp and /var/www/html, correctly owned, for the app to actually run in.
    mkdir -p /rootfs/tmp /rootfs/var/www/html; \
    chmod 1777 /rootfs/tmp; \
    chown app:app /rootfs/var/www/html; \
    chmod 0755 /rootfs/var/www/html; \
    # FrankenPHP/Caddy's XDG dirs, owned by the non-root runtime user (same
    # rationale as every prior pass — Caddy autosaves config/TLS bookkeeping
    # there even with admin/auto_https disabled).
    mkdir -p /rootfs/config /rootfs/data; \
    chown app:app /rootfs/config /rootfs/data; \
    # The dynamic linker's own resolved-path cache and search-path config.
    # libphp.so lives at /usr/local/lib/, which is NOT one of glibc's
    # compiled-in default search directories (/lib, /usr/lib, /lib64,
    # /usr/lib64) — it's only reachable via the entry in
    # /etc/ld.so.conf.d/*.conf baked into /etc/ld.so.cache at image-build
    # time. Without copying this cache, the linker can't find libphp.so
    # even though the file is physically present at the right path —
    # proven by a real failed smoke test (`frankenphp: error while loading
    # shared libraries: libphp.so: cannot open shared object file`). The
    # cache remains valid for the (smaller) set of libraries actually
    # copied into /rootfs, since it only maps SONAME -> absolute path and
    # never asserts every cached entry must exist.
    mkdir -p /rootfs/etc/ld.so.conf.d; \
    cp -a /etc/ld.so.conf /rootfs/etc/ld.so.conf 2>/dev/null || true; \
    cp -a /etc/ld.so.conf.d/. /rootfs/etc/ld.so.conf.d/; \
    cp -a /etc/ld.so.cache /rootfs/etc/ld.so.cache

# Build-time assertion: no shared library is missing anywhere in the
# assembled rootfs closure, and no unwanted library slipped in.
RUN set -eu; \
    fail=0; \
    for f in /rootfs/usr/local/bin/frankenphp /rootfs/usr/local/lib/php/extensions/no-debug-zts-20230831/*.so; do \
        missing="$(ldd "$f" 2>&1 | grep 'not found' || true)"; \
        if [ -n "$missing" ]; then \
            echo "BUILD ASSERTION FAILED: missing shared library for $f:" >&2; \
            echo "$missing" >&2; \
            fail=1; \
        fi; \
    done; \
    if find /rootfs -iname '*avif*' -o -iname '*aom*' -o -iname '*dav1d*' 2>/dev/null | grep -q .; then \
        echo "BUILD ASSERTION FAILED: an avif/aom/dav1d file exists in the assembled rootfs:" >&2; \
        find /rootfs -iname '*avif*' -o -iname '*aom*' -o -iname '*dav1d*' >&2; \
        fail=1; \
    fi; \
    if find /rootfs -iname 'perl*' 2>/dev/null | grep -q .; then \
        echo "BUILD ASSERTION FAILED: a perl file exists in the assembled rootfs:" >&2; \
        find /rootfs -iname 'perl*' >&2; \
        fail=1; \
    fi; \
    if [ ! -e /rootfs/usr/bin/sh ]; then \
        echo "BUILD ASSERTION FAILED: /usr/bin/sh (rewritten from /bin/sh) is missing from the assembled rootfs — Symfony Process (used by Laravel's schedule:work to spawn schedule:run each tick) execs via posix_spawn()/proc_open() through /bin/sh, not through PHP itself; without it the scheduler role boots but crashes on its first tick" >&2; \
        fail=1; \
    fi; \
    if [ "$fail" -eq 1 ]; then exit 1; fi; \
    echo "OK: rootfs closure is complete, no avif/aom/dav1d/perl present, /bin/sh present"

# ---------------------------------------------------------------------------
# Stage: runtime — the image every ECS task actually runs. Distroless: no
# shell, no package manager, no dpkg, no Perl — only the exact files
# assembled in `runtime_libs` above, plus the application itself.
#
# Pinned to the exact linux/amd64 digest (not merely a tag) — resolved and
# verified during this remediation pass via:
#   docker buildx imagetools inspect gcr.io/distroless/base-debian13:latest
# which lists each platform's child-manifest digest under the `latest` tag's
# multi-arch index; cross-checked by pulling that exact digest with
# `--platform linux/amd64` and confirming
# `docker inspect ... --format '{{.Architecture}}/{{.Os}}'` reports
# `amd64/linux`. Re-resolve and re-verify on each future remediation pass
# rather than floating silently (same policy as FRANKENPHP_BASE_DIGEST
# above).
# ---------------------------------------------------------------------------
FROM gcr.io/distroless/base-debian13@sha256:5c53b546dd6721a33dc6288641a25e0b2c6274b237bcf27cf47393013604b549 AS runtime

ARG GIT_SHA=unknown
ARG BUILD_DATE=unknown
LABEL org.opencontainers.image.revision="${GIT_SHA}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.title="firmsbase-app" \
      org.opencontainers.image.description="FirmsBase application image — web/worker/trust-worker/scheduler/migrate/maintenance, selected by command" \
      org.opencontainers.image.source="https://github.com/Aramitusconnect/firmsbase"

# `gcr.io/distroless/base-debian13` is an unrelated base image — it does
# NOT inherit any of the env vars the `dunglas/frankenphp` image sets
# (confirmed via `docker inspect` on both). `PATH` and `SSL_CERT_FILE`
# already happen to match/suffice; `XDG_CONFIG_HOME`/`XDG_DATA_HOME` and
# `GODEBUG` do not exist here at all unless set explicitly — without them,
# Caddy falls back to computing a config/data path under `$HOME` (`app`'s
# passwd entry has no real home directory, so this fails outright: a real
# smoke test showed "mkdir /home/app: permission denied"). Values copied
# verbatim from `docker inspect dunglas/frankenphp:1-php8.3-trixie`.
ENV XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data \
    GODEBUG=cgocheck=0 \
    PHP_INI_DIR=/usr/local/etc/php

COPY --from=runtime_libs /rootfs/ /

COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-firmsbase-production.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-firmsbase-opcache.ini
COPY docker/web/Caddyfile /etc/caddy/Caddyfile

WORKDIR /var/www/html

# Application source. .dockerignore excludes .env*, .git, tests/, node_modules,
# storage/logs contents, and anything else that must never reach an image
# layer (see .dockerignore for the full, commented list).
COPY --chown=1000:1000 . .
COPY --from=vendor --chown=1000:1000 /var/www/html/vendor ./vendor
COPY --from=vendor --chown=1000:1000 /var/www/html/bootstrap/cache ./bootstrap/cache
COPY --from=frontend --chown=1000:1000 /app/public/build ./public/build

# No RUN here — distroless has no shell to run anything in. Every directory
# that must be writable at runtime was created and chowned in `runtime_libs`;
# the ones that live under the app source root (storage/, bootstrap/cache)
# are chowned via the --chown flags on the COPY instructions above and via
# the app's own committed .gitkeep-backed directory structure.

HEALTHCHECK NONE

USER 1000:1000

EXPOSE 8080

ENTRYPOINT ["/bin/bash", "docker/entrypoint.sh"]
CMD ["web"]
