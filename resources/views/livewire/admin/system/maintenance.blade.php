<?php
// resources/views/livewire/admin/system/maintenance.blade.php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('System Maintenance')] class extends Component {
    use Toast;

    public bool $maintenanceMode = false;
    public string $maintenanceMessage = '';
    public bool $allowedIp = false;
    public string $scheduledMaintenanceDate = '';
    public string $scheduledMaintenanceTime = '';
    public array $runningTasks = [];

    public function mount(): void
    {
        $this->loadMaintenanceStatus();
        $this->loadScheduledMaintenance();
    }

    public function loadMaintenanceStatus(): void
    {
        $this->maintenanceMode = app()->isDownForMaintenance();

        if ($this->maintenanceMode) {
            $maintenanceFile = storage_path('framework/down');
            if (File::exists($maintenanceFile)) {
                $data = json_decode(File::get($maintenanceFile), true);
                $this->maintenanceMessage = $data['message'] ?? 'System is under maintenance';
            }
        }
    }

    public function loadScheduledMaintenance(): void
    {
        $scheduled = cache()->get('scheduled_maintenance', []);
        $this->scheduledMaintenanceDate = $scheduled['date'] ?? '';
        $this->scheduledMaintenanceTime = $scheduled['time'] ?? '';
    }

    public function enableMaintenanceMode(): void
    {
        try {
            $options = [];

            if ($this->maintenanceMessage) {
                $options['message'] = $this->maintenanceMessage;
            }

            if ($this->allowedIp) {
                $options['allow'] = [request()->ip()];
            }

            Artisan::call('down', $options);

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['files_cleared' => $cleared])
                ->log('System logs cleared');

            $this->success("Logs cleared successfully! {$cleared} files processed.");

        } catch (\Exception $e) {
            $this->error('Failed to clear logs: ' . $e->getMessage());
        }
    }

    public function clearSessions(): void
    {
        try {
            Artisan::call('session:clear');

            activity()
                ->causedBy(auth()->user())
                ->log('Sessions cleared');

            $this->success('Sessions cleared successfully!');

        } catch (\Exception $e) {
            $this->error('Failed to clear sessions: ' . $e->getMessage());
        }
    }

    public function clearQueue(): void
    {
        try {
            Artisan::call('queue:clear');

            activity()
                ->causedBy(auth()->user())
                ->log('Queue cleared');

            $this->success('Queue cleared successfully!');

        } catch (\Exception $e) {
            $this->error('Failed to clear queue: ' . $e->getMessage());
        }
    }

    public function restartQueue(): void
    {
        try {
            Artisan::call('queue:restart');

            activity()
                ->causedBy(auth()->user())
                ->log('Queue restarted');

            $this->success('Queue restarted successfully!');

        } catch (\Exception $e) {
            $this->error('Failed to restart queue: ' . $e->getMessage());
        }
    }

    public function generateAppKey(): void
    {
        try {
            Artisan::call('key:generate', ['--force' => true]);

            activity()
                ->causedBy(auth()->user())
                ->log('Application key generated');

            $this->success('Application key generated successfully!');

        } catch (\Exception $e) {
            $this->error('Failed to generate app key: ' . $e->getMessage());
        }
    }

    public function linkStorage(): void
    {
        try {
            Artisan::call('storage:link');

            activity()
                ->causedBy(auth()->user())
                ->log('Storage linked');

            $this->success('Storage linked successfully!');

        } catch (\Exception $e) {
            $this->error('Failed to link storage: ' . $e->getMessage());
        }
    }

    public function getSystemHealthProperty(): array
    {
        $checks = [
            'storage_writable' => is_writable(storage_path()),
            'bootstrap_cache_writable' => is_writable(bootstrap_path('cache')),
            'env_file_exists' => File::exists(base_path('.env')),
            'app_key_set' => !empty(config('app.key')),
            'database_connected' => $this->checkDatabaseConnection(),
            'cache_working' => $this->checkCacheWorking(),
        ];

        $passing = collect($checks)->filter()->count();
        $total = count($checks);

        return [
            'checks' => $checks,
            'passing' => $passing,
            'total' => $total,
            'percentage' => $total > 0 ? round(($passing / $total) * 100) : 0,
        ];
    }

    public function checkDatabaseConnection(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function checkCacheWorking(): bool
    {
        try {
            Cache::put('maintenance_test', 'test', 60);
            $result = Cache::get('maintenance_test') === 'test';
            Cache::forget('maintenance_test');
            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getMaintenanceTasksProperty(): array
    {
        return [
            [
                'name' => 'Clear Application Cache',
                'description' => 'Clear all cached data including views, config, and routes',
                'action' => 'clearCache',
                'icon' => 'o-trash',
                'color' => 'btn-outline',
                'critical' => false,
            ],
            [
                'name' => 'Clear Configuration Cache',
                'description' => 'Clear cached configuration files',
                'action' => 'clearConfigCache',
                'icon' => 'o-cog-6-tooth',
                'color' => 'btn-outline',
                'critical' => false,
            ],
            [
                'name' => 'Clear Route Cache',
                'description' => 'Clear cached application routes',
                'action' => 'clearRouteCache',
                'icon' => 'o-map',
                'color' => 'btn-outline',
                'critical' => false,
            ],
            [
                'name' => 'Clear View Cache',
                'description' => 'Clear compiled Blade templates',
                'action' => 'clearViewCache',
                'icon' => 'o-eye',
                'color' => 'btn-outline',
                'critical' => false,
            ],
            [
                'name' => 'Optimize Application',
                'description' => 'Cache configuration, routes, and views for better performance',
                'action' => 'optimizeApplication',
                'icon' => 'o-rocket-launch',
                'color' => 'btn-success',
                'critical' => false,
            ],
            [
                'name' => 'Clear Optimization',
                'description' => 'Remove all cached optimization files',
                'action' => 'clearOptimization',
                'icon' => 'o-x-mark',
                'color' => 'btn-outline',
                'critical' => false,
            ],
            [
                'name' => 'Run Migrations',
                'description' => 'Execute pending database migrations',
                'action' => 'runMigrations',
                'icon' => 'o-circle-stack',
                'color' => 'btn-warning',
                'critical' => true,
            ],
            [
                'name' => 'Optimize Database',
                'description' => 'Optimize all database tables for better performance',
                'action' => 'optimizeDatabase',
                'icon' => 'o-wrench-screwdriver',
                'color' => 'btn-info',
                'critical' => false,
            ],
            [
                'name' => 'Clear System Logs',
                'description' => 'Clear all application log files',
                'action' => 'clearLogs',
                'icon' => 'o-document-minus',
                'color' => 'btn-outline',
                'critical' => false,
            ],
            [
                'name' => 'Clear Sessions',
                'description' => 'Clear all user sessions',
                'action' => 'clearSessions',
                'icon' => 'o-user-minus',
                'color' => 'btn-warning',
                'critical' => true,
            ],
            [
                'name' => 'Restart Queue',
                'description' => 'Restart all queue workers',
                'action' => 'restartQueue',
                'icon' => 'o-arrow-path',
                'color' => 'btn-info',
                'critical' => false,
            ],
            [
                'name' => 'Link Storage',
                'description' => 'Create symbolic link for public storage',
                'action' => 'linkStorage',
                'icon' => 'o-link',
                'color' => 'btn-outline',
                'critical' => false,
            ],
        ];
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="System Maintenance" separator>
        <x-slot:actions>
            @if($maintenanceMode)
                <x-button
                    label="Disable Maintenance"
                    icon="o-play"
                    wire:click="disableMaintenanceMode"
                    class="btn-success"
                />
            @else
                <x-button
                    label="Enable Maintenance"
                    icon="o-pause"
                    wire:click="enableMaintenanceMode"
                    class="btn-warning"
                />
            @endif
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Maintenance Mode Controls -->
        <div class="lg:col-span-1">
            <!-- Current Status -->
            <div class="mb-6 bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Maintenance Status</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-sm font-medium text-gray-700">Current Status</span>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $maintenanceMode ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                            {{ $maintenanceMode ? 'Maintenance Mode' : 'Online' }}
                        </span>
                    </div>

                    @if($maintenanceMode)
                        <div class="p-4 mb-4 rounded-lg bg-red-50">
                            <div class="flex">
                                <svg class="w-5 h-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-red-800">System is down for maintenance</h4>
                                    @if($maintenanceMessage)
                                        <p class="mt-1 text-sm text-red-700">{{ $maintenanceMessage }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Maintenance Mode Settings -->
                    <div class="space-y-4">
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Maintenance Message</label>
                            <textarea
                                wire:model="maintenanceMessage"
                                rows="3"
                                class="w-full textarea textarea-bordered textarea-sm"
                                placeholder="System is temporarily unavailable for maintenance..."
                            ></textarea>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-gray-700">Allow My IP</span>
                            <input
                                type="checkbox"
                                wire:model="allowedIp"
                                class="toggle toggle-primary toggle-sm"
                            />
                        </div>

                        <div class="pt-4 border-t border-gray-200">
                            @if($maintenanceMode)
                                <x-button
                                    label="Disable Maintenance Mode"
                                    icon="o-play"
                                    wire:click="disableMaintenanceMode"
                                    class="w-full btn-success"
                                />
                            @else
                                <x-button
                                    label="Enable Maintenance Mode"
                                    icon="o-pause"
                                    wire:click="enableMaintenanceMode"
                                    class="w-full btn-warning"
                                />
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Scheduled Maintenance -->
            <div class="mb-6 bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Schedule Maintenance</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="space-y-4">
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Date</label>
                            <input
                                type="date"
                                wire:model="scheduledMaintenanceDate"
                                class="w-full input input-bordered input-sm"
                            />
                        </div>

                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Time</label>
                            <input
                                type="time"
                                wire:model="scheduledMaintenanceTime"
                                class="w-full input input-bordered input-sm"
                            />
                        </div>

                        <x-button
                            label="Schedule Maintenance"
                            icon="o-clock"
                            wire:click="scheduleMaintenanceMode"
                            class="w-full btn-outline"
                        />
                    </div>
                </div>
            </div>

            <!-- System Health -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">System Health</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="space-y-3">
                        @foreach($this->systemHealth['checks'] as $check => $status)
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">{{ ucwords(str_replace('_', ' ', $check)) }}</span>
                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $status ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $status ? 'OK' : 'Failed' }}
                                </span>
                            </div>
                        @endforeach

                        <div class="pt-3 border-t border-gray-200">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-700">Overall Health</span>
                                <span class="text-lg font-semibold {{ $this->systemHealth['percentage'] >= 80 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $this->systemHealth['percentage'] }}%
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Maintenance Tasks -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Maintenance Tasks</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        @foreach($this->maintenanceTasks as $task)
                            <div class="p-4 border border-gray-200 rounded-lg">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <h4 class="mb-1 text-sm font-medium text-gray-900">
                                            {{ $task['name'] }}
                                            @if($task['critical'])
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 ml-2">
                                                    Critical
                                                </span>
                                            @endif
                                        </h4>
                                        <p class="mb-3 text-sm text-gray-500">{{ $task['description'] }}</p>

                                        <x-button
                                            label="Run Task"
                                            icon="{{ $task['icon'] }}"
                                            wire:click="{{ $task['action'] }}"
                                            @if($task['critical'])
                                                wire:confirm="This is a critical operation. Are you sure you want to continue?"
                                            @endif
                                            class="btn-sm {{ $task['color'] }}"
                                        />
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Quick Actions -->
                    <div class="pt-6 mt-8 border-t border-gray-200">
                        <h4 class="mb-4 text-sm font-medium text-gray-900">Quick Actions</h4>
                        <div class="flex flex-wrap gap-2">
                            <x-button
                                label="Clear All Caches"
                                icon="o-trash"
                                wire:click="clearCache"
                                class="btn-sm btn-outline"
                            />
                            <x-button
                                label="Optimize System"
                                icon="o-rocket-launch"
                                wire:click="optimizeApplication"
                                class="btn-sm btn-success"
                            />
                            <x-button
                                label="Generate App Key"
                                icon="o-key"
                                wire:click="generateAppKey"
                                wire:confirm="This will generate a new application key. Continue?"
                                class="btn-sm btn-warning"
                            />
                            <x-button
                                label="Clear Logs"
                                icon="o-document-minus"
                                wire:click="clearLogs"
                                wire:confirm="This will clear all log files. Continue?"
                                class="btn-sm btn-outline"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
