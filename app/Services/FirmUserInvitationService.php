<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Exceptions\FirmSeatLimitExceededException;
use App\Exceptions\LastFirmOwnerRemovalException;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Notifications\FirmOwnerInvitationNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * FirmUserInvitationService — Firm Feature Manifest §12 ("Firm Team /
 * Access"): the firm-facing analogue of `FirmProvisioningService`'s
 * owner-invitation path, generalized to invite ANY of the 6
 * `FirmUserRole` values (never a platform-admin concept — `FirmUserRole`
 * structurally has no such case, see that enum's own docblock), and the
 * sole place `FirmUser` status transitions (suspend/reactivate/remove)
 * happen outside first-time provisioning.
 *
 * REUSE, NOT DUPLICATION:
 *  - The invited `User` row is created the identical way
 *    `FirmProvisioningService::provisionLocalRecords()` creates a new
 *    owner: an unusable, never-returned, never-logged random password
 *    (`Str::random(64)`) — the invitee sets their real password
 *    exclusively through the same password-reset/invitation-acceptance
 *    flow already fixed for owners (`App\Filament\Firm\Pages\Auth\
 *    ResetPassword`).
 *  - The invitation email reuses `FirmOwnerInvitationNotification`
 *    AS-IS — confirmed by direct source read that nothing in that class
 *    (subject/body inherited unchanged from Laravel's own `ResetPassword`
 *    notification; its own overrides are only `resetUrl()`, which builds
 *    a firm-panel-scoped signed URL, and an optional SES correlation
 *    tag) references "owner" or any role-specific content at all. Its
 *    own docblock already documents it is used for "both the brand-new
 *    owner's first-time password SETUP and any later ordinary 'forgot
 *    password' reset" — this service extends that same "any invited
 *    FirmUser, any role" generalization one step further, rather than
 *    duplicating an identical notification class under a new name. The
 *    class's OWN NAME still says "Owner" (a pre-existing, cosmetic
 *    mismatch, out of this feature's scope to rename — renaming it would
 *    touch `FirmProvisioningService`, which this task is explicitly not
 *    authorized to modify).
 *  - `App\Filament\Firm\Pages\Auth\ResetPassword::hasPendingFirmOwnerInvitation()`
 *    (confirmed by direct source read) queries
 *    `$user->firmUsers()->where('status', FirmUserStatus::Invited->value)`
 *    with NO role filter at all — it already accepts a pending
 *    invitation for any of the 6 roles, not only FirmOwner. No change
 *    was needed there.
 *  - `dispatchInvitation()` below mirrors
 *    `FirmProvisioningService::dispatchOwnerInvitation()`'s exact
 *    mechanism (the password broker's `$callback` extension point +
 *    `CorrelatedPasswordResetSenderService::sendForFirm()`) rather than
 *    calling `$user->sendPasswordResetNotification()` — same reasoning:
 *    firm-owned email always requires an exact firm correlation, and
 *    this authenticated, firm-owner-facing call site is free to inspect
 *    the real send outcome (unlike the public "forgot password" flow).
 *
 * SEAT ENFORCEMENT — Firm Feature Manifest §12 point (4): `invite()`
 * checks `SeatEnforcementService::canInvite()` for the invited role's
 * `effectiveSeatClass()` BEFORE creating any row, and fails cleanly with
 * `FirmSeatLimitExceededException` if no seat remains — seat limits are
 * never silently ignored. KNOWN, PRE-EXISTING GAP (confirmed by direct
 * source read, not introduced by this service): no production caller in
 * this codebase creates a `seat_allocations` row for a firm today —
 * `FirmProvisioningService::provisionLocalRecords()` creates the firm's
 * OWN owner `FirmUser` without ever calling `SeatAllocationService`. A
 * freshly-provisioned firm therefore has ZERO allocated seats in every
 * `SeatClass`, so `canInvite()` will legitimately return `false` for
 * every firm until a (separately tracked, not-yet-built) seat
 * provisioning step calls `SeatAllocationService::allocateDirect()`/
 * `allocateFromPool()` for it. This is flagged here, not silently
 * routed around — see this feature's own final report.
 *
 * LAST-OWNER GUARD — `suspend()`/`remove()` both call
 * `assertNotLastActiveOwner()` BEFORE writing, a hard service-level
 * guard (never merely a UI-level disable) per Firm Feature Manifest
 * §12's explicit "never allow removing/suspending the last remaining
 * Active FirmOwner of a firm" requirement.
 *
 * TENANT CONTEXT — every write below runs inside its own
 * `TenantContextService::runWithFirmContext()` wrap (firm_users is
 * FORCE-RLS + BelongsToTenant); every mutation re-fetches the target row
 * fresh by primary key inside that wrap first (TOCTOU discipline,
 * matching `RevokeConsentAction`'s established pattern in this
 * codebase). The invitation email itself is dispatched strictly AFTER
 * that transaction commits — matching `FirmProvisioningService`'s own
 * "no network/mail work happens while any row lock is held" discipline.
 */
class FirmUserInvitationService
{
    public function __construct(
        private readonly SeatEnforcementService $seatEnforcement,
        private readonly CorrelatedPasswordResetSenderService $correlatedSender,
    ) {}

    /**
     * @throws FirmSeatLimitExceededException if the firm has no
     *                                        remaining seat for the invited role's seat class.
     * @throws RuntimeException if the email already belongs to a member of this firm.
     */
    public function invite(Firm $firm, string $email, string $name, FirmUserRole $role, User $invitedBy): FirmUser
    {
        // effectiveSeatClass() is the single authoritative place this
        // default-derivation table is implemented (see FirmUser's own
        // docblock) — an unsaved, transient FirmUser instance is enough
        // to compute it, since it only reads $this->role/$this->seat_class.
        $seatClass = (new FirmUser(['role' => $role]))->effectiveSeatClass();

        $firmUser = (new TenantContextService)->runWithFirmContext(
            $firm,
            function () use ($firm, $email, $name, $role, $invitedBy, $seatClass): FirmUser {
                if (! $this->seatEnforcement->canInvite($firm, $seatClass)) {
                    throw new FirmSeatLimitExceededException($seatClass);
                }

                $existingUser = User::query()->where('email', $email)->first();

                if ($existingUser !== null && $firm->firmUsers()->where('user_id', $existingUser->id)->exists()) {
                    throw new RuntimeException('This person is already a member of this firm.');
                }

                $user = $existingUser ?? User::create([
                    'name' => $name,
                    'email' => $email,
                    // Unusable, never-returned, never-logged placeholder —
                    // matches FirmProvisioningService's identical owner-
                    // creation pattern exactly.
                    'password' => Str::random(64),
                ]);

                return FirmUser::create([
                    'user_id' => $user->id,
                    'firm_id' => $firm->id,
                    'role' => $role,
                    'status' => FirmUserStatus::Invited,
                    'is_primary' => false,
                    'invited_by' => $invitedBy->id,
                ]);
            },
        );

        $this->dispatchInvitation($firmUser->user, $firm);

        // Deliberately NOT ->fresh() here: that would re-query
        // firm_users AFTER runWithFirmContext() above has already
        // restored the ambient tenant context (or the request's own, or
        // none at all) — against a FORCE-RLS table, a fresh() call with
        // no matching context returns null. $firmUser, as returned by
        // FirmUser::create() inside the wrap above, is already a fully
        // populated, freshly-created model — no re-fetch is needed.
        return $firmUser;
    }

    /**
     * @throws LastFirmOwnerRemovalException if $firmUser is the firm's
     *                                       last remaining active owner.
     */
    public function suspend(FirmUser $firmUser): FirmUser
    {
        return (new TenantContextService)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($firmUser): FirmUser {
                $fresh = FirmUser::query()->where('id', $firmUser->id)->firstOrFail();

                $this->assertNotLastActiveOwner($fresh);

                $fresh->update(['status' => FirmUserStatus::Suspended]);

                return $fresh->fresh();
            },
        );
    }

    public function reactivate(FirmUser $firmUser): FirmUser
    {
        return (new TenantContextService)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($firmUser): FirmUser {
                $fresh = FirmUser::query()->where('id', $firmUser->id)->firstOrFail();

                $fresh->update(['status' => FirmUserStatus::Active]);

                return $fresh->fresh();
            },
        );
    }

    /**
     * @throws LastFirmOwnerRemovalException if $firmUser is the firm's
     *                                       last remaining active owner.
     */
    public function remove(FirmUser $firmUser): FirmUser
    {
        return (new TenantContextService)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($firmUser): FirmUser {
                $fresh = FirmUser::query()->where('id', $firmUser->id)->firstOrFail();

                $this->assertNotLastActiveOwner($fresh);

                $fresh->update(['status' => FirmUserStatus::Removed]);

                return $fresh->fresh();
            },
        );
    }

    /**
     * Only blocks when $firmUser is CURRENTLY the Active FirmOwner being
     * transitioned away from Active — a Suspended/Removed/Invited
     * FirmOwner row, or any non-FirmOwner role, is never blocked by this
     * guard (there is nothing left to protect in either case).
     */
    private function assertNotLastActiveOwner(FirmUser $firmUser): void
    {
        if ($firmUser->role !== FirmUserRole::FirmOwner) {
            return;
        }

        if ($firmUser->status !== FirmUserStatus::Active) {
            return;
        }

        $otherActiveOwnerExists = FirmUser::query()
            ->where('firm_id', $firmUser->firm_id)
            ->where('role', FirmUserRole::FirmOwner->value)
            ->where('status', FirmUserStatus::Active->value)
            ->where('id', '!=', $firmUser->id)
            ->exists();

        if (! $otherActiveOwnerExists) {
            throw new LastFirmOwnerRemovalException;
        }
    }

    /**
     * Mirrors FirmProvisioningService::dispatchOwnerInvitation() exactly
     * (see this class's own docblock for why) — generalized only in that
     * it is not owner-specific. Never throws; every failure mode is
     * logged and reported back as a safe, non-secret category string so
     * the calling Action can tell the acting FirmOwner the membership
     * row was created but the email failed to send, without leaking any
     * transport/token detail.
     *
     * @return string|null the safe failure category, or null on success
     */
    private function dispatchInvitation(User $invitee, Firm $firm): ?string
    {
        try {
            $status = Password::broker('users')->sendResetLink(
                ['email' => $invitee->email],
                function (User $user, string $token) use ($firm): ?string {
                    $result = $this->correlatedSender->sendForFirm(
                        $user,
                        $firm,
                        ConsentChannel::Email,
                        $user->email,
                        fn (string $correlationId) => (new FirmOwnerInvitationNotification($token))->withCorrelationId($correlationId),
                    );

                    return $result->wasSent() ? Password::RESET_LINK_SENT : 'firm_user_invitation_correlation_failed';
                },
            );

            if ($status === Password::RESET_LINK_SENT) {
                return null;
            }

            $category = 'password_broker_status_'.Str::after($status, 'passwords.');

            Log::warning('firm_user_invitation_not_sent', [
                'firm_id' => $firm->id,
                'password_broker_status' => $status,
            ]);

            return $category;
        } catch (Throwable $e) {
            $category = match (true) {
                str_contains($e->getMessage(), 'AccessDenied') => 'mail_transport_access_denied',
                $e instanceof TransportExceptionInterface => 'mail_transport_error',
                default => 'unexpected_error',
            };

            Log::error('firm_user_invitation_failed', [
                'firm_id' => $firm->id,
                'exception_class' => get_class($e),
                'failure_category' => $category,
            ]);

            return $category;
        }
    }
}
