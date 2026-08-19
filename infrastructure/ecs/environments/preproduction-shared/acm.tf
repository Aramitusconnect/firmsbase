# Preproduction certificate — the one genuinely persistent, genuinely free
# resource in this environment.
#
# It lives in its own Terraform root because the runtime root is destroyed
# after every certification cycle. Issuing a DNS-validated certificate takes
# minutes and rewrites validation CNAMEs each time; keeping it here removes
# that from the critical path at zero cost, and means a destroy of the runtime
# can never take the certificate with it.
#
# One wildcard SAN rather than six explicit names: preprod hostnames exist to
# mirror whatever the release exposes, and re-issuing a certificate because a
# role was added would be a pointless gate.

resource "aws_acm_certificate" "preprod" {
  domain_name               = var.apex_hostname
  subject_alternative_names = ["*.${var.apex_hostname}"]
  validation_method         = "DNS"

  tags = { Name = "${var.name_prefix}-cert" }

  lifecycle {
    create_before_destroy = true
  }
}

resource "aws_route53_record" "cert_validation" {
  for_each = {
    for dvo in aws_acm_certificate.preprod.domain_validation_options : dvo.domain_name => {
      name   = dvo.resource_record_name
      record = dvo.resource_record_value
      type   = dvo.resource_record_type
    }
  }

  zone_id = var.route53_zone_id
  name    = each.value.name
  type    = each.value.type
  records = [each.value.record]
  ttl     = 60

  # The apex and the wildcard validate to the same CNAME name; allow_overwrite
  # keeps that from being a duplicate-record error.
  allow_overwrite = true
}

resource "aws_acm_certificate_validation" "preprod" {
  certificate_arn         = aws_acm_certificate.preprod.arn
  validation_record_fqdns = [for r in aws_route53_record.cert_validation : r.fqdn]
}
