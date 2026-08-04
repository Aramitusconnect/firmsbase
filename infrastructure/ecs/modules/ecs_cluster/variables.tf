variable "cluster_name" {
  type = string
}

variable "tags" {
  type    = map(string)
  default = {}
}

variable "container_insights_enabled" {
  description = "Whether the cluster's containerInsights setting is \"enabled\" or \"disabled\". No default, deliberately — this module previously hardcoded \"enabled\" unconditionally. This staging environment's live cluster actually has it disabled (confirmed via aws ecs describe-clusters — Settings: [{name: containerInsights, value: disabled}]) — see docs/ecs/state-adoption-plan.md §9.10/§9.11. Every caller must decide explicitly rather than silently inheriting a value that may not match the live cluster it's importing against."
  type        = bool
}

variable "capacity_providers" {
  description = "Capacity providers associated with the cluster. Defaults to [\"FARGATE\", \"FARGATE_SPOT\"] — this module's original design intent, unaffected for a brand-new environment. This staging environment's live cluster currently has NO capacity providers associated at all (confirmed via aws ecs describe-clusters — capacityProviders: [], defaultCapacityProviderStrategy: []; every live service instead uses a fixed launch_type=FARGATE) — see docs/ecs/state-adoption-plan.md §9.10/§9.11. Set to [] for live-compatible adoption; associating capacity providers with the live cluster is a separate, explicitly reviewed decision, not a byproduct of import."
  type        = list(string)
  default     = ["FARGATE", "FARGATE_SPOT"]
}

variable "default_capacity_provider" {
  description = "The capacity_provider used in the default_capacity_provider_strategy block, when var.capacity_providers is non-empty. Ignored (the block is omitted entirely) when var.capacity_providers is empty — an empty default strategy referencing zero associated providers would be nonsensical."
  type        = string
  default     = "FARGATE"
}
