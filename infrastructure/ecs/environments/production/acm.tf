# Production certificate for the four application hostnames.
#
# api.firmsvault.com is deliberately NOT a SAN: the hostname is reserved but no
# API is exposed in this release, and a certificate covering it would invite one.
#
# Only the ACM validation CNAMEs are created in the zone. The public A/AAAA
# aliases for the application hostnames are a separate owner approval and are
# not present in this configuration at all.

resource "aws_acm_certificate" "app" {
  domain_name               = var.application_hostnames[0]
  subject_alternative_names = slice(var.application_hostnames, 1, length(var.application_hostnames))
  validation_method         = "DNS"

  tags = { Name = "${var.name_prefix}-cert" }

  lifecycle {
    create_before_destroy = true
  }
}

resource "aws_route53_record" "cert_validation" {
  for_each = {
    for dvo in aws_acm_certificate.app.domain_validation_options : dvo.domain_name => {
      name   = dvo.resource_record_name
      record = dvo.resource_record_value
      type   = dvo.resource_record_type
    }
  }

  zone_id         = var.route53_zone_id
  name            = each.value.name
  type            = each.value.type
  records         = [each.value.record]
  ttl             = 60
  allow_overwrite = true
}

resource "aws_acm_certificate_validation" "app" {
  certificate_arn         = aws_acm_certificate.app.arn
  validation_record_fqdns = [for r in aws_route53_record.cert_validation : r.fqdn]
}
