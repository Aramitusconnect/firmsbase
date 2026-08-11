<?php

namespace App\Services\Security;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * SessionRevocationService — Mission 1B (Extreme Security Hardening),
 * sections 11 & 52: "Provide canonical ability to revoke sessions
 * after password reset, MFA removal, credential compromise, role
 * removal, Firm-user termination, Platform Admin security event" and
 * the "revoke Firm/Admin sessions" kill switch.
 *
 * The stock `sessions` table (database session driver — see
 * config/session.php) has no guard column, and this application has
 * three distinct guards backed by three distinct identity models
 * (`User`/`ClientPortalUser`/`PlatformAdmin`) whose primary keys can
 * legitimately collide numerically — a raw `user_id` match alone is
 * NOT guard-safe (the exact same class of bug
 * EstablishPanelAuthGuardDefault fixed for AuthenticateSession earlier
 * in this mission). This service instead decodes each session row's
 * payload and matches on the guard-scoped auth key Laravel itself
 * writes — `Illuminate\Auth\SessionGuard::getName()`, public API,
 * literally `'login_'.$guardName.'_'.sha1(SessionGuard::class)` —
 * exactly the key Laravel's own SessionGuard reads/writes, so this
 * reads the real stored identity rather than guessing a format.
 * config('session.serialization') is 'json' in this application, so
 * the payload is base64(json_encode(...)), not PHP's native
 * serialize() format.
 *
 * Only meaningful for the database session driver — a no-op (returns
 * 0) for any other driver, since there is no queryable session store
 * to revoke rows from.
 */
class SessionRevocationService
{
    /**
     * @return int number of session rows revoked
     */
    public function revokeAllSessionsFor(Authenticatable $user, string $guard, ?string $exceptSessionId = null): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        $authKey = Auth::guard($guard)->getName();
        $identifier = $user->getAuthIdentifier();
        $table = config('session.table', 'sessions');
        $revoked = 0;

        $query = DB::table($table)->orderBy('id');

        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }

        $query->chunkById(200, function ($rows) use ($table, $authKey, $identifier, &$revoked): void {
            $matchingIds = [];

            foreach ($rows as $row) {
                $payload = $this->decodePayload($row->payload);

                if (array_key_exists($authKey, $payload) && (string) $payload[$authKey] === (string) $identifier) {
                    $matchingIds[] = $row->id;
                }
            }

            if ($matchingIds !== []) {
                $revoked += DB::table($table)->whereIn('id', $matchingIds)->delete();
            }
        });

        return $revoked;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $payload): array
    {
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            return [];
        }

        $data = json_decode($decoded, true);

        return is_array($data) ? $data : [];
    }
}
