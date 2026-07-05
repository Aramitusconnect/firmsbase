<?php

namespace Database\Factories;

use App\Enums\EmailVisibilityScope;
use App\Models\EmailAccount;
use App\Models\EmailVisibilityRule;
use App\Models\FirmUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailVisibilityRule>
 */
class EmailVisibilityRuleFactory extends Factory
{
    protected $model = EmailVisibilityRule::class;

    public function definition(): array
    {
        return [
            'email_account_id' => EmailAccount::factory(),
            'firm_id' => fn (array $attributes) => EmailAccount::query()->find($attributes['email_account_id'])->firm_id,
            'matter_id' => null,
            'visibility_scope' => EmailVisibilityScope::OwnerOnly->value,
            'created_by_firm_user_id' => fn (array $attributes) => FirmUser::factory()->create([
                'firm_id' => $attributes['firm_id'],
            ])->id,
        ];
    }

    public function forAccount(EmailAccount $account): static
    {
        return $this->state(fn () => [
            'email_account_id' => $account->id,
            'firm_id' => $account->firm_id,
            'created_by_firm_user_id' => FirmUser::factory()->create(['firm_id' => $account->firm_id])->id,
        ]);
    }
}
