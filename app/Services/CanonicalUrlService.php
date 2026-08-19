<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class CanonicalUrlService
{
    public function marketingUrl(): string
    {
        return $this->configuredUrl('marketing_url');
    }

    public function firmAppUrl(): string
    {
        return $this->configuredUrl('firm_app_url');
    }

    public function clientPortalUrl(): string
    {
        return $this->configuredUrl('client_portal_url');
    }

    public function adminUrl(): string
    {
        return $this->configuredUrl('admin_url');
    }

    public function myAttorneyUrl(): string
    {
        return $this->configuredUrl('myattorney_url');
    }

    public function apiUrl(): string
    {
        return $this->configuredUrl('api_url');
    }

    /**
     * @return array<int, string>
     */
    public function trustedHosts(): array
    {
        return array_values(array_unique([
            $this->hostOf('marketing_url', $this->marketingUrl()),
            $this->hostOf('firm_app_url', $this->firmAppUrl()),
            $this->hostOf('client_portal_url', $this->clientPortalUrl()),
            $this->hostOf('admin_url', $this->adminUrl()),
            $this->hostOf('myattorney_url', $this->myAttorneyUrl()),
            $this->hostOf('api_url', $this->apiUrl()),
        ]));
    }

    /**
     * @return array<int, string>
     */
    public function trustedHostPatterns(): array
    {
        return array_map(
            static fn (string $host): string => '^'.preg_quote($host, '#').'$',
            $this->trustedHosts(),
        );
    }

    private function configuredUrl(string $key): string
    {
        return rtrim((string) config("hosts.{$key}"), '/');
    }

    private function hostOf(string $key, string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (
            ! is_string($host)
            || $host === ''
            || preg_match('/[\s\/\\\\:@]/', $host) === 1
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new RuntimeException("Invalid canonical URL configuration for {$key}: hostname is missing or invalid.");
        }

        return strtolower($host);
    }
}
