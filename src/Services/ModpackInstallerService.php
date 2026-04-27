<?php

namespace WeThink\ModpackInstaller\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ModpackInstallerService
{
    /**
     * Search for modpacks on Modrinth.
     */
    public function searchModpacks(string $query = '', int $page = 1, ?string $loader = null, ?string $gameVersion = null): array
    {
        $offset = ($page - 1) * 20;
        
        $facets = [
            ["project_type:modpack"],
            ["server_side:required", "server_side:optional"], // Only modpacks that work on servers
        ];

        if ($loader) {
            $facets[] = ["categories:{$loader}"];
        }

        if ($gameVersion) {
            $facets[] = ["versions:{$gameVersion}"];
        }
        
        $data = [
            'query' => $query,
            'offset' => $offset,
            'limit' => 20,
            'facets' => json_encode($facets),
        ];

        $cacheKey = "modpack_search:" . md5($query . $page . $loader . $gameVersion);

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($data) {
            try {
                $response = Http::get('https://api.modrinth.com/v2/search', $data);
                return $response->json();
            } catch (Exception $e) {
                Log::error("Modpack search failed: " . $e->getMessage());
                return ['hits' => [], 'total_hits' => 0];
            }
        });
    }

    /**
     * Get versions for a specific modpack.
     */
    public function getModpackVersions(string $projectId): array
    {
        $cacheKey = "modpack_versions:" . $projectId;

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($projectId) {
            try {
                $response = Http::get("https://api.modrinth.com/v2/project/{$projectId}/version");
                return $response->json();
            } catch (Exception $e) {
                Log::error("Failed to fetch modpack versions: " . $e->getMessage());
                return [];
            }
        });
    }

    public function getInstalledMetadata(Server $server): ?array
    {
        $cacheKey = "modpack_metadata:{$server->uuid}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($server) {
            try {
                /** @var DaemonFileRepository $fileRepository */
                $fileRepository = app(DaemonFileRepository::class);
                $fileRepository->setServer($server);
                $content = $fileRepository->getContent('.wethink-modpack.json');
                return json_decode($content, true);
            } catch (Exception $e) {
                return null;
            }
        });
    }

    /**
     * Install or Update a modpack.
     */
    public function installModpack(Server $server, array $versionData, bool $isUpdate = false): bool
    {
        /** @var DaemonFileRepository $fileRepository */
        $fileRepository = app(DaemonFileRepository::class);
        $fileRepository->setServer($server);

        try {
            // 1. Check Backup Limit (Disabled as requested)
            /*
            if (config('wethink-modpack-installer.auto_backup', true)) {
                $this->createBackup($server);
            }
            */

            // 2. Identify Modpack File
            $mrpackFile = collect($versionData['files'])->first(fn($f) => str_ends_with($f['filename'], '.mrpack'));
            if (!$mrpackFile) {
                throw new Exception("No .mrpack file found for this version.");
            }

            // 3. Handle Smart Migration (Save configs)
            $preservedConfigs = [];
            if ($isUpdate && config('wethink-modpack-installer.smart_migration', true)) {
                $preservedConfigs = $this->capturePreservedConfigs($server, $fileRepository);
            }

            // 4. Download Modpack
            $filename = $mrpackFile['filename'];
            $fileRepository->pull($mrpackFile['url'], "/");

            // 5. Decompress Modpack (Overrides)
            sleep(2); 
            try {
                Http::daemon($server->node)
                    ->post("/api/servers/{$server->uuid}/files/decompress", [
                        'root' => '/',
                        'file' => $filename,
                    ]);
                
                // Delete the zip file after decompression
                Http::daemon($server->node)
                    ->post("/api/servers/{$server->uuid}/files/delete", [
                        'root' => '/',
                        'files' => [$filename],
                    ]);
            } catch (\Throwable $e) {
                Log::warning("Decompression/Cleanup failed: " . $e->getMessage());
            }

            // 6. Parse Modrinth Index and Download Mods
            // Wait for decompression to finish so we can read the index
            $maxTries = 10;
            $indexContent = null;
            for ($i = 0; $i < $maxTries; $i++) {
                try {
                    $indexContent = $fileRepository->getContent('modrinth.index.json');
                    if ($indexContent) break;
                } catch (Exception $e) {
                    sleep(2);
                }
            }

            if ($indexContent) {
                $index = json_decode($indexContent, true);
                if (isset($index['files'])) {
                    foreach ($index['files'] as $file) {
                        if (isset($file['downloads'][0])) {
                            $dir = dirname($file['path']);
                            $dir = ($dir === '.') ? '/' : '/' . $dir;
                            $fileRepository->pull($file['downloads'][0], $dir);
                        }
                    }
                }
            } else {
                Log::warning("Could not find modrinth.index.json after waiting. Mod downloads skipped.");
            }
            
            // 7. Handle Smart Migration (Restore configs)
            if ($isUpdate && !empty($preservedConfigs)) {
                $this->restorePreservedConfigs($server, $fileRepository, $preservedConfigs);
            }

            // 8. Automatic Version & Java Switching (Disabled as requested)
            /*
            $mcVersion = $versionData['game_versions'][0] ?? '1.20.1';
            $this->switchMinecraftVersion($server, $mcVersion);
            $this->switchJavaVersion($server, $mcVersion);
            */

            $this->saveInstallationMetadata($server, $fileRepository, $versionData);

            return true;
        } catch (\Throwable $e) {
            Log::error("Modpack installation failed: " . $e->getMessage());
            throw $e; // Re-throw to show in UI
        }
    }

    protected function createBackup(Server $server): void
    {
        // Check backup limit
        $currentBackups = $server->backups()->count();
        if ($server->backups_limit > 0 && $currentBackups >= $server->backups_limit) {
            throw new Exception("Backup limit reached. Please delete an old backup before doing this installation.");
        }

        try {
            Http::daemon($server->node)
                ->post("/api/servers/{$server->uuid}/backups", [
                    'name' => "Pre-Modpack Installation: " . now()->toDateTimeString(),
                ]);
        } catch (Exception $e) {
            Log::warning("Backup failed, but continuing: " . $e->getMessage());
        }
    }

    protected function switchMinecraftVersion(Server $server, string $mcVersion): void
    {
        $variable = $server->variables()->where(fn($q) => $q->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))->first();
        if ($variable) {
            $variable->update(['server_value' => $mcVersion]);
        }
    }

    protected function switchJavaVersion(Server $server, string $mcVersion): void
    {
        $javaVersion = $this->getRequiredJavaForMinecraft($mcVersion);
        
        // Pelican logic to update environment variables or Docker image
        $variable = $server->variables()->where('env_variable', 'JAVA_VERSION')->first();
        if ($variable) {
            $variable->update(['server_value' => $javaVersion]);
        }

        // If the server uses a specific image for Java versions, we could update it here.
        // This depends on the Pelican/WeThink implementation details.
    }

    protected function getRequiredJavaForMinecraft(string $version): int
    {
        $parts = explode('.', $version);
        $minor = isset($parts[1]) ? (int)$parts[1] : 0;

        if ($minor >= 20) return 21;
        if ($minor >= 17) return 17;
        if ($minor >= 16) return 16;
        if ($minor >= 12) return 8; // Or 11
        return 8;
    }

    protected function capturePreservedConfigs(Server $server, DaemonFileRepository $fileRepository): array
    {
        $filesToPreserve = [
            'server.properties',
            'whitelist.json',
            'ops.json',
            'usercache.json',
            'banned-players.json',
            'banned-ips.json',
        ];

        $configs = [];
        foreach ($filesToPreserve as $file) {
            try {
                $content = $fileRepository->getContent($file);
                $configs[$file] = $content;
            } catch (Exception $e) {
                // File might not exist
            }
        }
        return $configs;
    }

    protected function restorePreservedConfigs(Server $server, DaemonFileRepository $fileRepository, array $configs): void
    {
        foreach ($configs as $file => $content) {
            try {
                $fileRepository->putContent($file, $content);
            } catch (Exception $e) {
                Log::warning("Failed to restore config {$file}: " . $e->getMessage());
            }
        }
    }

    protected function saveInstallationMetadata(Server $server, DaemonFileRepository $fileRepository, array $versionData): void
    {
        $metadata = [
            'installed_at' => now()->toIso8601String(),
            'project_id' => $versionData['project_id'],
            'version_id' => $versionData['id'],
            'version_number' => $versionData['version_number'],
            'mc_version' => $versionData['game_versions'][0] ?? 'unknown',
        ];

        $fileRepository->putContent('.wethink-modpack.json', json_encode($metadata, JSON_PRETTY_PRINT));
    }

    public function uninstallModpack(Server $server): bool
    {
        try {
            /** @var DaemonFileRepository $fileRepository */
            $fileRepository = app(DaemonFileRepository::class);
            $fileRepository->setServer($server);

            $filesToDelete = ['.wethink-modpack.json', 'modrinth.index.json'];

            // 1. Read index to find files
            try {
                $indexContent = $fileRepository->getContent('modrinth.index.json');
                $index = json_decode($indexContent, true);

                if (isset($index['files'])) {
                    foreach ($index['files'] as $file) {
                        $filesToDelete[] = $file['path'];
                    }
                }
            } catch (\Throwable $indexError) {
                Log::warning("Uninstaller could not read modrinth.index.json: " . $indexError->getMessage());
            }

            // 2. Perform deletion via Daemon API
            Http::daemon($server->node)
                ->post("/api/servers/{$server->uuid}/files/delete", [
                    'root' => '/',
                    'files' => array_values(array_unique($filesToDelete)),
                ]);

            // 3. Clear cache
            Cache::forget("modpack_metadata:{$server->uuid}");

            return true;
        } catch (\Throwable $e) {
            Log::error("Modpack uninstallation failed: " . $e->getMessage());
            // Aggressive fallback to clear cache anyway
            Cache::forget("modpack_metadata:{$server->uuid}");
            return false;
        }
    }
}
