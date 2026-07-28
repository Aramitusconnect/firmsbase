<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ClientPortalPanelProvider;
use App\Providers\Filament\FirmPanelProvider;
use App\Providers\IntegrationServiceProvider;
use App\Providers\PlatformAdminPolicyServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FirmPanelProvider::class,
    ClientPortalPanelProvider::class,
    IntegrationServiceProvider::class,
    PlatformAdminPolicyServiceProvider::class,
];
