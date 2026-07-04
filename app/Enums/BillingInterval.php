<?php

namespace App\Enums;

/**
 * BillingInterval — plans.billing_interval and
 * platform_subscriptions.billing_interval. Not given exact values by
 * the master plan; proposed during Phase 6 planning and approved.
 */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';
}
