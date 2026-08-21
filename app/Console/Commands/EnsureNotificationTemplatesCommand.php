<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Console\Command;

/**
 * firmsvault:ensure-notification-templates — Follow-up 3 ("Notification
 * Template Provisioning"). Builds the "Option A — idempotent deployment
 * command" that NotificationTemplateSeeder's own docblock already
 * identifies as the correct fix: `DatabaseSeeder::run()` early-returns
 * outside local/testing, so `NotificationTemplateSeeder` (wired only
 * from `DatabaseSeeder`) never runs in staging/production via the
 * normal `db:seed` path, and `NotificationDispatchService::dispatch()`
 * fails closed (Blocked) for every template-key caller until the four
 * global-default rows exist.
 *
 * Deliberately thin: it instantiates `NotificationTemplateSeeder`
 * directly and calls its own `run()` method rather than duplicating
 * the `defaults()` array or the check-before-create logic anywhere.
 * `NotificationTemplateSeeder::run()` only reaches into the container
 * (`app(NotificationTemplateService::class)`,
 * `app(TenantContextService::class)`) and never calls `$this->command`/
 * `$this->call()`, so it works identically whether invoked via
 * `db:seed` or instantiated standalone here — confirmed by reading its
 * source, not assumed.
 *
 * Safe to run in any environment (no environment guard here — that is
 * the entire point of this command existing alongside the
 * local/testing-only seeder) and safe to run repeatedly: idempotency
 * is entirely inherited from the seeder's own check-before-create
 * logic (exact same (key, channel, language='en', status=Active,
 * firm_id=null) tuple `NotificationTemplateService::resolve()` itself
 * queries) — nothing here adds or weakens that guarantee.
 *
 * DECISION REQUIRED (documented here, not resolved by this command):
 * this command is intentionally NOT wired into the scheduler or any
 * deploy script. Recommended usage is a one-time manual/CI step per
 * environment, run once mail configuration and sender-domain
 * verification are in place — matching the precondition the seeder's
 * own docblock already states (NotificationDispatchService::dispatch()
 * still blocks on NotificationTemplate::isDomainVerified() regardless
 * of whether these rows exist). Leaving the actual invocation timing
 * to ops is deliberate: it is an operational decision, not something
 * this follow-up should silently decide by adding a schedule.
 */
final class EnsureNotificationTemplatesCommand extends Command
{
    protected $signature = 'firmsvault:ensure-notification-templates';

    protected $description = 'Idempotently provisions the global-default notification templates NotificationDispatchService::dispatch() requires to resolve, safe to run in any environment including staging/production.';

    public function handle(): int
    {
        (new NotificationTemplateSeeder)->run();

        $this->info('Notification template defaults ensured (existing Active global-default rows were left untouched).');

        return self::SUCCESS;
    }
}
