# Production environment — placeholder only

**No production infrastructure is defined here.** This directory exists so the repository's structure documents where production configuration *would* go, per the mission's requirement to "separate reusable modules, staging environment, and production environment placeholders."

This mission (`feature/ecs-readiness-foundation`) is explicitly scoped to staging readiness. It does not:

- define a production Terraform root module,
- provision any production AWS resource,
- change production DNS,
- shift production traffic, or
- touch the existing EC2-hosted production deployment in any way.

When a production environment is authorized, it will reuse the same modules under `infrastructure/ecs/modules/` that the staging environment (`../staging/`) uses — the modules are already environment-agnostic (no staging-specific values are hardcoded inside them; everything environment-specific is a variable passed in by the root module). Building `environments/production/main.tf` at that point is expected to be mostly a copy of `environments/staging/main.tf` with production-sized variable values (larger RDS/Redis instances, `desired_count`/autoscaling ranges appropriate for production load, `enable_deletion_protection = true` on the ALB, a production ACM certificate, and — separately and explicitly — an actual decision about the EC2-to-ECS cutover sequence, see [../../../docs/ecs/staging-readiness-report.md](../../../docs/ecs/staging-readiness-report.md) "exact staging deployment sequence" and the EC2 cutover runbook referenced there).

That work requires explicit human authorization and is out of scope for this branch.
