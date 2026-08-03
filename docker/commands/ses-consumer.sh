#!/bin/bash
# ECS "ses-consumer" role — the dedicated long-running SES bounce/complaint
# SQS consumer (App\Console\Commands\ConsumeSesEventsCommand, see
# docs/ecs/container-architecture.md and docs/ecs/graceful-shutdown.md).
#
# Deliberately NOT `queue:work`: this queue carries raw SES/SNS event JSON,
# not serialized Laravel job payloads (see the command's own docblock).
# `ses:consume-events` loops internally (long-polling SQS via the AWS SDK,
# which resolves credentials through the ECS task role — never a static
# key/secret), so this is a single `exec`'d foreground process exactly like
# docker/commands/scheduler.sh, receiving SIGTERM directly as PID 1.
set -euo pipefail
cd /var/www/html

exec php artisan ses:consume-events --no-interaction
