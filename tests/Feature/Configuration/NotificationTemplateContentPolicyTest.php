<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Enums\ConsentChannel;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Services\Configuration\NotificationTemplateContentPolicyService;
use App\Services\NotificationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mission section 70: template content is CONTENT, never executable
 * code. Enforced in the canonical NotificationTemplateService, so the
 * guarantee holds for any caller, not just the admin form.
 *
 * Also pins the honest boundary of what can be validated: variable
 * NAMES cannot be checked, because this codebase has no canonical
 * variable registry to check them against.
 */
class NotificationTemplateContentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private NotificationTemplateContentPolicyService $policy;

    private NotificationTemplateService $templates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = app(NotificationTemplateContentPolicyService::class);
        $this->templates = app(NotificationTemplateService::class);
    }

    #[DataProvider('executableContent')]
    public function test_executable_content_is_rejected(string $body): void
    {
        $this->assertNotEmpty(
            $this->policy->validate(null, $body),
            'executable content must never validate',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function executableContent(): array
    {
        return [
            'php open tag' => ['Hello <?php echo $x; ?>'],
            'php short echo' => ['Hello <?= $x ?>'],
            'blade if' => ['@if ($client) Hello @endif'],
            'blade foreach' => ['@foreach ($items as $i) {{ $i }} @endforeach'],
            'blade php directive' => ['@php $x = 1; @endphp'],
            'blade include' => ['@include("partials.header")'],
            'blade unescaped output' => ['{!! $raw !!}'],
            'blade comment' => ['{{-- secret --}}'],
            'script tag' => ['<script>alert(1)</script>'],
            'javascript url' => ['<a href="javascript:alert(1)">click</a>'],
            'function call in placeholder' => ['{{ system("ls") }}'],
        ];
    }

    #[DataProvider('malformedPlaceholders')]
    public function test_malformed_placeholders_are_rejected(string $body): void
    {
        $this->assertNotEmpty($this->policy->validate(null, $body));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedPlaceholders(): array
    {
        return [
            'unclosed' => ['Hello {{ client_name'],
            'empty placeholder' => ['Hello {{ }}'],
            'expression-like' => ['Hello {{ client.name | upper }}'],
            'spaces in name' => ['Hello {{ client name }}'],
        ];
    }

    #[DataProvider('validContent')]
    public function test_ordinary_template_content_is_accepted(string $body): void
    {
        $this->assertSame([], $this->policy->validate(null, $body));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validContent(): array
    {
        return [
            'plain text' => ['Your consultation is confirmed.'],
            'single variable' => ['Hello {{ client_name }}, welcome.'],
            'multiple variables' => ['Hi {{ client_name }}, your invoice from {{ firm_name }} is ready.'],
            'dotted variable' => ['Hello {{ client.first_name }}.'],
            'benign html' => ['<p>Hello <strong>{{ client_name }}</strong></p>'],
            'email-like word containing at' => ['Contact us at support@example.com'],
        ];
    }

    public function test_the_subject_line_is_validated_too(): void
    {
        $this->assertNotEmpty($this->policy->validate('@if($x) Hi @endif', 'Safe body.'));
    }

    public function test_variables_are_extracted_in_order_and_deduplicated(): void
    {
        $variables = $this->policy->extractVariables(
            'Hi {{ client_name }}, {{ firm_name }} wrote to {{ client_name }}.'
        );

        $this->assertSame(['client_name', 'firm_name'], $variables);
    }

    public function test_content_with_no_variables_extracts_none(): void
    {
        $this->assertSame([], $this->policy->extractVariables('No placeholders here.'));
        $this->assertSame([], $this->policy->extractVariables(null));
    }

    /**
     * Mission section 100 — the gap is disclosed, not faked.
     */
    public function test_variable_name_validation_is_reported_as_unavailable(): void
    {
        $this->assertFalse($this->policy->variableRegistryAvailable());
        $this->assertStringContainsString('Not implemented', $this->policy->variableRegistryStatus());
    }

    public function test_an_unrecognized_but_well_formed_variable_is_not_rejected(): void
    {
        // Without a registry there is no such thing as an "unknown"
        // variable — rejecting one would enforce an invented allowlist.
        $this->assertSame([], $this->policy->validate(null, 'Hello {{ some_future_variable }}.'));
    }

    // --- enforcement at the canonical service ---

    public function test_a_global_default_with_blade_content_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/never executable code/i');

        $this->templates->createGlobalDefault(
            'zzz_test_key',
            ConsentChannel::Email,
            '@if ($x) Hello @endif',
        );
    }

    public function test_a_firm_override_with_php_content_is_refused(): void
    {
        $firm = Firm::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->templates->createFirmOverride(
            $firm,
            'zzz_test_key',
            ConsentChannel::Email,
            'Hello <?php echo "x"; ?>',
        );
    }

    public function test_no_row_is_written_when_content_is_refused(): void
    {
        $before = NotificationTemplate::query()->count();

        try {
            $this->templates->createGlobalDefault('zzz_test_key', ConsentChannel::Email, '{!! $raw !!}');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame($before, NotificationTemplate::query()->count());
    }

    public function test_a_safe_global_default_is_still_created_normally(): void
    {
        $template = $this->templates->createGlobalDefault(
            'zzz_safe_key',
            ConsentChannel::Email,
            'Hello {{ client_name }}, your invoice is ready.',
            subject: 'Invoice from {{ firm_name }}',
        );

        $this->assertTrue($template->exists);
        $this->assertSame([], $this->policy->validate($template->subject, $template->body));
    }
}
