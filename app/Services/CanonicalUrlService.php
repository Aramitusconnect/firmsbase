<?php

namespace App\Services;

/**
 * CanonicalUrlService — Mission 1 (canonical reconstruction), Domain &
 * Security Boundary Architecture. The single canonical-URL
 * configuration authority: every place in the application that needs
 * the base URL or bare hostname of one of FirmsVault's six application
 * surfaces (marketing, Firm app, Client Portal, SuperAdmin,
 * MyAttorney-reserved, API-reserved) reads it from here, which itself
 * reads only config/hosts.php — never from the current HTTP request's
 * Host header (a background queue worker has no trustworthy request
 * context) and never hardcoded inline.
 *
 * Consumers: Filament panel domain binding, the TrustHosts allow-list,
 * bootstrap/app.php's host-based guest-redirect dispatch, legacy-path
 * redirect targets, and any future canonical link generation.
 */
class CanonicalUrlService
{
    public function marketingUrl(): string
    {
        return rtrim((string) config('hosts.marketing_url'), '/');
    }

    public function firmAppUrl(): string
    {
        return rtrim((string) config('hosts.firm_app_url'), '/');
    }

    public function clientPortalUrl(): string
    {
        return rtrim((string) config('hosts.client_portal_url'), '/');
    }

    public function adminUrl(): string
    {
        return rtrim((string) config('hosts.admin_url'), '/');
    }

    public function myAttorneyUrl(): string
    {
        return rtrim((string) config('hosts.myattorney_url'), '/');
    }

    /**
     * The canonical public URL for a single Directory Firm profile
     * page — the one place this composition (`{myAttorneyUrl}/firms/
     * {slug}`) lives, so it never drifts from the real route defined
     * in routes/web.php.
     */
    public function myAttorneyFirmUrl(string $slug): string
    {
        return $this->myAttorneyUrl().'/firms/'.$slug;
    }

    /**
     * The canonical public URL for a single Directory Attorney profile
     * page.
     */
    public function myAttorneyAttorneyUrl(string $slug): string
    {
        return $this->myAttorneyUrl().'/attorneys/'.$slug;
    }

    /**
     * Mission 2 (MyAttorney Marketplace Core), checkpoint 12. See
     * config/hosts.php's own "MyAttorney public search-engine
     * indexing" block for the full section-95 rationale — defaults
     * false everywhere until an owner deliberately flips it.
     */
    public function myAttorneyIndexingEnabled(): bool
    {
        return (bool) config('hosts.myattorney_indexing_enabled');
    }

    public function apiUrl(): string
    {
        return rtrim((string) config('hosts.api_url'), '/');
    }

    public function marketingHost(): string
    {
        return $this->hostOf($this->marketingUrl());
    }

    public function firmAppHost(): string
    {
        return $this->hostOf($this->firmAppUrl());
    }

    public function clientPortalHost(): string
    {
        return $this->hostOf($this->clientPortalUrl());
    }

    public function adminHost(): string
    {
        return $this->hostOf($this->adminUrl());
    }

    public function myAttorneyHost(): string
    {
        return $this->hostOf($this->myAttorneyUrl());
    }

    public function apiHost(): string
    {
        return $this->hostOf($this->apiUrl());
    }

    /**
     * Every bare hostname FirmsVault is willing to serve a response
     * for — the TrustHosts allow-list. An incoming Host header that
     * matches none of these is rejected before reaching any route, so
     * a forged/poisoned Host header can never influence a reset link,
     * an OAuth redirect_uri, or any other canonical-URL-dependent
     * behavior.
     *
     * @return array<int, string>
     */
    public function trustedHosts(): array
    {
        return array_values(array_unique([
            $this->marketingHost(),
            $this->firmAppHost(),
            $this->clientPortalHost(),
            $this->adminHost(),
            $this->myAttorneyHost(),
            $this->apiHost(),
        ]));
    }

    private function hostOf(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?? '');
    }
}
