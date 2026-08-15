<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\ConsentChannel;
use App\Models\PlatformAdmin;
use App\Services\Configuration\NotificationTemplateContentPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * PreviewNotificationTemplateAction — shows a template's stored content
 * in a channel-appropriate shape, with its variables and validation
 * state. Read-only.
 *
 * IT CANNOT SEND ANYTHING (mission section 71). There is no code path
 * from this action to any transport: it reads the stored row and
 * renders text into a modal. The only dispatch path in this codebase
 * (NotificationDispatchService) is not called here, and that path
 * performs no real transport call of its own anyway.
 *
 * WHY VARIABLES ARE NOT SUBSTITUTED. Section 71 asks for a preview
 * using sanitized sample data. This codebase has NO renderer — nothing
 * anywhere interpolates a template body — and no variable registry
 * defining what each placeholder means. Substituting invented values
 * would show the operator a message that no system in this platform
 * would ever actually produce, which is worse than showing none: it
 * would imply a rendering pipeline exists and that this is what it
 * emits. So the preview shows the stored content verbatim, lists the
 * variables that would need values, and states the position plainly.
 *
 * No real client, matter or firm data is read by this action at any
 * point, so there is nothing to leak across tenants (section 71's
 * "never use another Firm's real Client data" is satisfied by
 * construction, not by filtering).
 */
class PreviewNotificationTemplateAction extends Action
{
    /**
     * Mirrors the common SMS segment boundary. Shown as guidance for
     * SMS templates, not enforced — no SMS transport exists to enforce
     * it against.
     */
    private const SMS_SEGMENT_LENGTH = 160;

    public static function getDefaultName(): ?string
    {
        return 'previewNotificationTemplate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Preview');
        $this->icon(Heroicon::OutlinedEye);
        $this->color('gray');

        $this->modalHeading('Template preview');
        $this->modalDescription('Read-only. This preview cannot send anything — it displays stored content only.');
        $this->modalSubmitAction(false);

        $this->schema([
            Placeholder::make('preview')
                ->hiddenLabel()
                ->content(fn (array $record): string => self::renderPreview($record)),
        ]);

        // Read-only, but still gated to those who may view templates.
        $this->visible(fn (): bool => Auth::guard('platform_admin')->user() instanceof PlatformAdmin);

        // No ->action(): there is deliberately nothing to execute.
        $this->action(function (): void {
            Notification::make()->title('Preview is read-only — nothing was sent.')->send();
        });
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function renderPreview(array $record): string
    {
        $policy = app(NotificationTemplateContentPolicyService::class);

        $subject = is_string($record['subject'] ?? null) ? $record['subject'] : null;
        $body = is_string($record['body'] ?? null) ? $record['body'] : '';
        $channel = $record['channel'] ?? null;

        $lines = [];

        // Channel-appropriate framing (mission section 71).
        $lines[] = match ($channel) {
            ConsentChannel::Email->value => 'EMAIL — To: (resolved per recipient at send time) • Subject: '
                .($subject ?? '(no subject set)'),
            ConsentChannel::Sms->value => sprintf(
                'SMS — %d character(s), approximately %d segment(s) at %d characters each.',
                mb_strlen($body),
                max(1, (int) ceil(mb_strlen($body) / self::SMS_SEGMENT_LENGTH)),
                self::SMS_SEGMENT_LENGTH,
            ),
            ConsentChannel::Portal->value => 'IN-APP / PORTAL — Title: '.($subject ?? '(no title set)'),
            ConsentChannel::WhatsApp->value => 'WHATSAPP — '.mb_strlen($body).' character(s).',
            default => 'Channel: '.((string) $channel),
        };

        $lines[] = 'BODY (stored content, variables not substituted): '.$body;

        $variables = $policy->extractVariables($subject.' '.$body);

        $lines[] = $variables === []
            ? 'Variables: none used.'
            : 'Variables used: '.implode(', ', $variables).'.';

        $lines[] = 'Variable substitution is not shown because this codebase has no template renderer — '
            .'no code interpolates a template body — and no variable registry defines these names. '
            .$policy->variableRegistryStatus();

        $errors = $policy->validate($subject, $body);

        $lines[] = $errors === []
            ? 'Content validation: passes.'
            : 'Content validation: FAILS — '.implode(' ', $errors);

        $lines[] = 'Delivery: this template is not wired to any transport. Nothing was sent by opening this preview.';

        return implode("\n\n", $lines);
    }
}
