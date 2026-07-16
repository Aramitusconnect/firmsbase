# HTTPS Remediation Plan (prepared, NOT executed)

## Current state (verified live)
- ALB `firmsbase-staging-alb` has only an HTTP:80 listener.
- No HTTPS:443 listener exists.
- No ACM certificate exists or is referenced anywhere in the verified live state.

## Gate (do not lift until every step below is complete and verified)
No client data and no real user traffic may be sent to this environment until
HTTPS is configured and independently verified. Synthetic HTTP-only checks
(the `/up` and `/readyz` curl calls in `runtime-verification-commands.sh`)
are the ONLY traffic permitted against the HTTP-80 listener, and only for
initial infrastructure validation.

## Blocker
**A staging domain name is required and none is assumed or invented here.**
ACM DNS validation and the ALB HTTPS listener both need a real hostname
(e.g. `staging.example.com`) that resolves (or will resolve) to the ALB.
This plan cannot proceed past step 1 until the domain is supplied by the
user/organization.

## Steps (none executed)

1. **Obtain the staging domain name** from the user. Confirm which DNS
   provider/zone hosts it (Route 53 or external).

2. **Request an ACM certificate in us-east-1** (must match the ALB's
   region; ALBs only accept certs from the same region as the ALB):
   ```
   aws acm request-certificate \
     --domain-name <staging-domain> \
     --validation-method DNS \
     --tags Key=Application,Value=FirmsBase Key=Environment,Value=staging
   ```

3. **Retrieve the DNS validation CNAME record** ACM generates:
   ```
   aws acm describe-certificate --certificate-arn <cert-arn>
   ```
   Create that CNAME in the domain's DNS zone (Route 53 or external
   registrar, depending on step 1's answer).

4. **Wait for validation** (`Status: ISSUED`):
   ```
   aws acm wait certificate-validated --certificate-arn <cert-arn>
   ```

5. **Create the HTTPS:443 listener** on the existing ALB, using a modern
   TLS security policy (e.g. `ELBSecurityPolicy-TLS13-1-2-2021-06`):
   ```
   aws elbv2 create-listener \
     --load-balancer-arn <alb-arn> \
     --protocol HTTPS --port 443 \
     --ssl-policy ELBSecurityPolicy-TLS13-1-2-2021-06 \
     --certificates CertificateArn=<cert-arn> \
     --default-actions Type=forward,TargetGroupArn=<tg-arn>
   ```

6. **Modify the existing HTTP:80 listener** to redirect to HTTPS instead of
   forwarding to the target group:
   ```
   aws elbv2 modify-listener \
     --listener-arn <http-80-listener-arn> \
     --default-actions '[{"Type":"redirect","RedirectConfig":{"Protocol":"HTTPS","Port":"443","StatusCode":"HTTP_301"}}]'
   ```

7. **Point the staging domain at the ALB** (Route 53 alias record or
   external CNAME to the ALB's DNS name), if not already done as part of
   validation in step 3.

8. **Final verification (only after all of the above)**:
   ```
   curl -s -o /dev/null -w "up: %{http_code}\n"     "https://<staging-domain>/up"
   curl -s -o /dev/null -w "readyz: %{http_code}\n" "https://<staging-domain>/readyz"
   curl -s -o /dev/null -w "redirect: %{http_code}\n" "http://<staging-domain>/up"   # expect 301
   ```
   Both HTTPS checks must return 200, and the HTTP check must return a 301
   redirect, before this environment is considered eligible for anything
   beyond synthetic checks.

## Explicitly not covered by the current task-definition/service package
`SESSION_SECURE_COOKIE=true` is already set in all six task definitions in
anticipation of HTTPS — this is safe today because no cookie-bearing traffic
is sent over HTTP-80 yet (synthetic `/up`/`/readyz` checks only, no
authenticated requests), but it does mean the application will not accept
session cookies at all until this HTTPS plan is completed.
