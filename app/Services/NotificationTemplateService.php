<?php

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Models\PlatformAdmin;
use App\Services\Configuration\NotificationTemplateContentPolicyService;

/**
 * NotificationTemplateService — resolve() implements the global-
 * default-with-firm-override lookup (approved decision): a firm-
 * specific Active row for (key, channel, language) wins; otherwise the
 * global (firm_id null) Active row is used. create()/createFirmOverride()
 * are the only places new rows are written.
 *
 * Phase 4 (FirmsVault Platform Admin Control Center, "Configuration"
 * category) addition: createGlobalDefault()/createFirmOverride()/
 * archive() now each accept an optional $actor (PlatformAdmin) and,
 * when supplied, record a PlatformAdminAuditEventRecorder audit row —
 * this phase's own architecture investigation confirmed all three
 * previously had "no actor parameter, no audit-trail call... add both
 * before wiring". $actor is optional so every pre-existing caller
 * (currently only tests call these methods directly — this class's own
 * docblock and the architecture investigation both confirm zero live
 * production call sites for this entire subsystem today) keeps
 * byte-for-byte unchanged behavior when it is omitted.
 *
 * createGlobalDefault() uses recordPlatformEvent() (the null-firm_id
 * variant) — a global default template has firm_id = null by
 * definition, genuinely platform-wide, not tied to any one firm.
 * createFirmOverride() uses record() (firm-scoped) — a firm override
 * carries a real, single, non-nullable firm_id at creation time.
 * archive() branches between the two based on the template's OWN
 * firm_id, mirroring this method's own existing runWithFirmContext()/
 * runWithoutFirmContext() branch immediately below it.
 */
class NotificationTemplateService
{
    private const AUDIT_CATEGORY = 'notification_templates';

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function resolve(?Firm $firm, string $key, ConsentChannel $channel, string $language = 'en'): ?NotificationTemplate
    {
        if ($firm) {
            $override = NotificationTemplate::query()
                ->where('firm_id', $firm->id)
                ->where('key', $key)
                ->where('channel', $channel->value)
                ->where('language', $language)
                ->where('status', NotificationTemplateStatus::Active->value)
                ->first();

            if ($override) {
                return $override;
            }
        }

        return NotificationTemplate::query()
            ->whereNull('firm_id')
            ->where('key', $key)
            ->where('channel', $channel->value)
            ->where('language', $language)
            ->where('status', NotificationTemplateStatus::Active->value)
            ->first();
    }

    public function createGlobalDefault(
        string $key,
        ConsentChannel $channel,
        string $body,
        string $language = 'en',
        ?string $subject = null,
        ?string $fromEmail = null,
        ?string $fromDomain = null,
        ?PlatformAdmin $actor = null,
    ): NotificationTemplate {
        $this->assertContentIsSafe($subject, $body);

        $create = fn () => NotificationTemplate::create([
            'firm_id' => null,
            'key' => $key,
            'channel' => $channel,
            'language' => $language,
            'status' => NotificationTemplateStatus::Active,
            'subject' => $subject,
            'body' => $body,
            'from_email' => $fromEmail,
            'from_domain' => $fromDomain,
        ]);

        $template = app(TenantContextService::class)->runWithoutFirmContext($create);

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'notification_template_global_default_created',
                self::AUDIT_CATEGORY,
                [
                    'notification_template_id' => $template->id,
                    'key' => $template->key,
                    'channel' => $template->channel->value,
                    'language' => $template->language,
                ],
            );
        }

        return $template;
    }

    public function createFirmOverride(
        Firm $firm,
        string $key,
        ConsentChannel $channel,
        string $body,
        string $language = 'en',
        ?string $subject = null,
        ?string $fromEmail = null,
        ?string $fromDomain = null,
        ?PlatformAdmin $actor = null,
    ): NotificationTemplate {
        $this->assertContentIsSafe($subject, $body);

        $create = fn () => NotificationTemplate::create([
            'firm_id' => $firm->id,
            'key' => $key,
            'channel' => $channel,
            'language' => $language,
            'status' => NotificationTemplateStatus::Active,
            'subject' => $subject,
            'body' => $body,
            'from_email' => $fromEmail,
            'from_domain' => $fromDomain,
        ]);

        $template = app(TenantContextService::class)->runWithFirmContext($firm, $create);

        if ($actor !== null) {
            $this->auditRecorder->record(
                $firm,
                $actor,
                'notification_template_firm_override_created',
                self::AUDIT_CATEGORY,
                [
                    'notification_template_id' => $template->id,
                    'key' => $template->key,
                    'channel' => $template->channel->value,
                    'language' => $template->language,
                ],
            );
        }

        return $template;
    }

    /**
     * Configuration Control Plane (mission section 70). Template content
     * is CONTENT — never executable code. Refused at write time, in the
     * canonical service, so the guarantee does not depend on any form.
     *
     * Nothing renders these bodies today (NotificationDispatchService
     * never interpolates one, and DispatchNotificationJob performs no
     * transport call), so no stored directive can execute right now.
     * That is precisely why enforcing it now is worthwhile: it costs
     * nothing while the table is small, and it means whoever wires a
     * real renderer later cannot inherit a corpus of templates that
     * already contains Blade or PHP.
     *
     * Variable NAMES are deliberately not validated — no canonical
     * variable registry exists in this codebase, so there is no
     * approved vocabulary to check against, and inventing one would
     * fabricate a governance capability. See
     * NotificationTemplateContentPolicyService's own docblock.
     */
    private function assertContentIsSafe(?string $subject, ?string $body): void
    {
        $errors = (new NotificationTemplateContentPolicyService)->validate($subject, $body);

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
    }

    public function archive(NotificationTemplate $template, ?PlatformAdmin $actor = null): NotificationTemplate
    {
        $tenantContext = app(TenantContextService::class);
        $firmId = $template->firm_id;

        $body = function () use ($template) {
            $template->update(['status' => NotificationTemplateStatus::Archived]);

            return $template->fresh();
        };

        $archived = $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);

        if ($actor !== null) {
            $metadata = [
                'notification_template_id' => $archived->id,
                'key' => $archived->key,
                'channel' => $archived->channel->value,
            ];

            if ($firmId !== null) {
                $this->auditRecorder->record(
                    Firm::query()->findOrFail($firmId),
                    $actor,
                    'notification_template_archived',
                    self::AUDIT_CATEGORY,
                    $metadata,
                );
            } else {
                $this->auditRecorder->recordPlatformEvent(
                    $actor,
                    'notification_template_archived',
                    self::AUDIT_CATEGORY,
                    $metadata,
                );
            }
        }

        return $archived;
    }
}
