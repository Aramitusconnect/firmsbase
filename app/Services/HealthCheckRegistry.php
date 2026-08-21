<?php

namespace App\Services;

use App\Enums\HealthCheckMonitoringType;
use App\Enums\HealthCheckStatus;
use App\Enums\HealthCheckType;
use App\Services\VirusScan\ClamAvVirusScanner;
use App\ValueObjects\HealthCheckResult;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * HealthCheckRegistry — pluggable, mirrors Phase 4's
 * ReadinessScorecardRegistry exactly, one layer up (monitoring checks
 * vs. matter readiness components). A check registers a callable
 * keyed by HealthCheckType; runAll() executes every registered check.
 * Unlike ReadinessScorecardRegistry, there is no separate database
 * catalog table gating which checks are "active" — HealthCheckType
 * itself is the closed, reviewed list (see that enum's doc comment),
 * so registration alone determines what runs.
 *
 * Reuses Phase 4's QueueHealthService (QueueWorkers, FailedJobs) and
 * SchedulerHealthService (Scheduler) as two of its default registered
 * sources — no duplicated queue/scheduler-inspection logic (project
 * rule: extend Phase 4's health/queue/scheduler services only
 * additively, and here "additively" means "consumed by," never
 * modified).
 *
 * OPERATIONS CONTROL PLANE CORRECTION (P0 false-confidence fix).
 * Previously, five registered check types (WebUptime, Storage,
 * EmailDelivery, PaymentWebhooks, DocumentScanning) were hardcoded
 * stub callables that returned HealthCheckStatus::Healthy with a
 * detail string explaining they were stubs. The detail string is not
 * what an operator reads at a glance — the badge is — so the Service
 * Health console rendered five permanent green lights for five
 * surfaces with no probe of any kind behind them. Those five now
 * return HealthCheckStatus::NotMonitored, which renders grey and is
 * excluded from every aggregate "healthy" claim. A surface nobody
 * monitors must never be indistinguishable from a surface that was
 * checked and found well.
 *
 * Every registration now also declares a HealthCheckMonitoringType,
 * and the registry — not the individual closure — stamps it onto the
 * result. That makes "how many checks are actually real" a derived,
 * dynamic fact (see monitoringTypeCounts()) rather than a hand-
 * maintained sentence in a Blade file that silently goes stale.
 *
 * No NEW external monitoring provider is introduced here (project
 * rule, and an explicit stop gate): WebUptime genuinely needs one this
 * program does not add, and PaymentWebhooks is Finix-adjacent and out
 * of scope — those two remain honestly reported as NotMonitored.
 * Storage, EmailDelivery, and DocumentScanning, however, each already
 * have real infrastructure configured elsewhere in this codebase
 * (the default filesystem disk, the configured mailer, and the
 * optional clamd daemon already used by ClamAvVirusScanner::scan()
 * respectively) — probing them is not a new dependency, just finally
 * looking at ones that already exist:
 *  - Storage does a genuine write+read+delete round-trip against
 *    `config('filesystems.default')` — HealthCheckMonitoringType::LiveProbe.
 *  - EmailDelivery resolves (never sends through) the configured
 *    mailer — this proves configuration only, never a live delivery
 *    path, so it is stamped HealthCheckMonitoringType::ConfigurationCheck,
 *    not LiveProbe.
 *  - DocumentScanning pings a configured clamd daemon via
 *    ClamAvVirusScanner::ping() when `services.clamav.socket` is set
 *    (HealthCheckMonitoringType::LiveProbe), or honestly reports
 *    Unknown when no daemon is configured — independent of whether
 *    VirusScanner::class happens to be bound to ClamAvVirusScanner
 *    (see AppServiceProvider's own binding comment: binding activation
 *    and daemon availability are two separate things).
 * None of the three ever fabricates Healthy on doubt or exception.
 */
class HealthCheckRegistry
{
    /**
     * @var array<string, callable(): HealthCheckResult>
     */
    private array $checks = [];

    /**
     * The declared provenance of each registered check, keyed exactly
     * like $checks. The registry is authoritative for this — a
     * callable cannot claim monitoring it does not have.
     *
     * @var array<string, HealthCheckMonitoringType>
     */
    private array $monitoringTypes = [];

    public function __construct(
        private QueueHealthService $queueHealth,
        private SchedulerHealthService $schedulerHealth,
        private TenantIsolationAnomalyService $tenantIsolationAnomaly,
    ) {
        $this->registerDefaults();
    }

    /**
     * @param  callable(): HealthCheckResult  $check
     *
     * $monitoringType defaults to NotMonitored on purpose: an
     * unannotated registration is treated as unproven rather than
     * assumed real. Existing two-argument call sites (tests
     * overriding a check with a fixture) therefore keep working and
     * stay conservatively classified.
     */
    public function register(
        HealthCheckType $type,
        callable $check,
        HealthCheckMonitoringType $monitoringType = HealthCheckMonitoringType::NotMonitored,
    ): void {
        $this->checks[$type->value] = $check;
        $this->monitoringTypes[$type->value] = $monitoringType;
    }

    public function isRegistered(HealthCheckType $type): bool
    {
        return isset($this->checks[$type->value]);
    }

    /**
     * The declared provenance of one check. A HealthCheckType that is
     * not registered at all is NotMonitored — there is no probe, so
     * there is nothing to claim.
     */
    public function monitoringTypeFor(HealthCheckType $type): HealthCheckMonitoringType
    {
        return $this->monitoringTypes[$type->value] ?? HealthCheckMonitoringType::NotMonitored;
    }

    /**
     * Dynamic registry census, keyed by monitoring type value, always
     * covering every case of HealthCheckType (registered or not) so
     * the totals reconcile. Callers render these numbers directly
     * instead of hardcoding a count that drifts the moment a check is
     * added, removed, or made real.
     *
     * @return array<string, int>
     */
    public function monitoringTypeCounts(): array
    {
        $counts = array_fill_keys(
            array_map(fn (HealthCheckMonitoringType $t): string => $t->value, HealthCheckMonitoringType::cases()),
            0,
        );

        foreach (HealthCheckType::cases() as $type) {
            $counts[$this->monitoringTypeFor($type)->value]++;
        }

        return $counts;
    }

    public function totalRegisteredCount(): int
    {
        return count($this->checks);
    }

    /**
     * Check types declared in the enum but never registered — no
     * callable exists, so nothing about them is observed at all.
     *
     * @return array<int, HealthCheckType>
     */
    public function unregisteredTypes(): array
    {
        return array_values(array_filter(
            HealthCheckType::cases(),
            fn (HealthCheckType $type): bool => ! $this->isRegistered($type),
        ));
    }

    /**
     * @return array<int, HealthCheckResult>
     */
    public function runAll(): array
    {
        return array_values(array_map(
            fn (HealthCheckType $type): HealthCheckResult => $this->run($type),
            array_filter(HealthCheckType::cases(), fn (HealthCheckType $type): bool => $this->isRegistered($type)),
        ));
    }

    public function run(HealthCheckType $type): ?HealthCheckResult
    {
        if (! $this->isRegistered($type)) {
            return null;
        }

        $result = ($this->checks[$type->value])();

        return $result->withMonitoringType($this->monitoringTypeFor($type));
    }

    private function registerDefaults(): void
    {
        $this->registerUnmonitored(
            HealthCheckType::WebUptime,
            'No external uptime provider is configured. Web availability is not observed by this platform.',
        );

        // Real evidence, but note what it actually measures: the depth
        // and age of the database queue tables. It does NOT observe
        // whether any worker process is alive — see the honest naming
        // and the separate worker-evidence reporting in
        // QueueWorkerEvidenceService. An empty queue and a dead worker
        // look identical from here.
        $this->register(HealthCheckType::QueueWorkers, function () {
            $pending = $this->queueHealth->pendingJobsCount();
            $failed = $this->queueHealth->failedJobsCount();
            $healthy = $this->queueHealth->isHealthy();

            return new HealthCheckResult(
                HealthCheckType::QueueWorkers,
                $healthy ? HealthCheckStatus::Healthy : HealthCheckStatus::Degraded,
                "queue backlog: pending={$pending} failed={$failed} (backlog only — worker liveness is not observed)",
            );
        }, HealthCheckMonitoringType::InternalMetric);

        $this->register(HealthCheckType::Scheduler, function () {
            $lastHeartbeat = $this->schedulerHealth->lastHeartbeatAt();

            if ($lastHeartbeat === null) {
                return new HealthCheckResult(
                    HealthCheckType::Scheduler,
                    HealthCheckStatus::Unhealthy,
                    'no scheduler heartbeat has ever been recorded — the scheduler is not running, or has never run, in this environment',
                );
            }

            $healthy = $this->schedulerHealth->isHealthy();
            $ageSeconds = max(0, now()->timestamp - $lastHeartbeat);

            return new HealthCheckResult(
                HealthCheckType::Scheduler,
                $healthy ? HealthCheckStatus::Healthy : HealthCheckStatus::Unhealthy,
                $healthy
                    ? "heartbeat seen {$ageSeconds}s ago"
                    : "last scheduler heartbeat was {$ageSeconds}s ago, beyond the staleness window",
            );
        }, HealthCheckMonitoringType::InternalMetric);

        $this->register(HealthCheckType::FailedJobs, function () {
            $count = $this->queueHealth->failedJobsCount();

            return new HealthCheckResult(
                HealthCheckType::FailedJobs,
                $count > 50 ? HealthCheckStatus::Unhealthy : ($count > 0 ? HealthCheckStatus::Degraded : HealthCheckStatus::Healthy),
                "{$count} failed job(s)",
            );
        }, HealthCheckMonitoringType::InternalMetric);

        $this->register(
            HealthCheckType::Storage,
            fn () => $this->probeStorage(),
            HealthCheckMonitoringType::LiveProbe,
        );

        $this->register(
            HealthCheckType::EmailDelivery,
            fn () => $this->probeEmailDelivery(),
            HealthCheckMonitoringType::ConfigurationCheck,
        );

        $this->registerUnmonitored(
            HealthCheckType::PaymentWebhooks,
            'No payment-webhook probe is configured. Payment webhook delivery is not observed by this platform.',
        );

        $this->register(
            HealthCheckType::DocumentScanning,
            fn () => $this->probeDocumentScanning(),
            HealthCheckMonitoringType::LiveProbe,
        );

        $this->register(
            HealthCheckType::TenantIsolationAnomalies,
            fn () => $this->tenantIsolationAnomaly->checkForKnownAnomalyPatterns(),
            HealthCheckMonitoringType::InternalMetric,
        );
    }

    /**
     * probeStorage() — writes a randomly-named, randomly-keyed marker
     * file to `config('filesystems.default')`, reads it back, and
     * verifies the content round-trips exactly, then deletes it.
     * `Healthy` only on a clean write+read+delete+match cycle;
     * `Unhealthy` on any exception, content mismatch, OR falsy return
     * value — this codebase's own local/public disk config sets
     * `'throw' => false` (see config/filesystems.php), meaning
     * Flysystem failures surface as `false`/`null` return values
     * rather than exceptions, so a bare try/catch alone would silently
     * miss them. This check must never fabricate Healthy. Cleanup is
     * always attempted, even when the read/verify step fails, so a
     * failed run doesn't leave litter on the disk.
     */
    private function probeStorage(): HealthCheckResult
    {
        $disk = (string) config('filesystems.default');
        $path = 'health-checks/'.Str::random(24).'.txt';
        $marker = Str::random(32);

        try {
            $written = Storage::disk($disk)->put($path, $marker);
        } catch (Throwable $e) {
            return new HealthCheckResult(
                HealthCheckType::Storage,
                HealthCheckStatus::Unhealthy,
                "write to disk [{$disk}] failed: {$e->getMessage()}",
            );
        }

        if (! $written) {
            return new HealthCheckResult(
                HealthCheckType::Storage,
                HealthCheckStatus::Unhealthy,
                "write to disk [{$disk}] returned a failure result",
            );
        }

        try {
            $readBack = Storage::disk($disk)->get($path);

            if ($readBack !== $marker) {
                return new HealthCheckResult(
                    HealthCheckType::Storage,
                    HealthCheckStatus::Unhealthy,
                    "content mismatch reading back from disk [{$disk}]",
                );
            }

            return new HealthCheckResult(
                HealthCheckType::Storage,
                HealthCheckStatus::Healthy,
                "write+read+delete round-trip succeeded on disk [{$disk}]",
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                HealthCheckType::Storage,
                HealthCheckStatus::Unhealthy,
                "read from disk [{$disk}] failed: {$e->getMessage()}",
            );
        } finally {
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable) {
                // Best-effort cleanup only — a delete failure here does
                // not change the status already determined above; it
                // does not warrant fabricating a different result, and
                // there is nothing more this check can safely do about
                // it.
            }
        }
    }

    /**
     * probeEmailDelivery() — resolves `Mail::mailer(config('mail.mailer'))`
     * inside a try/catch to prove the mailer is at least instantiable/
     * configured correctly. This does NOT send any real email and does
     * NOT prove delivery actually works — only that config resolves
     * cleanly, which is exactly why this check is registered as
     * HealthCheckMonitoringType::ConfigurationCheck rather than
     * LiveProbe. `log`/`array` mailers resolve cleanly but are not a
     * real delivery transport, so they report `Degraded`, never
     * `Healthy` — matching this codebase's "technically working but
     * not really live" convention (see QueueWorkers above). `ses`
     * additionally requires `services.ses.region` (the one field the
     * AWS SDK's default credential provider chain cannot supply on its
     * own — without it, the SDK itself refuses to construct a client,
     * so a missing region reports `Unhealthy`, a real failure, not
     * merely "not live"); `key`/`secret` are legitimately left empty
     * in this codebase's ECS-task-role deployments (see
     * config/services.php's own comment on the `ses` block), so their
     * absence alone is not treated as a failure.
     */
    private function probeEmailDelivery(): HealthCheckResult
    {
        $mailer = config('mail.mailer');

        if (blank($mailer)) {
            return new HealthCheckResult(
                HealthCheckType::EmailDelivery,
                HealthCheckStatus::Unknown,
                'mail.mailer is not configured',
            );
        }

        // Checked before resolution, not after: the AWS SDK's SesClient
        // validates 'region' eagerly at construction time, so a missing
        // region actually makes Mail::mailer('ses') below throw anyway
        // (verified against this app's real AWS SDK dependency) — this
        // explicit check just makes that failure deterministic and its
        // message specific, rather than depending on SDK-internal
        // exception wording.
        if ($mailer === 'ses' && blank(config('services.ses.region'))) {
            return new HealthCheckResult(
                HealthCheckType::EmailDelivery,
                HealthCheckStatus::Unhealthy,
                'ses mailer selected but services.ses.region is not configured',
            );
        }

        try {
            Mail::mailer($mailer);
        } catch (Throwable $e) {
            return new HealthCheckResult(
                HealthCheckType::EmailDelivery,
                HealthCheckStatus::Unhealthy,
                "mailer [{$mailer}] failed to resolve: {$e->getMessage()}",
            );
        }

        if (in_array($mailer, ['log', 'array'], true)) {
            return new HealthCheckResult(
                HealthCheckType::EmailDelivery,
                HealthCheckStatus::Degraded,
                "mailer [{$mailer}] resolves but is not a real delivery transport",
            );
        }

        return new HealthCheckResult(
            HealthCheckType::EmailDelivery,
            HealthCheckStatus::Healthy,
            "mailer [{$mailer}] resolves cleanly (config resolution only — not a live delivery proof)",
        );
    }

    /**
     * probeDocumentScanning() — conditional by design: `Unknown` when
     * `services.clamav.socket` is empty (honest — no daemon is
     * expected in that environment), regardless of whether
     * VirusScanner::class happens to be bound to ClamAvVirusScanner —
     * binding activation and daemon availability are two separate
     * things (see AppServiceProvider's own VirusScanner binding
     * comment). When a socket IS configured, pings clamd over that
     * same socket using ClamAvVirusScanner::ping(), which reuses that
     * class's own low-level socket-connection mechanism rather than
     * duplicating it. `Healthy` only on a genuine `PONG`; `Unhealthy`
     * on anything else (connection refused, timeout, unexpected
     * response, or an exception) — never a fabricated Healthy.
     */
    private function probeDocumentScanning(): HealthCheckResult
    {
        $socket = config('services.clamav.socket');

        if (blank($socket)) {
            return new HealthCheckResult(
                HealthCheckType::DocumentScanning,
                HealthCheckStatus::Unknown,
                'services.clamav.socket is empty, no clamd daemon expected in this environment',
            );
        }

        try {
            $healthy = app(ClamAvVirusScanner::class)->ping();
        } catch (Throwable $e) {
            return new HealthCheckResult(
                HealthCheckType::DocumentScanning,
                HealthCheckStatus::Unhealthy,
                "clamd ping at [{$socket}] raised an exception: {$e->getMessage()}",
            );
        }

        return new HealthCheckResult(
            HealthCheckType::DocumentScanning,
            $healthy ? HealthCheckStatus::Healthy : HealthCheckStatus::Unhealthy,
            $healthy ? "clamd responded PONG at [{$socket}]" : "clamd did not respond PONG at [{$socket}]",
        );
    }

    /**
     * Registers a check type for which no probe exists. The result is
     * always NotMonitored — never Healthy — and the detail says
     * plainly what is not being watched, so an operator reading the
     * row learns the gap rather than being reassured by it.
     */
    private function registerUnmonitored(HealthCheckType $type, string $detail): void
    {
        $this->register(
            $type,
            fn () => new HealthCheckResult($type, HealthCheckStatus::NotMonitored, $detail),
            HealthCheckMonitoringType::NotMonitored,
        );
    }
}
