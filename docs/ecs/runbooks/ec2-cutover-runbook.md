# EC2 → ECS Cutover Runbook (Production — Planning Document Only)

**Date:** 2026-07-13
**Branch:** `feature/ecs-readiness-foundation`

**This runbook is not executed by this mission.** This branch does not provision production infrastructure, does not change production DNS, does not shift production traffic, and does not disable any EC2 service (see mission boundaries). This document exists so that when a production cutover is later authorized, there is a reviewed sequence to follow rather than an improvised one — and so [staging-readiness-report.md](../staging-readiness-report.md) can point at something concrete when it lists "what's still required."

## Prerequisites before this runbook can start (none met by this branch)

- [ ] Staging has run on ECS successfully for a meaningful soak period (team-defined; not decided here).
- [ ] A production `infrastructure/ecs/environments/production/` Terraform root module exists and has been reviewed (see `environments/production/README.md` — does not exist yet).
- [ ] Production-sized RDS/Redis decisions have been made and provisioned (see [infrastructure-architecture.md](../infrastructure-architecture.md) "hardening follow-ups" and [staging-readiness-report.md](../staging-readiness-report.md) "required database/Redis decisions").
- [ ] Production ACM certificate issued and validated for the real production hostname.
- [ ] Production secrets (`APP_KEY` — **must be the same key currently in use on EC2**, not a freshly generated one, or every existing encrypted value/session becomes unreadable — `DB_PASSWORD` for the production database, `REDIS_PASSWORD`) provisioned in Secrets Manager.
- [ ] A human decision on whether production ECS connects to the **same** RDS instance the EC2 deployment currently uses, or a migrated/replicated one — this is a data-continuity decision this document cannot make.
- [ ] Deployment circuit breaker, alarms, and on-call paging (SNS subscription — see [alarm-inventory.md](../alarm-inventory.md)) are live and tested in staging first.

## Planned sequence (once authorized)

1. **Provision production ECS infrastructure** (`terraform apply` against a reviewed `environments/production/`) — cluster, IAM roles, ALB **on a new, not-yet-public hostname or with DNS not yet pointed at it**, ECS services at `desiredCount` sized for production load but **not yet receiving real traffic**.
2. **Deploy the exact digest already running in staging** (never rebuild — the whole point of digest-based promotion, see [container-architecture.md](../container-architecture.md) "Immutable digest promotion" — is that what passed staging is bit-for-bit what goes to production).
3. **Run the migration task against the production database** during a low-traffic window, with the EC2 deployment still fully serving traffic and unaffected (the migration only touches schema; expand-contract discipline, per [database-migrations.md](../database-migrations.md), means the currently-running EC2 code must remain compatible with the post-migration schema until the cutover completes).
4. **Verify the new ECS stack independently**, without customer traffic: smoke tests, a manual QA pass, `/readyz` returning healthy, alarms quiet.
5. **Shift a small percentage of traffic** (weighted DNS or ALB-level canary, depending on what the real DNS/routing setup supports — not decided here) from EC2 to ECS. Watch error rates/latency/alarms closely.
6. **Progressively increase the ECS traffic share** while EC2 continues serving the remainder, watching the same signals at each step.
7. **Full cutover**: DNS points entirely at the ECS ALB. EC2 continues running, fully idle of new traffic, as the immediate rollback target.
8. **Soak period** with EC2 kept warm (not decommissioned) — length is a team decision, not specified here.
9. **Decommission EC2** only after the soak period passes with no rollback needed, and only as its own separate, explicitly authorized action — **this step is never automatic and is not part of any pipeline in this repository.**

## Rollback during cutover

At every step from 5 onward, rolling back means shifting DNS/routing back toward EC2 — which remains fully capable of serving 100% of traffic until step 9 is explicitly authorized and executed. This is the entire reason step 9 is deliberately the last, separate, most-delayed step: EC2 is the safety net for every earlier step in this runbook, and remains so until a human decides the ECS deployment has proven itself.

## What this mission built to make this runbook possible later

Everything else in `docs/ecs/` and `infrastructure/ecs/` — the container image, the task definitions, the IAM roles, the alarms, the staging deploy pipeline. This runbook is the one document in the branch that describes work explicitly **not done**, by design, and states plainly what would need to be true before it could be.
