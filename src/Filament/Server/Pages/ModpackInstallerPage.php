<?php

namespace WeThink\ModpackInstaller\Filament\Server\Pages;

use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use WeThink\ModpackInstaller\Services\ModpackInstallerService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class ModpackInstallerPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-package-import';
    protected static ?string $slug = 'modpacks';
    protected static ?string $navigationLabel = 'Modpack Installer';

    public static function getNavigationSort(): ?int
    {
        return 40; // Moving it up to be above Open in Admin (usually at 50+ or via hook)
    }

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        if (!$server->loadMissing('egg')) {
            return false;
        }

        $tags = $server->egg->tags ?? [];
        $features = $server->egg->features ?? [];
        
        // Allow access if 'wethink_modpack' is in tags or features
        return in_array('wethink_modpack', $tags) || in_array('wethink_modpack', $features);
    }

    public ?string $installationStatus = null;
    public int $installationProgress = 0;
    public ?string $currentStep = null;

    public function getTitle(): string
    {
        return 'Modpack Installer';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(1)
                    ->schema([
                        Section::make('Installation Progress')
                            ->visible(fn() => $this->installationStatus !== null)
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('steps_visual')
                                    ->label('')
                                    ->formatStateUsing(fn() => new \Illuminate\Support\HtmlString($this->renderStepBar())),
                                
                                \Filament\Infolists\Components\TextEntry::make('status')
                                    ->label('Current Task')
                                    ->state(fn() => $this->installationStatus)
                                    ->weight('bold'),
                            ]),

                        Section::make('Available Modpacks')
                            ->description('One click modpack installer from Modrinth.')
                            ->schema([
                                EmbeddedTable::make(),
                            ]),
                    ]),
            ]);
    }

    protected function renderStepBar(): string
    {
        $steps = [
            'Capturing Configs',
            'Creating Backup',
            'Downloading',
            'Decompressing',
            'Restoring Configs',
            'Switching Java',
            'Finalizing',
        ];

        $html = '<div style="display: flex; gap: 4px; width: 100%; height: 35px; margin-bottom: 10px; overflow-x: auto; padding-bottom: 5px;">';
        $currentIdx = array_search($this->currentStep, $steps);
        if ($currentIdx === false) $currentIdx = -1;

        foreach ($steps as $idx => $step) {
            $color = '#2d2d2d'; // Default dark
            if ($idx < $currentIdx) $color = '#10b981'; // Green (Success)
            if ($idx === $currentIdx) $color = '#3b82f6'; // Blue (Current)
            
            $html .= "<div style=\"flex: 1; min-width: 80px; background: $color; display: flex; align-items: center; justify-content: center; color: white; font-size: 9px; border-radius: 4px; transition: all 0.3s; padding: 0 4px; text-align: center; line-height: 1.1;\">$step</div>";
        }
        $html .= '</div>';

        return $html;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page) {
                $server = Filament::getTenant();
                // Improved loader detection
                $tags = collect($server->egg->tags ?? [])->map(fn($t) => strtolower($t))->toArray();
                $loader = null;
                foreach (['fabric', 'forge', 'quilt', 'neoforge'] as $l) {
                    foreach ($tags as $tag) {
                        if (str_contains($tag, $l)) {
                            $loader = $l;
                            break 2;
                        }
                    }
                }

                // Detect current MC version
                $gameVersion = $server->variables()
                    ->where(fn($q) => $q->where('env_variable', 'MINECRAFT_VERSION')
                        ->orWhere('env_variable', 'MC_VERSION')
                        ->orWhere('env_variable', 'VERSION')
                    )
                    ->first()?->server_value;

                $service = app(ModpackInstallerService::class);

                // If 'latest', try to get the actual version from installed metadata
                if ($gameVersion === 'latest') {
                    $installed = $service->getInstalledMetadata($server);
                    if ($installed && isset($installed['mc_version'])) {
                        $gameVersion = $installed['mc_version'];
                    } else {
                        // If we can't find it, we might want to default to something or leave it as latest
                        // Modrinth search for 'latest' versions might not work as expected, 
                        // so we usually want a specific version.
                    }
                }

                $response = $service->searchModpacks($search ?? '', $page, $loader, $gameVersion);

                return new LengthAwarePaginator(
                    $response['hits'] ?? [],
                    $response['total_hits'] ?? 0,
                    20,
                    $page
                );
            })
            ->columns([
                ImageColumn::make('icon_url')
                    ->label('')
                    ->circular(),
                TextColumn::make('title')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn($record) => (strlen($record['description'] ?? '') > 100) ? substr($record['description'], 0, 100) . '...' : $record['description']),
                TextColumn::make('installed_status')
                    ->label('Status')
                    ->badge()
                    ->state(function($record) {
                        $server = Filament::getTenant();
                        /** @var ModpackInstallerService $service */
                        $service = app(ModpackInstallerService::class);
                        $installed = $service->getInstalledMetadata($server);
                        
                        if ($installed && $installed['project_id'] === $record['project_id']) {
                            return 'Installed';
                        }
                        return null;
                    })
                    ->color('success'),
                TextColumn::make('categories')
                    ->label('Loaders')
                    ->badge()
                    ->formatStateUsing(fn($state) => collect($state)->intersect(['fabric', 'forge', 'quilt', 'neoforge'])->map(fn($l) => ucfirst($l))->implode(', '))
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('server_side')
                    ->label('Environment')
                    ->badge()
                    ->state(function($record) {
                        $server = $record['server_side'] ?? 'unknown';
                        $client = $record['client_side'] ?? 'unknown';
                        
                        if ($server !== 'unsupported' && $client !== 'unsupported') return 'Universal';
                        if ($server !== 'unsupported') return 'Server Side';
                        return 'Client Side';
                    })
                    ->color(fn($state) => $state === 'Universal' ? 'success' : 'warning')
                    ->visibleFrom('md'),
                TextColumn::make('downloads')
                    ->icon('tabler-download')
                    ->numeric()
                    ->toggleable()
                    ->visibleFrom('lg'),
            ])
            ->actions([
                Action::make('install')
                    ->label('Install')
                    ->icon('tabler-download')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn($record) => !($this->isInstalled($record)))
                    ->modalHeading(fn(array $record) => "Install " . $record['title'])
                    ->form([
                        Select::make('version_type')
                            ->label('Modpack Build Type')
                            ->options([
                                'release' => 'Stable (Release) - Recommended',
                                'beta' => 'Beta - Testing',
                                'alpha' => 'Alpha - Experimental',
                            ])
                            ->default('release')
                            ->required(),
                    ])
                    ->modalDescription(function(array $record) {
                        $server = Filament::getTenant();
                        $gameVersion = $server->variables()
                            ->where(fn($q) => $q->where('env_variable', 'MINECRAFT_VERSION')
                                ->orWhere('env_variable', 'MC_VERSION')
                                ->orWhere('env_variable', 'VERSION')
                            )
                            ->first()?->server_value ?? 'Latest';

                        return new \Illuminate\Support\HtmlString("
                            You are about to install <strong>" . e($record['title']) . "</strong>.
                            <br><br>
                            <strong>Details:</strong>
                            <ul style='list-style: disc; margin-left: 20px;'>
                                <li><strong>Server Minecraft Version:</strong> " . e($gameVersion) . "</li>
                                <li><strong>Loaders:</strong> " . e(collect($record['categories'])->intersect(['fabric', 'forge', 'quilt', 'neoforge'])->map(fn($l) => ucfirst($l))->implode(', ')) . "</li>
                            </ul>
                            <br>
                            <em>Note: Existing files will be overwritten.</em>
                        ");
                    })
                    ->action(function (array $record, array $data) {
                        $this->runInstallation($record, $data['version_type']);
                    }),
                Action::make('uninstall')
                    ->label('Uninstall')
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn($record) => $this->isInstalled($record))
                    ->modalHeading('Uninstall Modpack')
                    ->modalDescription('This will remove the installed mods and configurations associated with this modpack.')
                    ->action(function () {
                        $this->runUninstallation();
                    }),
            ]);
    }

    protected function isInstalled(array $record): bool
    {
        $server = Filament::getTenant();
        $service = app(ModpackInstallerService::class);
        $installed = $service->getInstalledMetadata($server);
        
        return $installed && $installed['project_id'] === $record['project_id'];
    }

    protected function runUninstallation(): void
    {
        try {
            $server = Filament::getTenant();
            $service = app(ModpackInstallerService::class);
            
            $this->installationStatus = "Uninstalling...";
            $this->currentStep = null; // Clear steps for uninstall

            $success = $service->uninstallModpack($server);

            if ($success) {
                $this->installationStatus = "Uninstalled Successfully";
                Notification::make()
                    ->title('Uninstallation Successful')
                    ->body('The modpack has been removed.')
                    ->success()
                    ->send();
            } else {
                throw new \Exception("Uninstallation failed.");
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Uninstallation Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function runInstallation(array $record, string $versionType = 'release'): void
    {
        try {
            $service = app(ModpackInstallerService::class);
            $versions = $service->getModpackVersions($record['project_id']);
            
            if (empty($versions)) {
                throw new \Exception("No compatible versions found.");
            }

            // Find the version that matches the server's current version and type
            $server = Filament::getTenant();
            $gameVersion = $server->variables()
                ->where(fn($q) => $q->where('env_variable', 'MINECRAFT_VERSION')
                    ->orWhere('env_variable', 'MC_VERSION')
                    ->orWhere('env_variable', 'VERSION')
                )
                ->first()?->server_value;

            // Filter by version type first
            $filteredVersions = collect($versions)->filter(function ($v) use ($versionType) {
                return $v['version_type'] === $versionType;
            });

            // If no match for requested type, try stable as fallback
            if ($filteredVersions->isEmpty()) {
                $filteredVersions = collect($versions)->filter(fn($v) => $v['version_type'] === 'release');
            }

            $version = null;
            if ($gameVersion && $gameVersion !== 'latest') {
                $version = $filteredVersions->first(function ($v) use ($gameVersion) {
                    return in_array($gameVersion, $v['game_versions']);
                });
            }

            // Fallback to first available version if no exact match found or if server is 'latest'
            if (!$version) {
                $version = $filteredVersions->first() ?? $versions[0];
            }

            // Set initial state
            $this->installationStatus = "Starting Installation...";
            $this->currentStep = 'Capturing Configs';
            $this->installationProgress = 5;

            $success = $service->installModpack($server, $version);

            if ($success) {
                $this->currentStep = 'Finalizing';
                $this->installationStatus = "Installation Complete!";
                $this->installationProgress = 100;

                Notification::make()
                    ->title('Installation Successful')
                    ->body('The modpack has been installed.')
                    ->success()
                    ->send();
            } else {
                throw new \Exception("Installation failed.");
            }
        } catch (\Throwable $e) {
            $this->installationStatus = "Error: " . $e->getMessage();
            Notification::make()
                ->title('Installation Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
