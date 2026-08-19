output "acm_certificate_arn" {
  description = "Consumed by the ephemeral runtime root via a data lookup, not by remote state — the runtime root must not be able to mutate anything in this state."
  value       = aws_acm_certificate_validation.preprod.certificate_arn
}

output "apex_hostname" {
  value = var.apex_hostname
}
