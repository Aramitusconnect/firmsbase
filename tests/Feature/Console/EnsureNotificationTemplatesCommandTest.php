<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Models\NotificationTemplate;
use App\Services\TenantContextService;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * EnsureNotificationTemplatesCommandTest — Follow-up 3 ("Notification
 * Template Provisioning"). Proves `firmsvault:ensure-notification-templates`
 * (unlike NotificationTemplateSeeder, which DatabaseSeeder::run() only
 * calls in local/testing) is a safe, idempotent, environment-agnostic
 * way to provision the four global-default notification templates
 * NotificationDispatchService::dispatch() requires to resolve.
 */
final class EnsureNotificationTemplatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function globalDefaults(): Collection
    {
        return app(TenantContextService::class)->runWithoutFirmContext(
            fn () => NotificationTemplate::query()->whereNull('firm_id')->get()
        );
    }

    public function test_first_run_creates_all_four_default_templates(): void
    {
        $exitCode = Artisan::call('firmsvault:ensure-notification-templates');

        $this->assertSame(0, $exitCode);

        $templates = $this->globalDefaults();
        $this->assertCount(4, $templates);

        $expectedKeys = collect(NotificationTemplateSeeder::defaults())->pluck('key')->sort()->values();
        $this->assertSame($expectedKeys->all(), $templates->pluck('key')->sort()->values()->all());

        foreach ($templates as $template) {
            $this->assertSame(ConsentChannel::Email, $template->channel);
            $this->assertSame('en', $template->language);
            $this->assertSame(NotificationTemplateStatus::Active, $template->status);
            $this->assertNull($template->firm_id);
        }
    }

    public function test_second_run_produces_no_duplicates(): void
    {
        Artisan::call('firmsvault:ensure-notification-templates');
        $firstRunCount = $this->globalDefaults()->count();

        $exitCode = Artisan::call('firmsvault:ensure-notification-templates');

        $this->assertSame(0, $exitCode);
        $this->assertSame(4, $firstRunCount);
        $this->assertSame(4, $this->globalDefaults()->count(), 'Re-running the command must never create duplicate rows.');
    }

    public function test_third_run_is_still_idempotent(): void
    {
        Artisan::call('firmsvault:ensure-notification-templates');
        Artisan::call('firmsvault:ensure-notification-templates');
        Artisan::call('firmsvault:ensure-notification-templates');

        $this->assertSame(4, $this->globalDefaults()->count());
    }

    public function test_an_existing_customized_template_with_the_same_key_is_never_overwritten(): void
    {
        $tenantContext = app(TenantContextService::class);

        $customized = $tenantContext->runWithoutFirmContext(fn () => NotificationTemplate::create([
            'firm_id' => null,
            'key' => 'invoice_sent',
            'channel' => ConsentChannel::Email,
            'language' => 'en',
            'status' => NotificationTemplateStatus::Active,
            'subject' => 'Custom subject an operator wrote',
            'body' => 'Custom body content an operator wrote, deliberately different from the seeder default.',
        ]));

        $exitCode = Artisan::call('firmsvault:ensure-notification-templates');

        $this->assertSame(0, $exitCode);

        $fresh = $tenantContext->runWithoutFirmContext(
            fn () => NotificationTemplate::query()->whereNull('firm_id')->where('key', 'invoice_sent')->first()
        );

        $this->assertTrue($fresh->is($customized), 'The command must not replace an existing Active row with a different id.');
        $this->assertSame('Custom subject an operator wrote', $fresh->subject);
        $this->assertSame('Custom body content an operator wrote, deliberately different from the seeder default.', $fresh->body);

        // The other three keys the customized row does not cover must still be provisioned.
        $this->assertCount(4, $this->globalDefaults());
    }

    public function test_the_command_runs_without_error_regardless_of_app_environment(): void
    {
        $original = config('app.env');

        try {
            config(['app.env' => 'production']);
            $exitCode = Artisan::call('firmsvault:ensure-notification-templates');
            $this->assertSame(0, $exitCode, 'Unlike the seeder (guarded to local/testing by DatabaseSeeder), this command must run cleanly in production.');
            $this->assertCount(4, $this->globalDefaults());
        } finally {
            config(['app.env' => $original]);
        }
    }
}
