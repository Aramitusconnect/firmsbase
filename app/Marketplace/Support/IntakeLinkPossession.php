<?php

declare(strict_types=1);

namespace App\Marketplace\Support;

/**
 * Records that THIS browser session actually opened a given intake's signed
 * link, so a later same-session action can require it.
 *
 * The resumable link is the credential for a marketplace intake: it is signed,
 * and loading it is what proves a visitor holds it. Follow-up actions on that
 * intake are deliberately not signed themselves — a POST carrying a signature
 * in its URL leaks it into logs and referrers — so they need some other way to
 * ask "did this session ever hold the link?". That is exactly what this stores.
 *
 * Session-scoped on purpose. It never survives a new browser session, and it
 * says nothing about identity: it is possession of a link, nothing more.
 */
final class IntakeLinkPossession
{
    private const KEY = 'marketplace_intake_link_possession';

    public static function remember(string $uuid): void
    {
        $held = session()->get(self::KEY, []);

        if (! is_array($held)) {
            $held = [];
        }

        if (! in_array($uuid, $held, true)) {
            $held[] = $uuid;
            session()->put(self::KEY, $held);
        }
    }

    public static function holds(string $uuid): bool
    {
        $held = session()->get(self::KEY, []);

        return is_array($held) && in_array($uuid, $held, true);
    }
}
