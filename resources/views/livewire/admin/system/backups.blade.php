<?php
// resources/views/livewire/admin/system/backups.blade.php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('System Backups')] class extends Component {
    use Toast;

    public bool $includingDatabase = true;
    public bool $includingFiles = true;
    public bool $includingUploads = true;
    public bool $includingLogs = false;
    public string $backupName = '';
    public bool $isCreating = false;
    public string $autoBackupSchedule = 'daily';
    public bool $autoBackupEnabled = false;
    public int $retentionDays = 30;

    public function mount(): void
    {
        $this->backupName = 'backup_' . now()->format('Y_m_d_H_i_s');
        $this->loadBackupSettings();
    }

    public function loadBackupSettings(): void
    {
        // Load settings from cache or config
        $settings = cache()->get('backup_settings', []);

        $this->autoBackupEnabled = $settings['auto_backup_enabled'] ?? false;
        $this->autoBackupSchedule = $settings['auto_backup_schedule'] ?? 'daily';
        $this->retentionDays = $settings['retention_days'] ?? 30;
    }

    public function getAvailableBackupsProperty(): array
    {
        $backupPath = storage_path('app/backups');
        $backups = [];

        if (File::exists($backupPath)) {
            $files = File::files($backupPath);
            foreach ($files as $file) {
                if (in_array($file->getExtension(), ['zip', 'sql', 'gz'])) {
                    $backups[] = [
                        'filename' => $file->getFilename(),
                        'path' => $file->getPathname(),
                        'size' => $file->getSize(),
                        'created' => $file->getMTime(),
                        'readable_size' => $this->formatBytes($file->getSize()),
                        'created_human' => date('M d, Y H:i', $file->getMTime()),
                        'type' => $this->getBackupType($file->getFilename()),
                        'age_days' => floor((time() - $file->getMTime()) / 86400),
                    ];
                }
            }
        }

        // Sort by creation time (newest first)
        usort($backups, fn($a, $b) => $b['created'] <=> $a['created']);

        return $backups;
    }

    public function getBackupStatsProperty(): array
    {
        $backups = $this->availableBackups;

        return [
            'total_backups' => count($backups),
            'total_size' => array_sum(array_column($backups, 'size')),
            'readable_total_size' => $this->formatBytes(array_sum(array_column($backups, 'size'))),
            'oldest_backup' => !empty($backups) ? min(array_column($backups, 'created')) : null,
            'newest_backup' => !empty($backups) ? max(array_column($backups, 'created')) : null,
            'database_backups' => count(array_filter($backups, fn($b) => $b['type'] === 'database')),
            'full_backups' => count(array_filter($backups, fn($b) => $b['type'] === 'full')),
        ];
    }

    public function createBackup(): void
    {
        $this->isCreating = true;

        try {
            $backupPath = storage_path('app/backups');
            if (!File::exists($backupPath)) {
                File::makeDirectory($backupPath, 0755, true);
            }

            $timestamp = now()->format('Y_m_d_H_i_s');
            $backupName = $this->backupName ?: 'backup_' . $timestamp;

            // Create backup based on selected options
            if ($this->includingDatabase && $this->includingFiles) {
                $this->createFullBackup($backupName, $timestamp);
            } elseif ($this->includingDatabase) {
                $this->createDatabaseBackup($backupName, $timestamp);
            } elseif ($this->includingFiles) {
                $this->createFilesBackup($backupName, $timestamp);
            }

            activity()
                ->causedBy(auth()->user())
                ->withProperties([
                    'backup_name' => $backupName,
                    'includes_database' => $this->includingDatabase,
                    'includes_files' => $this->includingFiles,
                    'includes_uploads' => $this->includingUploads,
                    'includes_logs' => $this->includingLogs,
                ])
                ->log('System backup created');

            $this->success('Backup created successfully!');
            $this->backupName = 'backup_' . now()->format('Y_m_d_H_i_s');

        } catch (\Exception $e) {
            $this->error('Failed to create backup: ' . $e->getMessage());
        } finally {
            $this->isCreating = false;
        }
    }

    public function createDatabaseBackup(string $name, string $timestamp): void
    {
        $filename = "{$name}_db_{$timestamp}.sql";
        $filepath = storage_path('app/backups/' . $filename);

        $database = config('database.connections.' . config('database.default'));
        $dbName = $database['database'];
        $username = $database['username'];
        $password = $database['password'];
        $host = $database['host'];
        $port = $database['port'] ?? 3306;

        $command = sprintf(
            'mysqldump --user=%s --password=%s --host=%s --port=%s --single-transaction --routines --triggers %s > %s',
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($dbName),
            escapeshellarg($filepath)
        );

        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new \Exception('Database backup failed');
        }
    }

    public function createFilesBackup(string $name, string $timestamp): void
    {
        $filename = "{$name}_files_{$timestamp}.zip";
        $filepath = storage_path('app/backups/' . $filename);

        $zip = new \ZipArchive();
        if ($zip->open($filepath, \ZipArchive::CREATE) !== TRUE) {
            throw new \Exception('Cannot create zip file');
        }

        // Add application files
        $this->addDirectoryToZip($zip, app_path(), 'app/');
        $this->addDirectoryToZip($zip, config_path(), 'config/');
        $this->addDirectoryToZip($zip, database_path(), 'database/');
        $this->addDirectoryToZip($zip, resource_path(), 'resources/');

        // Add uploads if selected
        if ($this->includingUploads && File::exists(storage_path('app/public'))) {
            $this->addDirectoryToZip($zip, storage_path('app/public'), 'storage/app/public/');
        }

        // Add logs if selected
        if ($this->includingLogs && File::exists(storage_path('logs'))) {
            $this->addDirectoryToZip($zip, storage_path('logs'), 'storage/logs/');
        }

        $zip->close();
    }

    public function createFullBackup(string $name, string $timestamp): void
    {
        // Create database backup first
        $this->createDatabaseBackup($name, $timestamp);

        // Create files backup
        $this->createFilesBackup($name, $timestamp);

        // Create combined zip
        $combinedFilename = "{$name}_full_{$timestamp}.zip";
        $combinedPath = storage_path('app/backups/' . $combinedFilename);

        $zip = new \ZipArchive();
        if ($zip->open($combinedPath, \ZipArchive::CREATE) !== TRUE) {
            throw new \Exception('Cannot create combined backup file');
        }

        // Add database backup to combined zip
        $dbBackup = storage_path("app/backups/{$name}_db_{$timestamp}.sql");
        if (File::exists($dbBackup)) {
            $zip->addFile($dbBackup, "database_{$timestamp}.sql");
        }

        // Add files backup to combined zip
        $filesBackup = storage_path("app/backups/{$name}_files_{$timestamp}.zip");
        if (File::exists($filesBackup)) {
    public function addDirectoryToZip(\ZipArchive $zip, string $directory, string $zipPath = ''): void
    {
        if (!File::exists($directory)) {
            return;
        }

        $files = File::allFiles($directory);
        foreach ($files as $file) {
            $relativePath = $zipPath . $file->getRelativePathname();
            $zip->addFile($file->getPathname(), $relativePath);
        }
    }

    public function downloadBackup(string $filename): void
    {
        $filepath = storage_path('app/backups/' . $filename);

        if (!File::exists($filepath)) {
            $this->error('Backup file not found.');
            return;
        }

        activity()
            ->causedBy(auth()->user())
            ->withProperties(['backup_file' => $filename])
            ->log('Backup downloaded');

        return response()->download($filepath);
    }

    public function deleteBackup(string $filename): void
    {
        $filepath = storage_path('app/backups/' . $filename);

        if (!File::exists($filepath)) {
            $this->error('Backup file not found.');
            return;
        }

        try {
            File::delete($filepath);

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['backup_file' => $filename])
                ->log('Backup deleted');

            $this->success('Backup deleted successfully!');

        } catch (\Exception $e) {
            $this->error('Failed to delete backup: ' . $e->getMessage());
        }
    }

    public function cleanupOldBackups(): void
    {
        $backups = $this->availableBackups;
        $deleted = 0;
        $cutoffDate = now()->subDays($this->retentionDays)->timestamp;

        foreach ($backups as $backup) {
            if ($backup['created'] < $cutoffDate) {
                try {
                    File::delete($backup['path']);
                    $deleted++;
                } catch (\Exception $e) {
                    // Continue with other files
                }
            }
        }

        activity()
            ->causedBy(auth()->user())
            ->withProperties([
                'deleted_count' => $deleted,
                'retention_days' => $this->retentionDays
            ])
            ->log('Old backups cleaned up');

        $this->success("Deleted {$deleted} old backup(s) successfully!");
    }

    public function saveBackupSettings(): void
    {
        $settings = [
            'auto_backup_enabled' => $this->autoBackupEnabled,
            'auto_backup_schedule' => $this->autoBackupSchedule,
            'retention_days' => $this->retentionDays,
            'updated_at' => now(),
            'updated_by' => auth()->id(),
        ];

        cache()->put('backup_settings', $settings, now()->addDays(30));

        activity()
            ->causedBy(auth()->user())
            ->withProperties($settings)
            ->log('Backup settings updated');

        $this->success('Backup settings saved successfully!');
    }

    public function getBackupType(string $filename): string
    {
        if (str_contains($filename, '_db_')) {
            return 'database';
        } elseif (str_contains($filename, '_files_')) {
            return 'files';
        } elseif (str_contains($filename, '_full_')) {
            return 'full';
        }
        return 'unknown';
    }

    public function getBackupIcon(string $type): string
    {
        return match($type) {
            'database' => 'o-circle-stack',
            'files' => 'o-folder',
            'full' => 'o-archive-box',
            default => 'o-document',
        };
    }

    public function getBackupColor(string $type): string
    {
        return match($type) {
            'database' => 'bg-blue-100 text-blue-800',
            'files' => 'bg-green-100 text-green-800',
            'full' => 'bg-purple-100 text-purple-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    public function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="System Backups" separator>
        <x-slot:actions>
            <x-button
                label="Cleanup Old Backups"
                icon="o-trash"
                wire:click="cleanupOldBackups"
                wire:confirm="Are you sure you want to delete backups older than {{ $retentionDays }} days?"
                class="btn-outline"
            />
            <x-button
                label="Create Backup"
                icon="o-plus"
                wire:click="createBackup"
                spinner="createBackup"
                :disabled="$isCreating"
                class="btn-primary"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <!-- Backup Creation Panel -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Create Backup</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="space-y-4">
                        <!-- Backup Name -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Backup Name</label>
                            <input
                                type="text"
                                wire:model="backupName"
                                class="w-full input input-bordered input-sm"
                                placeholder="backup_name"
                            />
                        </div>

                        <!-- What to Include -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Include in Backup</label>
                            <div class="space-y-2">
                                <label class="flex items-center">
                                    <input
                                        type="checkbox"
                                        wire:model="includingDatabase"
                                        class="checkbox checkbox-primary checkbox-sm"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Database</span>
                                </label>
                                <label class="flex items-center">
                                    <input
                                        type="checkbox"
                                        wire:model="includingFiles"
                                        class="checkbox checkbox-primary checkbox-sm"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Application Files</span>
                                </label>
                                <label class="flex items-center">
                                    <input
                                        type="checkbox"
                                        wire:model="includingUploads"
                                        class="checkbox checkbox-primary checkbox-sm"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Uploaded Files</span>
                                </label>
                                <label class="flex items-center">
                                    <input
                                        type="checkbox"
                                        wire:model="includingLogs"
                                        class="checkbox checkbox-primary checkbox-sm"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Log Files</span>
                                </label>
                            </div>
                        </div>

                        <!-- Create Button -->
                        <x-button
                            label="{{ $isCreating ? 'Creating...' : 'Create Backup' }}"
                            icon="o-plus"
                            wire:click="createBackup"
                            spinner="createBackup"
                            :disabled="$isCreating || (!$includingDatabase && !$includingFiles)"
                            class="w-full btn-primary"
                        />
                    </div>
                </div>
            </div>

            <!-- Backup Settings -->
            <div class="mt-6 bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Backup Settings</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="space-y-4">
                        <!-- Auto Backup -->
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-gray-700">Auto Backup</span>
                            <input
                                type="checkbox"
                                wire:model="autoBackupEnabled"
                                class="toggle toggle-primary toggle-sm"
                            />
                        </div>

                        @if($autoBackupEnabled)
                            <!-- Schedule -->
                            <div>
                                <label class="block mb-2 text-sm font-medium text-gray-700">Schedule</label>
                                <select wire:model="autoBackupSchedule" class="w-full select select-bordered select-sm">
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </div>
                        @endif

                        <!-- Retention -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Retention (days)</label>
                            <input
                                type="number"
                                wire:model="retentionDays"
                                min="1"
                                max="365"
                                class="w-full input input-bordered input-sm"
                            />
                        </div>

                        <!-- Save Settings -->
                        <x-button
                            label="Save Settings"
                            icon="o-check"
                            wire:click="saveBackupSettings"
                            class="w-full btn-outline"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Backups List -->
        <div class="lg:col-span-3">
            <!-- Stats -->
            <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-4">
                <x-card>
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="flex items-center justify-center w-8 h-8 bg-blue-500 rounded-full">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Total Backups</p>
                            <p class="text-lg font-semibold text-gray-900">{{ $this->backupStats['total_backups'] }}</p>
                        </div>
                    </div>
                </x-card>

                <x-card>
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="flex items-center justify-center w-8 h-8 bg-green-500 rounded-full">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Database Backups</p>
                            <p class="text-lg font-semibold text-gray-900">{{ $this->backupStats['database_backups'] }}</p>
                        </div>
                    </div>
                </x-card>

                <x-card>
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="flex items-center justify-center w-8 h-8 bg-purple-500 rounded-full">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Full Backups</p>
                            <p class="text-lg font-semibold text-gray-900">{{ $this->backupStats['full_backups'] }}</p>
                        </div>
                    </div>
                </x-card>

                <x-card>
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="flex items-center justify-center w-8 h-8 bg-orange-500 rounded-full">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-900">Total Size</p>
                            <p class="text-lg font-semibold text-gray-900">{{ $this->backupStats['readable_total_size'] }}</p>
                        </div>
                    </div>
                </x-card>
            </div>

            <!-- Backups Table -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Available Backups</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                    Backup File
                                </th>
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                    Type
                                </th>
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                    Size
                                </th>
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                    Created
                                </th>
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                    Age
                                </th>
                                <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($this->availableBackups as $backup)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <x-icon name="{{ $this->getBackupIcon($backup['type']) }}" class="w-5 h-5 mr-3 text-gray-400" />
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    {{ $backup['filename'] }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getBackupColor($backup['type']) }}">
                                            {{ ucfirst($backup['type']) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900 whitespace-nowrap">
                                        {{ $backup['readable_size'] }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                        {{ $backup['created_human'] }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                        {{ $backup['age_days'] }} days
                                        @if($backup['age_days'] > $retentionDays)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 ml-2">
                                                Old
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm font-medium whitespace-nowrap">
                                        <div class="flex items-center space-x-2">
                                            <x-button
                                                icon="o-arrow-down-tray"
                                                wire:click="downloadBackup('{{ $backup['filename'] }}')"
                                                tooltip="Download"
                                                class="btn-xs btn-ghost"
                                            />
                                            <x-button
                                                icon="o-trash"
                                                wire:click="deleteBackup('{{ $backup['filename'] }}')"
                                                wire:confirm="Are you sure you want to delete this backup?"
                                                tooltip="Delete"
                                                class="text-red-600 btn-xs btn-ghost"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center">
                                        <div class="text-center">
                                            <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"></path>
                                            </svg>
                                            <h3 class="mt-2 text-sm font-medium text-gray-900">No backups found</h3>
                                            <p class="mt-1 text-sm text-gray-500">Create your first backup to get started.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
