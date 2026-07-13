#!/usr/bin/env bash
# ECS "scheduler" role. Runs as a single long-lived ECS SERVICE (desiredCount
# fixed at 1, no autoscaling — see docs/ecs/infrastructure-architecture.md)
# rather than a task triggered every minute, so Laravel's own in-process
# `withoutOverlapping()` locking (backed by the configured cache store) is
# sufficient to prevent duplicate concurrent runs without an extra
# distributed-lock mechanism. `schedule:work` loops internally and dispatches
# whatever routes/console.php registers each minute; it checks for a
# shutdown signal between ticks, never mid-command, and exits safely on
# SIGTERM (see docs/ecs/graceful-shutdown.md).
#
# NOTE (see docs/ecs/ec2-dependency-audit.md §3 and
# docs/ecs/staging-readiness-report.md): routes/console.php currently
# registers zero Schedule:: entries, so this process will run correctly but
# have nothing to dispatch until that is added as a separate, deliberate
# application change. This script provides the capability, not the schedule
# content.
set -euo pipefail
cd /var/www/html

exec php artisan schedule:work --no-interaction
