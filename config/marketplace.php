<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MyAttorney Marketplace Analytics Retention
    |--------------------------------------------------------------------------
    |
    | Mission 2 (MyAttorney Marketplace Core), checkpoint 13.
    | directory_marketplace_analytics_events rows older than this are
    | pruned by the marketplace:analytics:prune scheduled command
    | (bootstrap/app.php, daily). 400 days (~13 months) is the default —
    | enough for year-over-year aggregate comparisons without keeping
    | this append-only table growing forever, matching the retention-
    | window convention config/integrations.php already establishes for
    | its own event-log tables.
    |
    */

    'analytics_retention_days' => env('MARKETPLACE_ANALYTICS_RETENTION_DAYS', 400),

];
