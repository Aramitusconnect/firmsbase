<?php

namespace App\Providers;

use App\Enums\FirmUserStatus;
use App\Http\Middleware\EstablishFirmTenantContextForLivewireUpdate;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\Stripe\PaymentGatewaySimulationPolicyService;
use App\Services\Stripe\StripeGateway;
use App\Services\Stripe\UnavailablePaymentGateway;
use App\Services\TenantContextService;
use App\Services\VirusScan\FakeVirusScanner;
use App\Services\VirusScan\VirusScanner;
use Aws\Sqs\SqsClient;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SES event consumer (feature/ses-event-consumer). A container
        // binding (not a bare `new SqsClient(...)` inside the command)
        // so tests can swap in a mock via $this->app->instance() without
        // ever touching a live SQS queue. 'key'/'secret' both default to
        // null — the AWS SDK's default credential provider chain then
        // falls back to the ECS task role's own temporary credentials,
        // identical in intent to how Illuminate\Mail\Transport\SesTransport
        // is already configured. Never introduces a static access key.
        $this->app->singleton(SqsClient::class, function () {
            $config = config('services.ses_events');

            // Bounds the worst-case time ConsumeSesEventsCommand's
            // graceful-shutdown handling can be blocked inside a single
            // receiveMessage() call — confirmed necessary by a real
            // container-level smoke test (feature/ses-consumer-ecs-
            // wiring): pcntl's async signal dispatch cannot interrupt a
            // C-library network call already in flight (e.g. a hung
            // TCP connect during a network partition), so without an
            // explicit ceiling here, SIGTERM could be delayed well past
            // ECS's stopTimeout, forcing a SIGKILL instead of a clean
            // exit. 'connect_timeout' bounds the TCP handshake phase
            // alone (5s is generous for a reachable AWS endpoint, fast-
            // fails on an unreachable one); 'timeout' bounds the whole
            // request including the long-poll wait itself, so it MUST
            // always exceed the configured WaitTimeSeconds — it is
            // deliberately derived from the SAME normalized wait-time
            // value the receiveMessage() call itself uses (+10s margin
            // for TLS/transfer), never a flat constant, so a legitimate
            // near-20s long poll is never cut short.
            $waitTimeSeconds = $this->normalizeSesEventsWaitTimeSeconds($config['wait_time_seconds'] ?? null);

            $clientConfig = [
                'version' => 'latest',
                'region' => $config['region'],
                'http' => [
                    'connect_timeout' => 5,
                    'timeout' => $waitTimeSeconds + 10,
                ],
            ];

            // Matches Illuminate\Mail\MailManager::addSesCredentials()'s
            // own established pattern exactly: the 'credentials' key is
            // only ever set when a static key/secret is actually
            // configured. Left unset (never set to an explicit null),
            // the AWS SDK falls back to its own default credential
            // provider chain, which resolves the ECS task role's
            // temporary credentials automatically.
            if (! empty($config['key']) && ! empty($config['secret'])) {
                $clientConfig['credentials'] = [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                ];
            }

            return new SqsClient($clientConfig);
        });

        // Payment Link / QR Routing phase — the StripeGateway interface
        // (app/Services/Stripe/StripeGateway.php) already existed, used
        // by PlatformPaymentService/PlatformRefundService, but was never
        // container-bound anywhere; every existing caller constructed a
        // FakeStripeGateway directly. PaymentRequestCheckoutService is
        // the first production code path that needs to resolve
        // StripeGateway from the container (a public HTTP request has
        // no place to hand-construct one), so this binding is the
        // minimal missing piece, not a new abstraction — reuses the
        // interface exactly as designed.
        //
        // Payment-Channel Safety Hardening pass, item 1 — this binding
        // is now a fail-closed factory, not an unconditional bind to
        // FakeStripeGateway. FakeStripeGateway must NEVER make staging
        // or production appear to have received real money:
        // PaymentGatewaySimulationPolicyService::isSimulationEnabled()
        // is the single source of truth (testing: always simulated;
        // local: only when explicitly opted in; everything else:
        // never). Outside simulation, this resolves to
        // UnavailablePaymentGateway, which throws
        // PaymentProviderUnavailableException on every call rather
        // than silently falling back to a fake success. A real
        // connector is an explicit, disclosed BLOCKED_PROVIDER_CONNECTION
        // limitation (see the final report), not something this
        // binding fakes into looking live.
        $this->app->bind(StripeGateway::class, function () {
            return $this->app->make(PaymentGatewaySimulationPolicyService::class)->isSimulationEnabled()
                ? new FakeStripeGateway
                : new UnavailablePaymentGateway;
        });

        // Mission 1B (Extreme Security Hardening) fix: VirusScanner had NO
        // container binding at all anywhere in this codebase — every real
        // dispatch of ScanDocumentJob (its handle() type-hints this
        // interface for automatic resolution, same mechanism as
        // controller-method injection) would throw "Target [VirusScanner]
        // is not instantiable" before ever reaching FakeVirusScanner. This
        // is the disclosed, self-tracked stub (ComplianceGapRegistryService
        // key `real_malware_scanning_engine_stubbed`) — the missing binding
        // was a wiring bug, not a decision to fake scanning; a real scanner
        // implementation remains a separate, later decision (see the final
        // report).
        $this->app->bind(VirusScanner::class, FakeVirusScanner::class);
    }

    /**
     * Validates the raw `services.ses_events.wait_time_seconds` config
     * value (itself only ever a blind `(int)` cast of an env var — see
     * config/services.php) before it drives the SqsClient's derived
     * HTTP 'timeout'. SQS's own ReceiveMessage API only accepts
     * WaitTimeSeconds in [0, 20] — a value outside that range would
     * either be rejected by SQS itself at call time, or (if somehow
     * negative after a bad cast) could derive a nonsensical/too-short
     * HTTP timeout. Rather than let a misconfigured env var (e.g. a
     * manual ECS console edit bypassing Terraform's own 0-20
     * validation — see infrastructure/ecs/environments/staging/
     * variables.tf) silently produce a degenerate timeout, any
     * out-of-range or non-numeric value falls back to the documented
     * safe default of 20 — never a fabricated smaller number, and
     * never left unbounded.
     */
    private function normalizeSesEventsWaitTimeSeconds(mixed $rawValue): int
    {
        $default = 20;

        if (is_int($rawValue) && $rawValue >= 0 && $rawValue <= 20) {
            return $rawValue;
        }

        if (is_string($rawValue) && preg_match('/\A(0|[1-9][0-9]?)\z/', $rawValue) === 1 && (int) $rawValue <= 20) {
            return (int) $rawValue;
        }

        return $default;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAuthenticationAuditLogging();
        $this->registerLivewireUpdateRoute();
        $this->registerFirmOwnerInvitationAcceptance();
    }

    /**
     * Platform Firm Provisioning workflow. A newly-provisioned firm
     * owner's FirmUser membership starts as FirmUserStatus::Invited —
     * User::canAccessPanel()/LoginPolicyService::canAttemptFirmLogin()
     * both require Active, so an Invited member can complete the
     * password-setup flow (a guest route, not gated by canAccessPanel())
     * but could not actually log in afterward without something
     * flipping that status. Laravel's own password broker fires this
     * standard `PasswordReset` event on every successful reset
     * (Illuminate\Auth\Passwords\PasswordBroker::reset(), which Filament's
     * built-in password-reset page uses unmodified) — reused here rather
     * than forking Filament's page or inventing a bespoke
     * "accept invitation" controller/route.
     *
     * Deliberately unconditional on "is this the first reset": an
     * ordinary later "forgot password" reset re-running this is a
     * harmless no-op (the where('status', Invited) query simply matches
     * nothing once the member is already Active).
     */
    private function registerFirmOwnerInvitationAcceptance(): void
    {
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            $user = $event->user;

            $context = new TenantContextService;

            $invitedMemberships = $context->withUserContext(
                $user->id,
                fn () => $user->firmUsers()->where('status', FirmUserStatus::Invited->value)->get(),
            );

            foreach ($invitedMemberships as $firmUser) {
                $context->runWithFirmContext($firmUser->firm_id, function () use ($firmUser): void {
                    $firmUser->update([
                        'status' => FirmUserStatus::Active,
                        'invitation_accepted_at' => now(),
                    ]);
                });
            }
        });
    }

    /**
     * CP13 P1 (p1-livewire-fix-frozen-design.md §5). Replace Livewire's
     * default `/livewire/update` route with an identical one that also
     * carries EstablishFirmTenantContextForLivewireUpdate, so firm-panel
     * Filament actions re-establish tenant context BEFORE Livewire's
     * `ModelSynth::hydrate()` re-fetches their FORCE-RLS-protected
     * `#[Locked]` record properties. This provider's boot() runs after
     * LivewireServiceProvider::boot(), and RouteCollection keys by
     * method+URI, so this later `POST /livewire/update` registration
     * overwrites the default one (and `findUpdateRoute()` additionally
     * prefers any `*livewire.update`-named route). URI and update-URI are
     * unchanged; the middleware itself no-ops for every non-firm-panel
     * (Admin/SuperAdmin) component, so those surfaces are unaffected.
     */
    private function registerLivewireUpdateRoute(): void
    {
        Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)
            ->middleware(['web', EstablishFirmTenantContextForLivewireUpdate::class])
            ->name('livewire.update'));
    }

    /**
     * Internal login/panel access wiring: records every successful and
     * failed login attempt, across both the `web` (User) and
     * `platform_admin` (PlatformAdmin) guards, into the existing
     * SecurityEvent audit log — no new audit system, no new table.
     * Fires from Laravel's own standard Login/Failed guard events
     * (dispatched by Filament's built-in Login page via Auth::attemptWhen()),
     * so this requires no custom login controller/route of its own.
     * Never logs raw credentials — only the attempted email, never the
     * password.
     */
    private function registerAuthenticationAuditLogging(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            // FirmsVault Admin Control Center MFA design proposal §5
            // (EnsurePlatformAdminMfaIsEnrolledAndVerified's step 5,
            // "reset-stamp check"): stamps the exact moment this
            // platform_admin session was authenticated, entirely
            // independent of the security_events write below (this must
            // still happen even if that write is ever skipped/fails) —
            // there is no other reliable "when did this session log in"
            // signal available to that middleware, since Laravel's own
            // SessionGuard does not track one. Deliberately session-only
            // (not persisted anywhere else): a value that vanishes with
            // the session is exactly the fail-closed behavior that
            // middleware step wants when it cannot find one.
            if ($event->guard === 'platform_admin' && request()->hasSession()) {
                request()->session()->put('platform_admin_mfa_session_authenticated_at', now()->toISOString());
            }

            // Fix #0 (Section 39A-3L Phase B6): activeFirmUser() correctly
            // bootstraps via app.current_user_id, unlike a raw firmUsers()
            // query, which returns NULL under firm_users' own FORCE RLS
            // regardless of whether a real active membership exists.
            $firmId = $event->user instanceof User
                ? $event->user->activeFirmUser()?->firm_id
                : null;

            $context = new TenantContextService;

            if ($firmId !== null) {
                $context->setDatabaseTenantContextForFirmId($firmId);
            } else {
                $context->clearDatabaseTenantContext();
            }

            try {
                SecurityEvent::create([
                    'firm_id' => $firmId,
                    'actor_type' => get_class($event->user),
                    'actor_id' => $event->user->getAuthIdentifier(),
                    'event_type' => 'login_succeeded',
                    'category' => 'authentication',
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'metadata' => ['guard' => $event->guard],
                ]);
            } finally {
                $context->clearDatabaseTenantContext();
            }
        });

        Event::listen(Failed::class, function (Failed $event): void {
            $context = new TenantContextService;
            $context->clearDatabaseTenantContext();

            try {
                SecurityEvent::create([
                    'firm_id' => null,
                    'actor_type' => $event->user !== null
                        ? get_class($event->user)
                        : ($event->guard === 'platform_admin' ? PlatformAdmin::class : User::class),
                    'actor_id' => $event->user?->getAuthIdentifier(),
                    'event_type' => 'login_failed',
                    'category' => 'authentication',
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'metadata' => [
                        'guard' => $event->guard,
                        'attempted_email' => $event->credentials['email'] ?? null,
                    ],
                ]);
            } finally {
                $context->clearDatabaseTenantContext();
            }
        });
    }
}
