<?php

namespace Database\Factories;

use App\Enums\EmailAccountConnectionStatus;
use App\Enums\EmailProvider;
use App\Enums\EmailStorageMode;
use App\Models\EmailAccount;
use App\Models\Firm;
use App\Models\FirmUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailAccount>
 */
class EmailAccountFactory extends Factory
{
    protected $model = EmailAccount::class;

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'provider' => EmailProvider::Gmail->value,
            'mailbox_address' => $this->faker->unique()->safeEmail(),
            'connection_status' => EmailAccountConnectionStatus::Connected->value,
            'storage_mode' => EmailStorageMode::Disabled->value,
            // Resolved lazily so the created FirmUser belongs to the SAME
            // firm as this account — firm_id above is already a real,
            // persisted id by the time this closure runs.
            'connected_by_firm_user_id' => fn (array $attributes) => FirmUser::factory()
                ->create(['firm_id' => $attributes['firm_id']])
                ->id,
        ];
    }

    /**
     * Overrides BOTH firm_id and connected_by_firm_user_id together
     * (rather than firm_id alone) so a caller can never end up with a
     * fixture where the account's firm_id and its connecting FirmUser's
     * firm_id disagree — state application order relative to
     * definition()'s own lazy closures is not something this factory
     * relies on for correctness.
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'connected_by_firm_user_id' => FirmUser::factory()->create(['firm_id' => $firm->id])->id,
        ]);
    }

    public function withStorageMode(EmailStorageMode $mode): static
    {
        return $this->state(fn () => ['storage_mode' => $mode->value]);
    }
}
