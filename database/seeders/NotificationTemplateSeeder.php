<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Models\NotificationTemplate;
use App\Services\NotificationTemplateService;
use App\Services\TenantContextService;
use Illuminate\Database\Seeder;

/**
 * NotificationTemplateSeeder — Mission 6 (Real Communications &
 * Notification Delivery). NotificationTemplateService::createGlobalDefault()
 * had zero production seed callers before this — meaning
 * NotificationDispatchService::dispatch() resolved template=null and
 * short-circuited (Blocked) for every template-key caller already
 * wired to it: document-request-chase reminders, invoice dunning, and
 * (as of this mission) Send Invoice. Ensures exactly one Active,
 * global-default (firm_id null) row exists per (key, channel,
 * language) this codebase's own callers actually resolve against —
 * confirmed by reading each caller's own templateKey/channel argument:
 *   - AutomationTemplateCatalog::templates()['document_request_reminder']
 *     ['actions'][0]['config'] => template_key 'document_request_reminder', channel 'email'
 *   - AutomationTemplateCatalog::templates()['invoice_overdue_client_reminder']
 *     ['actions'][0]['config'] => template_key 'invoice_overdue_reminder', channel 'email'
 *     (note: the AUTOMATION RULE key is 'invoice_overdue_client_reminder';
 *     the NOTIFICATION TEMPLATE key its NotifyClient action actually
 *     resolves is the different string 'invoice_overdue_reminder' —
 *     read from the config array itself, not assumed from the rule's
 *     own key.)
 *   - AutomationTemplateCatalog::templates()['payment_plan_installment_client_reminder']
 *     ['actions'][0]['config'] => template_key 'payment_plan_installment_missed', channel 'email'
 *   - SendInvoiceAction::dispatchSentNotification() => template_key 'invoice_sent', channel 'email'
 *
 * Idempotent by design (check-before-create against the exact same
 * (key, channel, language, Active) tuple NotificationTemplateService::
 * resolve() itself queries), rather than a one-shot/destructive
 * seeder, because:
 *   - `php artisan db:seed` is NOT part of this codebase's normal
 *     deploy/migrate flow (DatabaseSeeder::run() itself early-returns
 *     outside local/testing — see its own docblock — so it is not
 *     safe to assume this seeder ever runs in a real deployment via
 *     that path).
 *   - The content here is a *minimal, sensible default*, not a firm's
 *     actual sender-verified template — a real deployment still needs
 *     an operator (or a future Platform Admin Control Center flow) to
 *     review/replace from_email/from_domain and run domain
 *     verification before mail can actually go out from these rows:
 *     NotificationDispatchService::dispatch() blocks on
 *     NotificationTemplate::isDomainVerified() regardless of whether
 *     this seeder has run.
 *
 * DECISION REQUIRED (documented here, not resolved by this seeder):
 * this class is called from DatabaseSeeder for local/testing
 * convenience only (DatabaseSeeder::run()'s own environment guard
 * means the call added there never executes outside local/testing).
 * For staging/production, whoever owns the FirmsVault ops runbook
 * needs to decide how this actually gets invoked against a real
 * deployment — e.g. a dedicated console command run once per
 * environment after a real sender domain is verified, or a
 * deliberately production-safe seeder invocation. Not built here:
 * adding a new console command/boot hook is outside this mission's
 * file-ownership boundary and would be a larger, unreviewed surface
 * change than "seed the missing template rows" calls for.
 */
class NotificationTemplateSeeder extends Seeder
{
    /**
     * @return array<int, array{key: string, channel: ConsentChannel, subject: string, body: string}>
     */
    public static function defaults(): array
    {
        return [
            [
                'key' => 'document_request_reminder',
                'channel' => ConsentChannel::Email,
                'subject' => 'Documents needed for your matter',
                'body' => 'This is a reminder that we are still waiting on one or more documents for your matter. Please log in to your client portal to review and upload what is outstanding.',
            ],
            [
                'key' => 'invoice_overdue_reminder',
                'channel' => ConsentChannel::Email,
                'subject' => 'Your invoice is overdue',
                'body' => 'This is a reminder that you have an invoice with an outstanding balance. Please log in to your client portal to review and make a payment, or contact us if you have already paid.',
            ],
            [
                'key' => 'payment_plan_installment_missed',
                'channel' => ConsentChannel::Email,
                'subject' => 'A scheduled payment was missed',
                'body' => 'A scheduled installment on your payment plan was missed. Please log in to your client portal to review your payment plan, or contact us to make alternate arrangements.',
            ],
            [
                'key' => 'invoice_sent',
                'channel' => ConsentChannel::Email,
                'subject' => 'A new invoice is available',
                'body' => 'A new invoice has been issued for your matter. Please log in to your client portal to review the details.',
            ],
        ];
    }

    public function run(): void
    {
        $service = app(NotificationTemplateService::class);
        $tenantContext = app(TenantContextService::class);

        foreach (self::defaults() as $default) {
            $exists = $tenantContext->runWithoutFirmContext(fn () => NotificationTemplate::query()
                ->whereNull('firm_id')
                ->where('key', $default['key'])
                ->where('channel', $default['channel']->value)
                ->where('language', 'en')
                ->where('status', NotificationTemplateStatus::Active->value)
                ->exists());

            if ($exists) {
                continue;
            }

            $service->createGlobalDefault(
                key: $default['key'],
                channel: $default['channel'],
                body: $default['body'],
                subject: $default['subject'],
            );
        }
    }
}
