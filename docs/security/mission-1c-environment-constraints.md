# Mission 1C — Environment Constraints

Recorded once, at the start of Mission 1C, so every later report item that
says "blocked" or "unverified" can point back to a single documented reason
rather than repeating unverified claims of infrastructure access.

## What this session actually has

```
$ aws sts get-caller-identity
{
    "UserId": "AROATEIILH6MR2NG54CYN:i-09fb02cdc8b9ef5ba",
    "Account": "215302750105",
    "Arn": "arn:aws:sts::215302750105:assumed-role/AmazonLightsailInstanceRole/i-09fb02cdc8b9ef5ba"
}
```

This is a Lightsail instance role attached to the sandbox VM this session
runs in. It is **not** FirmsVault's real infrastructure account. Mission 1B's
own Terraform (`infrastructure/ecs/environments/staging/variables.tf`,
`aws_account_id` variable) documents the real staging account as
`603013471426` — a different account entirely. Confirmed directly:

```
$ aws ecs list-clusters --region us-east-1
AccessDeniedException: not authorized to perform: ecs:ListClusters
$ aws rds describe-db-instances --region us-east-1
AccessDenied: not authorized to perform: rds:DescribeDBInstances
```

Zero ECS/RDS/CloudTrail/GuardDuty/WAF/Backup permissions, in the wrong
account regardless.

## No live staging origin is reachable

```
$ nslookup app.firmsvault.com     → NXDOMAIN
$ nslookup client.firmsvault.com  → NXDOMAIN
$ nslookup admin.firmsvault.com   → NXDOMAIN
```

There is no deployed FirmsVault instance this session can point a browser or
`curl` at. (`firmsvault.com` itself resolves, but that's the marketing
domain, not the application.)

## No browser automation is connected

No tool in this session can drive a real browser, register a WebAuthn
credential against a real authenticator, or capture real CSP violation
reports from a live page load.

## What this rules out, precisely

Everything requiring one or more of: the real AWS account, a reachable
staging origin, or a real browser:

- WebAuthn browser/authenticator validation (§3 of the Mission 1C prompt)
- Real-browser CSP violation collection (§6)
- WAF attachment to a real ALB + hostile-traffic proof (§9)
- GuardDuty/Security Hub/IAM Access Analyzer finding-consumption proof (§10)
- CloudTrail event-recording proof against real AWS API calls (§11)
- RDS live security-posture audit (§12)
- Database TLS *runtime* proof against the real RDS instance (§13) —
  the Terraform *configuration* (`DB_SSLMODE=require`) was already
  confirmed by Mission 1B; only the live runtime check is blocked here
- ECS public-IP / security-group live exposure audit (§14)
- AWS Backup creation + non-production restore proof (§20)
- Security-alert delivery-destination proof (§22)
- Staging attack-surface smoke test against a live origin (§23)

## What is genuinely still achievable here

Everything that is pure application code, pure Terraform code review
(`fmt`/`validate`, no `apply`), or can be proven with tools actually present
in this sandbox:

- Docker (confirmed present and working) — used for the ECS
  read-only-root-filesystem local proof and the ClamAV quarantine-flow proof.
- The full Laravel test suite against a local disposable PostgreSQL database
  (the same harness used throughout Mission 1B).
- Static/code-level review of every remaining item (Firm MFA enrollment,
  document-download authorization primitive, Microsoft Graph revocation,
  security-audit event routing).

This document is the single source of truth for "why is this marked
blocked" throughout the rest of Mission 1C's work — later reports reference
it rather than re-explaining the constraint each time.
