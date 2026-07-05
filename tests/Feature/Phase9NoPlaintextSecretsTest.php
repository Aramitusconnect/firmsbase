<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Confirms no plaintext OAuth token column and no plaintext email
 * body column exists anywhere in the Phase 9 schema (project rule).
 */
class Phase9NoPlaintextSecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_oauth_tokens_has_no_plaintext_token_column(): void
    {
        $columns = Schema::getColumnListing('email_oauth_tokens');

        $this->assertNotContains('access_token', $columns);
        $this->assertNotContains('refresh_token', $columns);
        $this->assertNotContains('raw_token', $columns);
        $this->assertNotContains('token', $columns);
        $this->assertContains('encrypted_token_ciphertext', $columns);
    }

    public function test_email_messages_has_no_plaintext_body_column(): void
    {
        $columns = Schema::getColumnListing('email_messages');

        $this->assertNotContains('body', $columns);
        $this->assertNotContains('body_html', $columns);
        $this->assertNotContains('body_text', $columns);
        $this->assertContains('encrypted_body_ciphertext', $columns);
    }

    public function test_encrypted_columns_are_hidden_from_model_array_and_json_serialization(): void
    {
        $account = \App\Models\EmailAccount::factory()->create();
        (new \App\Services\EncryptionKeyService())->provision($account->firm);

        $message = \App\Models\EmailMessage::factory()->forAccount($account)->create([
            'storage_mode' => \App\Enums\EmailStorageMode::EncryptedBody->value,
            'body_status' => \App\Enums\EmailBodyStatus::Encrypted->value,
            'encrypted_body_ciphertext' => 'fake-ciphertext-value',
        ]);

        $this->assertArrayNotHasKey('encrypted_body_ciphertext', $message->toArray());
    }
}
