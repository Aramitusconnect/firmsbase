<?php

declare(strict_types=1);

$localDefault = static function (string $url): ?string {
    return in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true) ? $url : null;
};

return [
    'marketing_url' => env('MARKETING_URL', $localDefault('http://firmsvault.test')),
    'firm_app_url' => env('FIRM_APP_URL', $localDefault('http://app.firmsvault.test')),
    'client_portal_url' => env('CLIENT_PORTAL_URL', $localDefault('http://client.firmsvault.test')),
    'admin_url' => env('ADMIN_URL', $localDefault('http://admin.firmsvault.test')),
    'myattorney_url' => env('MYATTORNEY_URL', $localDefault('http://myattorney.firmsvault.test')),
    'api_url' => env('API_URL', $localDefault('http://api.firmsvault.test')),
];
