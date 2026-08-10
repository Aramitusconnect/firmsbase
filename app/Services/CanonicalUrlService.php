<?php

namespace App\Services;

/**
 * CanonicalUrlService — Mission 1, Domain & Security Boundary
 * Architecture. The single canonical-URL configuration authority
 * (section 5): every place in the application that needs the base URL
 * or bare hostname of one of FirmsVault's six application surfaces
 * (marketing, Firm app, Client Portal, SuperAdmin, MyAttorney-reserved,
 * API-reserved) reads it from here, which itself reads only
 * config/hosts.php — never from the current HTTP request's Host header
 * (a background queue worker has no trustworthy request context) and
 * never hardcoded inline.
 *
 * Consumers: Filament panel domain binding, the TrustHosts allow-list,
 * legacy-path redirect targets, and any future canonical link
 * generation (password resets, verification, invitations, notification
 * deep links, signed URLs).
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
     * for — the TrustHosts allow-list (section 10). An incoming Host
     * header that matches none of these is rejected before reaching
     * any route, so a forged/poisoned Host header can never influence
     * a reset link, a verification link, an invitation, or any other
     * canonical-URL-dependent behavior.
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
