# Deprecated staging bootstrap files

The files in this directory are retained only as a historical record of the
initial ECS staging bootstrap.

Do not register task definitions, create services, or update the live staging
cluster from these JSON or shell files. They contain obsolete image digests and
configuration and are not maintained as an active deployment source.

The intended staging ECS configuration is defined in:

infrastructure/ecs/environments/staging/main.tf

The active deployed state must be confirmed directly through Amazon ECS before
performing an update.

When creating a new task-definition revision:

1. Inspect the service's current live task definition.
2. Apply the intended configuration from the Terraform source.
3. Preserve unrelated live settings.
4. Register a new revision.
5. Update the affected service using its existing rolling-deployment settings.
6. Verify the running task definition and service stability.

Do not introduce static AWS access keys. ECS workloads must use their assigned
task IAM roles.
