<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ClientPortalPanelProvider;
use App\Providers\Filament\FirmPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ClientPortalPanelProvider::class,
    FirmPanelProvider::class,
];
