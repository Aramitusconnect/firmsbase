<?php

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Services\TenantContextService;

/**
 * NotificationTemplateService — resolve() implements the global-
 * default-with-firm-override lookup (approved decision): a firm-
 * specific Active row for (key, channel, language) wins; otherwise the
 * global (firm_id null) Active row is used. create()/createFirmOverride()
 * are the only places new rows are written.
 */
class NotificationTemplateService
{
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
    ): NotificationTemplate {
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

        return app(TenantContextService::class)->runWithoutFirmContext($create);
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
    ): NotificationTemplate {
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

        return app(TenantContextService::class)->runWithFirmContext($firm, $create);
    }

    public function archive(NotificationTemplate $template): NotificationTemplate
    {
        $tenantContext = app(TenantContextService::class);
        $firmId = $template->firm_id;

        $body = function () use ($template) {
            $template->update(['status' => NotificationTemplateStatus::Archived]);

            return $template->fresh();
        };

        return $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }
}
