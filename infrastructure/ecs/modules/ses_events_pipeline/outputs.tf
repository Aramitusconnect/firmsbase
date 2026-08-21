output "queue_url" {
  value = aws_sqs_queue.ses_events.url
}

output "queue_arn" {
  value = aws_sqs_queue.ses_events.arn
}

output "queue_name" {
  value = aws_sqs_queue.ses_events.name
}

output "dlq_arn" {
  value = aws_sqs_queue.ses_events_dlq.arn
}

output "dlq_name" {
  value = aws_sqs_queue.ses_events_dlq.name
}

output "topic_arn" {
  value = aws_sns_topic.ses_events.arn
}

output "configuration_set_name" {
  value = aws_sesv2_configuration_set.staging.configuration_set_name
}
