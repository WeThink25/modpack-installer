<?php

return [
    'modrinth_api_token' => env('MODRINTH_API_TOKEN'),
    'auto_backup' => env('MODPACK_INSTALLER_AUTO_BACKUP', true),
    'smart_migration' => env('MODPACK_INSTALLER_SMART_MIGRATION', true),
];
