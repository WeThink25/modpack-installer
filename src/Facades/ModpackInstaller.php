<?php

namespace WeThink\ModpackInstaller\Facades;

use Illuminate\Support\Facades\Facade;
use WeThink\ModpackInstaller\Services\ModpackInstallerService;

class ModpackInstaller extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ModpackInstallerService::class;
    }
}
