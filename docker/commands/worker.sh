#!/bin/bash
# ECS "worker" role — general or critical queue worker, selected entirely by
# which queue name(s) the ECS task's WORKER_QUEUES env var lists (same
# image, same command, different task definition — see
# docs/ecs/container-architecture.md and docs/ecs/queue-and-redis-architecture.md).
#
# Uses `queue:work`, never `queue:listen` — `queue:listen` re-bootstraps the
# entire framework on every single job (slow, and defeats opcache/config
# caching benefits); `queue:work` boots once and loops, recycling itself
# after WORKER_MAX_JOBS/WORKER_MAX_TIME to bound memory growth.
#
# Laravel's queue worker installs SIGTERM/SIGINT handlers (pcntl is present,
# confirmed in docs/ecs/ec2-dependency-audit.md) and only checks for a
# shutdown signal BETWEEN jobs, never mid-job — it will finish the job
# currently executing, then exit without pulling another. Combined with
# ECS's stopTimeout (docs/ecs/graceful-shutdown.md), this is what "do not
# partially complete payment or trust operations" is built on: the job
# finishes cleanly or the container is killed only after stopTimeout, which
# is set comfortably longer than WORKER_TIMEOUT.
set -euo pipefail
cd /var/www/html

connection="${WORKER_CONNECTION:-${QUEUE_CONNECTION:-database}}"
queues="${WORKER_QUEUES:-default}"
tries="${WORKER_TRIES:-3}"
timeout="${WORKER_TIMEOUT:-90}"
sleep_seconds="${WORKER_SLEEP:-3}"
max_jobs="${WORKER_MAX_JOBS:-500}"
max_time="${WORKER_MAX_TIME:-3600}"
memory_limit="${WORKER_MEMORY:-256}"
backoff="${WORKER_BACKOFF:-10,30,60}"

exec php artisan queue:work "$connection" \
  --queue="$queues" \
  --tries="$tries" \
  --timeout="$timeout" \
  --sleep="$sleep_seconds" \
  --max-jobs="$max_jobs" \
  --max-time="$max_time" \
  --memory="$memory_limit" \
  --backoff="$backoff" \
  --no-interaction
