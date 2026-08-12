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

    /*
    |--------------------------------------------------------------------------
    | MyAttorney Intake Retention
    |--------------------------------------------------------------------------
    |
    | Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 14 — the
    | "abandoned-intake retention sweep" the marketplace_intakes
    | create-table migration's own docblock already reserved this
    | checkpoint for. A terminal, NEVER-CONVERTED intake (Declined/
    | Abandoned/Expired) has its prospect PII scrubbed by the
    | marketplace:intakes:retention:sweep scheduled command once it has
    | been sitting in that state for this many days — never a Converted
    | intake, whose identity fields now legitimately live on the
    | resulting Client, governed by that record's own separate
    | retention regime. 90 days is short relative to the analytics
    | window above on purpose: a never-converted intake is unsolicited
    | personal data with no ongoing legitimate business purpose once
    | the Firm has finished (or never started) reviewing it, unlike
    | aggregate, already-anonymous analytics counters.
    |
    */

    'intake_retention_days' => env('MARKETPLACE_INTAKE_RETENTION_DAYS', 90),

];
