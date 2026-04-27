<?php

namespace WeThink\ModpackInstaller;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Support\HtmlString;

class ModpackInstallerPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'wethink-modpack-installer';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/$id/Pages"),
            "WeThink\\ModpackInstaller\\Filament\\$id\\Pages"
        );
    }

    public function boot(Panel $panel): void {}

    public function getSettingsForm(): array
    {
        return [
            TextInput::make('modrinth_api_token')
                ->label('Modrinth API Token')
                ->helperText('Optional: Used for higher rate limits')
                ->password()
                ->default(config('wethink-modpack-installer.modrinth_api_token')),
            
            Toggle::make('auto_backup')
                ->label('Automatic Backups')
                ->helperText('Create a backup before installing or updating a modpack')
                ->default(config('wethink-modpack-installer.auto_backup', true)),
                
            Toggle::make('smart_migration')
                ->label('Smart Configuration Migration')
                ->helperText('Preserve existing settings during updates')
                ->default(config('wethink-modpack-installer.smart_migration', true)),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'MODRINTH_API_TOKEN' => $data['modrinth_api_token'],
            'MODPACK_INSTALLER_AUTO_BACKUP' => $data['auto_backup'],
            'MODPACK_INSTALLER_SMART_MIGRATION' => $data['smart_migration'],
        ]);

        Notification::make()
            ->title('Settings Saved')
            ->success()
            ->send();
    }
}
