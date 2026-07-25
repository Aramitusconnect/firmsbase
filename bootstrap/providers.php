<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\FirmPanelProvider;
use App\Providers\IntegrationServiceProvider;
use App\Providers\PlatformAdminPolicyServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FirmPanelProvider::class,
    IntegrationServiceProvider::class,
    PlatformAdminPolicyServiceProvider::class,
];
