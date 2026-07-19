<?php

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Providers\TestProvider\TestProvider;

/*
|--------------------------------------------------------------------------
| Integration Provider Registry
|--------------------------------------------------------------------------
|
| Structurally mirrors config/filesystems.php's disk-driver-listing
| shape: this file lists registered provider classes only, it wires no
| behavior (checkpoint-00-final-specification.md §6/§8/§21). Each entry
| maps a stable App\Integrations\Enums\ProviderKey string value to the
| fully-qualified provider class App\Integrations\Core\ProviderRegistry
| will resolve via the container. A null value means the key is
| currently NOT registered at all (ProviderRegistry::get() throws
| UnknownProviderException for it) — this is how environment-gated
| providers (like TestProvider) are kept out of the registry entirely
| when their gate is off, rather than merely "marked disabled" while
| still being resolvable.
|
| No real provider (Google/Microsoft/QuickBooks/LawPay/Clio/Stripe/
| Plaid/Zoom/Dropbox) is registered anywhere in this mission.
|
*/

return [

    'providers' => [

        // The only provider implemented in this mission
        // (checkpoint-00-final-specification.md §18). Never
        // registered unless INTEGRATIONS_TEST_PROVIDER_ENABLED is
        // explicitly true — default OFF, so it is absent from the
        // registry entirely in any environment that does not set this
        // flag (defense in depth alongside TestProvider::isConfigured()
        // independently re-checking the same flag).
        ProviderKey::Test->value => env('INTEGRATIONS_TEST_PROVIDER_ENABLED', false)
            ? TestProvider::class
            : null,

    ],

];
