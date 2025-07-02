<?php
// resources/views/livewire/admin/system/updates.blade.php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Mary\Traits\Toast;

new #[Title('System Updates')] class extends Component {
    use Toast, WithFileUploads;

    public $updateFile;
    public bool $autoUpdateEnabled = false;
    public string $updateChannel = 'stable';
    public bool $checkingUpdates = false;
    public array $availableUpdates = [];
    public array $updateHistory = [];
    public string $currentVersion = '1.0.0';

    public function mount(): void
    {
        $this->loadUpdateSettings();
        $this->loadCurrentVersion();
        $this->loadUpdateHistory();
    }

    public function loadUpdateSettings(): void
    {
        $settings = cache()->get('update_settings', []);
        $this->autoUpdateEnabled = $settings['auto_update_enabled'] ?? false;
        $this->updateChannel = $settings['update_channel'] ?? 'stable';
    }

    public function loadCurrentVersion(): void
    {
        // Try to get version from various sources
        $versionFile = base_path('version.txt');
        if (File::exists($versionFile)) {
            $this->currentVersion = trim(File::get($versionFile));
        } else {
            // Fallback to composer.json or config
            $composerFile = base_path('composer.json');
            if (File::exists($composerFile)) {
                $composer = json_decode(File::get($composerFile), true);
                $this->currentVersion = $composer['version'] ?? '1.0.0';
            }
        }
    }

    public function loadUpdateHistory(): void
    {
        $this->updateHistory = cache()->get('update_history', []);
    }

    public function checkForUpdates(): void
    {
        $this->checkingUpdates = true;

        try {
            // Simulate checking for updates
            // In a real application, you would call your update server
            $this->simulateUpdateCheck();

            activity()
                ->causedBy(auth()->user())
                ->withProperties([
                    'current_version' => $this->currentVersion,
                    'channel' => $this->updateChannel
                ])
                ->log('Checked for system updates');

            $this->success('Update check completed!');

        } catch (\Exception $e) {
            $this->error('Failed to check for updates: ' . $e->getMessage());
        } finally {
            $this->checkingUpdates = false;
        }
    }

    public function simulateUpdateCheck(): void
    {
        // Simulate available updates based on current version
        $updates = [];

        $currentVersionParts = explode('.', $this->currentVersion);
        $majorVersion = (int) $currentVersionParts[0];
        $minorVersion = (int) ($currentVersionParts[1] ?? 0);
        $patchVersion = (int) ($currentVersionParts[2] ?? 0);

        // Simulate patch update
        $updates[] = [
            'version' => "{$majorVersion}.{$minorVersion}." . ($patchVersion + 1),
            'type' => 'patch',
            'size' => '2.5 MB',
            'released_at' => now()->subDays(5)->format('Y-m-d'),
            'description' => 'Bug fixes and security improvements',
            'critical' => false,
            'changelog' => [
                'Fixed user authentication issues',
                'Improved performance in reports section',
                'Security patch for CVE-2024-001',
                'Updated dependencies'
            ]
        ];

        // Simulate minor update
        $updates[] = [
            'version' => "{$majorVersion}." . ($minorVersion + 1) . ".0",
            'type' => 'minor',
            'size' => '15.8 MB',
            'released_at' => now()->subDays(15)->format('Y-m-d'),
            'description' => 'New features and improvements',
            'critical' => false,
            'changelog' => [
                'Added new dashboard widgets',
                'Enhanced reporting capabilities',
                'Improved user interface',
                'New API endpoints',
                'Performance optimizations'
            ]
        ];

        // Simulate major update (only if we're not on latest major)
        if ($majorVersion < 2) {
            $updates[] = [
                'version' => ($majorVersion + 1) . ".0.0",
                'type' => 'major',
                'size' => '45.2 MB',
                'released_at' => now()->subDays(30)->format('Y-m-d'),
                'description' => 'Major version with breaking changes',
                'critical' => false,
                'changelog' => [
                    'Complete UI redesign',
                    'New architecture and performance improvements',
                    'Breaking changes in API',
                    'Enhanced security features',
                    'New modules and functionality'
                ]
            ];
        }

        $this->availableUpdates = $updates;
    }

    public function installUpdate(string $version): void
    {
        try {
            // In a real application, you would download and install the update
            $this->simulateUpdateInstallation($version);

            $updateInfo = collect($this->availableUpdates)->firstWhere('version', $version);

            // Add to update history
            $historyEntry = [
                'version' => $version,
                'type' => $updateInfo['type'],
                'installed_at' => now()->toISOString(),
                'installed_by' => auth()->id(),
                'status' => 'success',
                'previous_version' => $this->currentVersion,
            ];

            $this->updateHistory = array_merge([$historyEntry], $this->updateHistory);
            cache()->put('update_history', $this->updateHistory, now()->addDays(365));

            // Update current version
            $this->currentVersion = $version;
            File::put(base_path('version.txt'), $version);

            // Remove installed update from available updates
            $this->availableUpdates = collect($this->availableUpdates)
                ->reject(fn($update) => $update['version'] === $version)
                ->values()
                ->toArray();

            activity()
                ->causedBy(auth()->user())
                ->withProperties($historyEntry)
                ->log('System updated');

            $this->success("Successfully updated to version {$version}!");

        } catch (\Exception $e) {
            $this->error('Failed to install update: ' . $e->getMessage());
        }
    }

    public function simulateUpdateInstallation(string $version): void
    {
        // Simulate installation steps
        sleep(2); // Simulate download time

        // In a real implementation, you would:
        // 1. Download the update package
        // 2. Verify checksums/signatures
        // 3. Backup current version
        // 4. Extract and apply updates
        // 5. Run migrations if needed
        // 6. Clear caches
        // 7. Verify installation
    }

    public function uploadUpdate(): void
    {
        $this->validate([
            'updateFile' => 'required|file|mimes:zip|max:102400', // 100MB max
        ]);

        try {
            $fileName = 'update_' . time() . '.zip';
            $path = $this->updateFile->storeAs('updates', $fileName);

            // In a real application, you would extract and validate the update package
            $this->processUploadedUpdate($path);

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['update_file' => $fileName])
                ->log('Manual update uploaded');

            $this->success('Update package uploaded successfully!');
            $this->updateFile = null;

        } catch (\Exception $e) {
            $this->error('Failed to upload update: ' . $e->getMessage());
        }
    }

    public function processUploadedUpdate(string $path): void
    {
        // In a real implementation, you would:
        // 1. Extract the zip file
        // 2. Validate the update structure
        // 3. Check version compatibility
        // 4. Add to available updates list

        // For demo purposes, simulate adding an update
        $this->availableUpdates[] = [
            'version' => '1.1.0-custom',
            'type' => 'manual',
            'size' => '10.5 MB',
            'released_at' => now()->format('Y-m-d'),
            'description' => 'Manually uploaded update',
            'critical' => false,
            'changelog' => ['Custom update package']
        ];
    }

    public function saveUpdateSettings(): void
    {
        $settings = [
            'auto_update_enabled' => $this->autoUpdateEnabled,
            'update_channel' => $this->updateChannel,
            'updated_at' => now(),
            'updated_by' => auth()->id(),
        ];

        cache()->put('update_settings', $settings, now()->addDays(30));

        activity()
            ->causedBy(auth()->user())
            ->withProperties($settings)
            ->log('Update settings saved');

        $this->success('Update settings saved successfully!');
    }

    public function rollbackUpdate(string $version): void
    {
        try {
            // Find the update in history
            $updateIndex = collect($this->updateHistory)->search(fn($update) => $update['version'] === $version);

            if ($updateIndex === false) {
                $this->error('Update not found in history.');
                return;
            }

            $update = $this->updateHistory[$updateIndex];
            $previousVersion = $update['previous_version'];

            // Simulate rollback process
            $this->simulateRollback($version, $previousVersion);

            // Update history
            $this->updateHistory[$updateIndex]['rolled_back_at'] = now()->toISOString();
            $this->updateHistory[$updateIndex]['rolled_back_by'] = auth()->id();

            cache()->put('update_history', $this->updateHistory, now()->addDays(365));

            // Revert version
            $this->currentVersion = $previousVersion;
            File::put(base_path('version.txt'), $previousVersion);

            activity()
                ->causedBy(auth()->user())
                ->withProperties([
                    'rolled_back_from' => $version,
                    'rolled_back_to' => $previousVersion
                ])
                ->log('System rolled back');

            $this->success("Successfully rolled back to version {$previousVersion}!");

        } catch (\Exception $e) {
            $this->error('Failed to rollback update: ' . $e->getMessage());
        }
    }

    public function simulateRollback(string $fromVersion, string $toVersion): void
    {
        // Simulate rollback process
        sleep(1);

        // In a real implementation, you would:
        // 1. Restore backup files
        // 2. Rollback database migrations if needed
        // 3. Clear caches
        // 4. Verify rollback success
    }

    public function getUpdateTypeColor(string $type): string
    {
        return match($type) {
            'patch' => 'bg-green-100 text-green-800',
            'minor' => 'bg-blue-100 text-blue-800',
            'major' => 'bg-purple-100 text-purple-800',
            'manual' => 'bg-gray-100 text-gray-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    public function getUpdateTypeIcon(string $type): string
    {
        return match($type) {
            'patch' => 'o-wrench-screwdriver',
            'minor' => 'o-plus-circle',
            'major' => 'o-rocket-launch',
            'manual' => 'o-arrow-up-tray',
            default => 'o-cog-6-tooth',
        };
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="System Updates" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-4">
                <span class="text-sm text-gray-600">Current Version:</span>
                <span class="px-2 py-1 text-sm font-medium text-blue-800 bg-blue-100 rounded">
                    v{{ $currentVersion }}
                </span>
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Check for Updates"
                icon="o-arrow-path"
                wire:click="checkForUpdates"
                spinner="checkForUpdates"
                :disabled="$checkingUpdates"
                class="btn-outline"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Update Settings -->
        <div class="lg:col-span-1">
            <!-- Update Settings Card -->
            <div class="mb-6 bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Update Settings</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="space-y-4">
                        <!-- Auto Updates -->
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="text-sm font-medium text-gray-700">Auto Updates</span>
                                <p class="text-xs text-gray-500">Automatically install patches</p>
                            </div>
                            <input
                                type="checkbox"
                                wire:model="autoUpdateEnabled"
                                class="toggle toggle-primary toggle-sm"
                            />
                        </div>

                        <!-- Update Channel -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Update Channel</label>
                            <select wire:model="updateChannel" class="w-full select select-bordered select-sm">
                                <option value="stable">Stable</option>
                                <option value="beta">Beta</option>
                                <option value="alpha">Alpha</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ $updateChannel === 'stable' ? 'Recommended for production' :
                                   ($updateChannel === 'beta' ? 'Pre-release features' : 'Experimental features') }}
                            </p>
                        </div>

                        <!-- Save Settings -->
                        <x-button
                            label="Save Settings"
                            icon="o-check"
                            wire:click="saveUpdateSettings"
                            class="w-full btn-outline"
                        />
                    </div>
                </div>
            </div>

            <!-- Manual Update Upload -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Manual Update</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="space-y-4">
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Update Package</label>
                            <input
                                type="file"
                                wire:model="updateFile"
                                accept=".zip"
                                class="w-full file-input file-input-bordered file-input-sm"
                            />
                            <p class="mt-1 text-xs text-gray-500">Upload a ZIP file containing the update</p>
                            @error('updateFile')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        @if($updateFile)
                            <x-button
                                label="Upload Update"
                                icon="o-cloud-arrow-up"
                                wire:click="uploadUpdate"
                                spinner="uploadUpdate"
                                class="w-full btn-primary"
                            />
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- Available Updates & History -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Available Updates -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900">Available Updates</h3>
                        @if($checkingUpdates)
                            <span class="text-sm text-blue-600">Checking for updates...</span>
                        @endif
                    </div>
                </div>
                <div class="px-6 py-6">
                    @if(empty($availableUpdates))
                        <div class="py-8 text-center">
                            <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <h4 class="mt-2 text-sm font-medium text-gray-900">System is up to date</h4>
                            <p class="mt-1 text-sm text-gray-500">No updates available at this time.</p>
                        </div>
                    @else
                        <div class="space-y-4">
                            @foreach($availableUpdates as $update)
                                <div class="p-4 border border-gray-200 rounded-lg">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center mb-2 space-x-3">
                                                <h4 class="text-lg font-medium text-gray-900">
                                                    Version {{ $update['version'] }}
                                                </h4>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getUpdateTypeColor($update['type']) }}">
                                                    <x-icon name="{{ $this->getUpdateTypeIcon($update['type']) }}" class="w-3 h-3 mr-1" />
                                                    {{ ucfirst($update['type']) }}
                                                </span>
                                                @if($update['critical'])
                                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-red-800 bg-red-100 rounded-full">
                                                        Critical
                                                    </span>
                                                @endif
                                            </div>

                                            <p class="mb-3 text-sm text-gray-600">{{ $update['description'] }}</p>

                                            <div class="flex items-center mb-3 space-x-4 text-xs text-gray-500">
                                                <span>Size: {{ $update['size'] }}</span>
                                                <span>Released: {{ $update['released_at'] }}</span>
                                            </div>

                                            <!-- Changelog -->
                                            <div class="mb-4">
                                                <h5 class="mb-2 text-sm font-medium text-gray-700">What's New:</h5>
                                                <ul class="space-y-1">
                                                    @foreach($update['changelog'] as $change)
                                                        <li class="flex items-start text-sm text-gray-600">
                                                            <span class="w-1.5 h-1.5 bg-gray-400 rounded-full mt-2 mr-2 flex-shrink-0"></span>
                                                            {{ $change }}
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>

                                            <x-button
                                                label="Install Update"
                                                icon="o-arrow-down-tray"
                                                wire:click="installUpdate('{{ $update['version'] }}')"
                                                wire:confirm="Are you sure you want to install this update? The system may be temporarily unavailable."
                                                class="btn-primary btn-sm"
                                            />
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <!-- Update History -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Update History</h3>
                </div>
                <div class="px-6 py-6">
                    @if(empty($updateHistory))
                        <div class="py-8 text-center">
                            <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <h4 class="mt-2 text-sm font-medium text-gray-900">No update history</h4>
                            <p class="mt-1 text-sm text-gray-500">Update history will appear here after installations.</p>
                        </div>
                    @else
                        <div class="flow-root">
                            <ul class="-mb-8">
                                @foreach($updateHistory as $index => $update)
                                    <li>
                                        <div class="relative pb-8">
                                            @if(!$loop->last)
                                                <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                            @endif
                                            <div class="relative flex space-x-3">
                                                <div>
                                                    <span class="h-8 w-8 rounded-full flex items-center justify-center ring-8 ring-white {{ isset($update['rolled_back_at']) ? 'bg-red-500' : 'bg-green-500' }}">
                                                        @if(isset($update['rolled_back_at']))
                                                            <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                            </svg>
                                                        @else
                                                            <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                            </svg>
                                                        @endif
                                                    </span>
                                                </div>
                                                <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                                                    <div>
                                                        <p class="text-sm text-gray-500">
                                                            <span class="font-medium text-gray-900">
                                                                {{ isset($update['rolled_back_at']) ? 'Rolled back from' : 'Updated to' }}
                                                            </span>
                                                            version {{ $update['version'] }}
                                                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium {{ $this->getUpdateTypeColor($update['type']) }} rounded-full ml-2">
                                                                {{ ucfirst($update['type']) }}
                                                            </span>
                                                        </p>
                                                        @if(isset($update['previous_version']))
                                                            <p class="text-xs text-gray-400">
                                                                Previous: {{ $update['previous_version'] }}
                                                            </p>
                                                        @endif
                                                    </div>
                                                    <div class="text-sm text-right text-gray-500 whitespace-nowrap">
                                                        <div>
                                                            {{ \Carbon\Carbon::parse($update['installed_at'])->format('M d, Y H:i') }}
                                                        </div>
                                                        @if(!isset($update['rolled_back_at']) && $update['version'] === $currentVersion)
                                                            <x-button
                                                                label="Rollback"
                                                                icon="o-arrow-uturn-left"
                                                                wire:click="rollbackUpdate('{{ $update['version'] }}')"
                                                                wire:confirm="Are you sure you want to rollback this update?"
                                                                class="mt-1 btn-xs btn-outline btn-error"
                                                            />
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
