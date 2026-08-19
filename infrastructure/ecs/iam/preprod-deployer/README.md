# firmsbase-preprod-deployer — IAM package for owner review

**Nothing here has been created.** These are documents for review; the role,
the policies and the attachment are all owner actions.

## Why a separate identity

`firmsbase-production-deployer` must not be reused — it is scoped to
`firmsbase-production-*` and to the production Terraform state key, and
widening it would make one credential capable of mutating two environments.
`firmsbase-staging-operator` must not be broadened either: it is a long-lived
IAM user, and attaching infrastructure-build policies directly to it would
make every staging operation carry preproduction authority. The staging
operator receives exactly one new capability — `sts:AssumeRole` on this role
and nothing else.

## Files

| File | Purpose |
| --- | --- |
| `firmsbase-preprod-deployer-trust-policy.json` | Only `user/firmsbase-staging-operator` may assume the role |
| `firmsbase-preprod-deployer-allow-policy.json` | Build preproduction; state access limited to `environments/preproduction/*` |
| `firmsbase-preprod-deployer-deny-policy.json` | Explicit denies that override any Allow |
| `assume-preprod-deployer.json` | The single grant added to the staging operator |

## Boundaries this enforces

- Terraform state: read/write limited to `environments/preproduction/*`, and an
  explicit `Deny` on `environments/production/*` and `environments/staging/*`.
  A `Deny` beats an `Allow` anywhere in the evaluation, including any future
  policy that widens the allow side by mistake.
- Named staging and production clusters, services and RDS instances are denied
  outright, in addition to a tag-based deny for anything tagged with a
  different `Environment`. The tag-based rule alone would not protect an
  untagged resource, which is why both exist.
- `iam:PassRole` is limited to `firmsbase-preprod-*` and conditioned on
  `iam:PassedToService = ecs-tasks.amazonaws.com`.
- ECR is read-only. Every push, upload and delete action is explicitly denied:
  preproduction consumes the certified artifact and must never be able to
  create one.
- Route 53 changes are limited to `preprod.firmsvault.com` and its subdomains,
  with an explicit deny on the apex, `www`, the staging names and the four
  production application hostnames.
- IAM escalation and permissions-boundary tampering are denied.

## Bootstrap — owner runs these, not Claude

```bash
aws iam create-role \
  --role-name firmsbase-preprod-deployer \
  --assume-role-policy-document file://firmsbase-preprod-deployer-trust-policy.json \
  --description "Builds and destroys the FirmsBase preproduction release-certification environment"

for p in allow deny; do
  aws accessanalyzer validate-policy --policy-type IDENTITY_POLICY \
    --policy-document file://firmsbase-preprod-deployer-$p-policy.json \
    --query 'findings[?findingType==`ERROR`]' --output text   # must be empty
  aws iam create-policy \
    --policy-name firmsbase-preprod-deployer-$p \
    --policy-document file://firmsbase-preprod-deployer-$p-policy.json
  aws iam attach-role-policy --role-name firmsbase-preprod-deployer \
    --policy-arn arn:aws:iam::603013471426:policy/firmsbase-preprod-deployer-$p
done

aws iam create-policy \
  --policy-name firmsbase-assume-preprod-deployer \
  --policy-document file://assume-preprod-deployer.json
aws iam attach-user-policy \
  --user-name firmsbase-staging-operator \
  --policy-arn arn:aws:iam::603013471426:policy/firmsbase-assume-preprod-deployer
```

Access Analyzer must report zero `ERROR` findings before `create-policy`. That
check has not been run here: `access-analyzer:ValidatePolicy` is denied to the
identity available to Claude.
