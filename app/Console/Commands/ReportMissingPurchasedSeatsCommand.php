<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Services\TenantContextService;
use Illuminate\Console\Command;

/**
 * `firms:report-missing-purchased-seats` — Firm Feature Manifest §12
 * flat per-firm seat model backfill for PRE-EXISTING commercial firms
 * (firms provisioned before `purchased_seats` existed, or before a plan
 * was assigned through the now-validated provisioning path).
 *
 * CRITICAL RULE, enforced throughout this command: it never invents a
 * seat quantity for any firm, in either mode.
 *
 *   - Default (report/dry-run) mode: lists every `FirmLicense` with a
 *     non-null `plan_id` AND a null `purchased_seats`, alongside the
 *     firm's current Active+Invited+Suspended `FirmUser` count (the
 *     same "used seats" definition `FirmSeatCapacityService` uses) —
 *     purely informational, mutates nothing.
 *   - `--apply` mode requires the operator to explicitly supply BOTH
 *     `--firm=<id>` and `--seats=<n>` for exactly ONE firm at a time —
 *     there is no bulk/auto-derived default anywhere in this path.
 *     Idempotent: setting the same value twice is a no-op success.
 *     Refuses to silently overwrite an already-set, DIFFERENT value
 *     unless `--force` is also passed.
 *
 * CROSS-FIRM READ ARCHITECTURE (report mode only): `firm_licenses` and
 * `firm_users` both carry permanent FORCE ROW LEVEL SECURITY, and this
 * application's runtime database role is deliberately never granted
 * BYPASSRLS/superuser (confirmed by this repo's own DatabaseRoleProofTest
 * and every *ForceRlsActivationTest). Reading across every firm at once
 * therefore uses the SAME already-security-approved pattern
 * `PlatformFirmUserDirectoryService::listAll()` documents and uses: a
 * per-firm loop, each iteration wrapped in its own
 * `TenantContextService::runWithFirmContext()`, merged in PHP — an
 * O(number of firms) scan, not O(1). Same documented trade-off, same
 * reasoning: acceptable at this platform's current scale, would need a
 * precomputed summary table if the firm population grows large enough
 * to make a full scan slow (out of this command's scope).
 */
class ReportMissingPurchasedSeatsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'firms:report-missing-purchased-seats
        {--apply : Apply mode — set purchased_seats for exactly one firm instead of reporting}
        {--firm= : Firm id to set purchased_seats for (apply mode only)}
        {--seats= : Purchased seat quantity to set for --firm, a positive integer (apply mode only)}
        {--force : Required to overwrite an already-set, DIFFERENT purchased_seats value}';

    protected $description = 'Report commercial firms missing a purchased-seat quantity (default/dry-run), or set an explicit operator-supplied value for one firm (--apply). Never invents a seat quantity.';

    private const SEAT_CONSUMING_STATUSES = [
        FirmUserStatus::Active->value,
        FirmUserStatus::Invited->value,
        FirmUserStatus::Suspended->value,
    ];

    public function handle(): int
    {
        return $this->option('apply') ? $this->apply() : $this->report();
    }

    private function report(): int
    {
        $rows = [];

        Firm::query()->orderBy('id')->chunkById(100, function ($firms) use (&$rows): void {
            foreach ($firms as $firm) {
                $found = (new TenantContextService)->runWithFirmContext($firm, function () use ($firm): ?array {
                    $license = FirmLicense::query()
                        ->where('firm_id', $firm->id)
                        ->whereNotNull('plan_id')
                        ->whereNull('purchased_seats')
                        ->orderByDesc('id')
                        ->first();

                    if ($license === null) {
                        return null;
                    }

                    $usedSeats = $firm->firmUsers()
                        ->whereIn('status', self::SEAT_CONSUMING_STATUSES)
                        ->count();

                    return [
                        'firm_id' => $firm->id,
                        'firm_name' => $firm->name,
                        'plan_id' => $license->plan_id,
                        'plan_name' => $license->plan?->name,
                        'used_seats' => $usedSeats,
                    ];
                });

                if ($found !== null) {
                    $rows[] = $found;
                }
            }
        });

        if ($rows === []) {
            $this->components->info('No commercial firm is missing a purchased-seat quantity.');

            return self::SUCCESS;
        }

        $this->components->warn(count($rows).' commercial firm(s) missing a purchased-seat quantity:');

        $this->table(
            ['Firm ID', 'Firm', 'Plan', 'Current Active+Invited+Suspended users'],
            collect($rows)->map(fn (array $r): array => [
                $r['firm_id'],
                $r['firm_name'],
                $r['plan_name'] ?? "#{$r['plan_id']}",
                $r['used_seats'],
            ])->all(),
        );

        $this->newLine();
        $this->components->info('This command never invents a seat quantity. Set one explicitly, one firm at a time: php artisan firms:report-missing-purchased-seats --apply --firm=<id> --seats=<n>');

        return self::SUCCESS;
    }

    private function apply(): int
    {
        $firmId = $this->option('firm');
        $seatsOption = $this->option('seats');

        if (! filled($firmId) || ! filled($seatsOption)) {
            $this->components->error('--apply requires both --firm=<id> and --seats=<n> (exactly one firm at a time).');

            return self::FAILURE;
        }

        if (! ctype_digit((string) $seatsOption) || (int) $seatsOption < 1) {
            $this->components->error('--seats must be a positive whole number.');

            return self::FAILURE;
        }

        $seats = (int) $seatsOption;
        $force = (bool) $this->option('force');

        $firm = Firm::query()->find((int) $firmId);

        if ($firm === null) {
            $this->components->error("No firm found with id [{$firmId}].");

            return self::FAILURE;
        }

        $outcome = (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $seats, $force): array {
            $license = FirmLicense::query()
                ->where('firm_id', $firm->id)
                ->orderByDesc('id')
                ->first();

            if ($license === null) {
                return ['status' => 'no_license'];
            }

            if ($license->purchased_seats === $seats) {
                // Idempotent re-run with the identical value — a no-op
                // success, never an error.
                return ['status' => 'noop'];
            }

            if ($license->purchased_seats !== null && ! $force) {
                return ['status' => 'conflict', 'current' => $license->purchased_seats];
            }

            $license->update(['purchased_seats' => $seats]);

            return ['status' => 'applied'];
        });

        switch ($outcome['status']) {
            case 'no_license':
                $this->components->error("Firm [{$firm->name}] (id {$firm->id}) has no FirmLicense row at all — nothing to set. Assign a plan to this firm first.");

                return self::FAILURE;

            case 'conflict':
                $this->components->error("Firm [{$firm->name}] (id {$firm->id}) already has purchased_seats = {$outcome['current']}, which differs from the requested {$seats}. Pass --force to overwrite an already-set value.");

                return self::FAILURE;

            case 'noop':
                $this->components->info("Firm [{$firm->name}] (id {$firm->id}) already has purchased_seats = {$seats}. Nothing to do.");

                return self::SUCCESS;

            default:
                $this->components->info("Firm [{$firm->name}] (id {$firm->id}) purchased_seats set to {$seats}.");

                return self::SUCCESS;
        }
    }
}
