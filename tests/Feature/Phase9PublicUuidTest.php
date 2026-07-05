<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Confirms the approved Phase 9 uuid decision: 3 workflow models carry
 * a public uuid (EmailAccount, EmailMessage, EmailAttachment), while
 * email_oauth_tokens (secret material), email_message_links (join
 * row), email_sync_events (audit row), and email_visibility_rules
 * (policy row) do not.
 */
class Phase9PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('uuidModelProvider')]
    public function test_model_has_a_public_uuid(string $modelClass): void
    {
        $instance = $modelClass::factory()->create();

        $this->assertNotNull($instance->uuid);
    }

    public static function uuidModelProvider(): array
    {
        return [
            [EmailAccount::class],
            [EmailMessage::class],
            [EmailAttachment::class],
        ];
    }

    #[DataProvider('noUuidTableProvider')]
    public function test_table_has_no_uuid_column(string $table): void
    {
        $columns = Schema::getColumnListing($table);

        $this->assertNotContains('uuid', $columns);
    }

    public static function noUuidTableProvider(): array
    {
        return [
            ['email_oauth_tokens'],
            ['email_message_links'],
            ['email_sync_events'],
            ['email_visibility_rules'],
        ];
    }
}
