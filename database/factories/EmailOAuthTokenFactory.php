<?php

namespace Database\Factories;

use App\Enums\EmailOAuthTokenStatus;
use App\Enums\EmailOAuthTokenType;
use App\Enums\TenantEncryptionKeyStatus;
use App\Models\EmailAccount;
use App\Models\EmailOAuthToken;
use App\Models\TenantEncryptionKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<EmailOAuthToken>
 *
 * encrypted_token_ciphertext here is produced via the plain Crypt
 * facade (APP_KEY) rather than the module's real per-firm
 * EmailBodyEncryptionService, purely so factory-created rows are
 * self-contained and don't require a live TenantEncryptionKey/firm
 * wiring just to exist as test fixtures. Any test asserting real
 * per-firm encryption/decryption behavior goes through
 * EmailOAuthTokenService::store()/decryptForInternalUse() directly,
 * not this factory.
 */
class EmailOAuthTokenFactory extends Factory
{
    protected $model = EmailOAuthToken::class;

    public function definition(): array
    {
        return [
            'email_account_id' => EmailAccount::factory(),
            'token_type' => EmailOAuthTokenType::AccessToken->value,
            'encrypted_token_ciphertext' => Crypt::encryptString('fixture-oauth-token-'.$this->faker->uuid()),
            'encryption_key_id' => fn (array $attributes) => TenantEncryptionKey::factory()->create([
                'firm_id' => EmailAccount::query()->find($attributes['email_account_id'])->firm_id,
                'status' => TenantEncryptionKeyStatus::Active->value,
            ])->id,
            'status' => EmailOAuthTokenStatus::Active->value,
            'expires_at' => now()->addHour(),
        ];
    }

    public function forAccount(EmailAccount $account): static
    {
        return $this->state(fn () => [
            'email_account_id' => $account->id,
            'encryption_key_id' => TenantEncryptionKey::factory()->create([
                'firm_id' => $account->firm_id,
                'status' => TenantEncryptionKeyStatus::Active->value,
            ])->id,
        ]);
    }
}
