<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ClientPortalPanelProvider;
use App\Providers\Filament\FirmPanelProvider;
use App\Providers\IntegrationServiceProvider;
use App\Providers\MyAttorneyRateLimitServiceProvider;
use App\Providers\PlatformAdminPolicyServiceProvider;

return [
    AppServiceProvider::class,
    MyAttorneyRateLimitServiceProvider::class,
    AdminPanelProvider::class,
    FirmPanelProvider::class,
    ClientPortalPanelProvider::class,
    IntegrationServiceProvider::class,
    PlatformAdminPolicyServiceProvider::class,
];
