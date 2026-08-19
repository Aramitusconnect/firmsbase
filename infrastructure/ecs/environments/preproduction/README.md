# firmsbase-preprod — release-certification environment

A **third ECS cluster**, not a third application build.

```
development / WIP        -> firmsbase-staging-cluster      (537-migration lineage)
freeze + build ONCE      -> certified immutable digest
certify that digest      -> firmsbase-preprod-cluster      (this environment)
promote the same digest  -> firmsbase-production-cluster   (Blue/Green, later)
```

Every role here runs the digest CI already certified, pulled from the
repository where CI published it. No image is built, rebuilt, retagged or
copied for this environment.

## Two Terraform roots

| Root | State key | Lifecycle |
| --- | --- | --- |
| `../preproduction-shared` | `environments/preproduction/shared/terraform.tfstate` | Persistent — ACM certificate + validation records only |
| `.` (this directory) | `environments/preproduction/ecs/terraform.tfstate` | Ephemeral — destroyed and recreated each certification cycle |

The runtime root reads the certificate by data lookup, never by remote state,
so it can read it but cannot mutate anything the persistent root owns.

## Commands

Use the pinned binary. The `terraform` on `PATH` is 1.9.8 and must not be used.

```bash
/home/ubuntu/bin/terraform-1.15.8 init -reconfigure
/home/ubuntu/bin/terraform-1.15.8 plan \
  -var='image_digest=sha256:<the certified digest>' \
  -out=preprod.tfplan
```

`image_digest` has no default and is validated to be `sha256:<64 hex>`, so a
tag or `latest` cannot reach a task definition.

## Certification sequence

1. `apply` this root.
2. Run `bootstrap/01-create-roles-and-database.sql` once, as the RDS master
   identity, against the empty database.
3. Run the `firmsbase-preprod-migrate` task **once** with `ecs run-task`.
   Require container exit code 0 and `stopCode = EssentialContainerExited`.
4. Assert `SELECT count(*) FROM migrations = 275`, distinct = 275.
5. Assert FORCE-RLS state matches the certified source exactly, in both
   directions — no extra tables, none missing.
6. Verify target health, canonical `/up` = 200, `/readyz` = 200 with
   `database=ok` and `redis=ok`, and that the raw ALB hostname returns **400**
   (intended TrustHosts behaviour, not a failure).
7. Record the running `imageDigest` on all four services; it must equal the
   certified digest.
8. Destroy.

## Deliberate gaps, to be stated in certification evidence

- `PREPROD_MULTI_AZ=false` — RDS failover behaviour is **not** certified here.
- One NAT gateway, not one per AZ — cross-AZ egress redundancy is not certified.
- No permissions boundaries on preproduction task roles. The three existing
  boundary policies are production-scoped; preproduction boundaries would be a
  separate owner-approved bootstrap.
- The ALB health-check path here is `/up`. **Production's target group uses
  `/readyz`**, and the certified image rewrites the health-check Host header
  for path `/up` only — so this environment does not reproduce production's
  health-check path. See the promotion notes before Blue/Green.
