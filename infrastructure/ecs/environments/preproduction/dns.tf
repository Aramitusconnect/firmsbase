# Runtime aliases live in the EPHEMERAL root, not the persistent one, because
# they point at an ALB that is destroyed and recreated each certification
# cycle. Terraform recreates them against the new ALB automatically.
#
# The certificate they are served under comes from the persistent root and is
# read by data lookup rather than remote state — this root should be able to
# READ the certificate, never to mutate anything the persistent root owns.

data "aws_acm_certificate" "preprod" {
  domain      = var.apex_hostname
  statuses    = ["ISSUED"]
  most_recent = true
}

locals {
  # Exactly the six canonical hosts the certified image expects. Supplied as
  # environment configuration, never baked into the image.
  preprod_hostnames = [
    var.apex_hostname,
    "app.${var.apex_hostname}",
    "client.${var.apex_hostname}",
    "admin.${var.apex_hostname}",
    "myattorney.${var.apex_hostname}",
    "api.${var.apex_hostname}",
  ]
}

resource "aws_route53_record" "app" {
  for_each = toset(local.preprod_hostnames)

  zone_id = var.route53_zone_id
  name    = each.value
  type    = "A"

  alias {
    name                   = module.alb.alb_dns_name
    zone_id                = module.alb.alb_zone_id
    evaluate_target_health = true
  }
}
