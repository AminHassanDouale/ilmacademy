<?php
// resources/views/livewire/admin/system/status.blade.php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Spatie\Activitylog\Models\Activity;

new #[Title('System Status')] class extends Component {
    use Toast;

    public bool $autoRefresh = false;
    public string $refreshInterval = '30';

    public function mount(): void
    {
        //
    }

    public function getSystemInfoProperty(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'debug_mode' => config('app.debug'),
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
            'url' => config('app.url'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'server_os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ];
    }

    public function getDatabaseStatusProperty(): array
    {
        try {
            $pdo = DB::connection()->getPdo();
            $connected = true;
            $error = null;

            // Get database info
            $dbConfig = config('database.connections.' . config('database.default'));
            $dbName = $dbConfig['database'] ?? 'Unknown';
            $dbHost = $dbConfig['host'] ?? 'Unknown';
            $dbPort = $dbConfig['port'] ?? 'Unknown';

            // Get database size
            $dbSize = $this->getDatabaseSize();

            // Get table count
            $tableCount = count(DB::select('SHOW TABLES'));

        } catch (\Exception $e) {
            $connected = false;
            $error = $e->getMessage();
            $dbName = $dbHost = $dbPort = 'Unknown';
            $dbSize = $tableCount = 0;
        }

        return [
            'connected' => $connected,
            'error' => $error,
            'name' => $dbName,
            'host' => $dbHost,
            'port' => $dbPort,
            'size' => $dbSize,
            'table_count' => $tableCount,
        ];
    }

    public function getCacheStatusProperty(): array
    {
        try {
            $driver = config('cache.default');
            $working = Cache::put('system_status_test', 'test', 60) && Cache::get('system_status_test') === 'test';
            Cache::forget('system_status_test');

            return [
                'driver' => $driver,
                'working' => $working,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'driver' => config('cache.default'),
                'working' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getStorageStatusProperty(): array
    {
        $disks = [];

        foreach (config('filesystems.disks') as $name => $config) {
            try {
                $disk = Storage::disk($name);
                $working = true;
                $error = null;

                // Test write/read
                $testFile = 'system_status_test.txt';
                $disk->put($testFile, 'test');
                $content = $disk->get($testFile);
                $disk->delete($testFile);

                if ($content !== 'test') {
                    $working = false;
                    $error = 'Read/write test failed';
                }

                // Get disk space for local disks
                $freeSpace = null;
                $totalSpace = null;
                if ($config['driver'] === 'local' && isset($config['root'])) {
                    $freeSpace = disk_free_space($config['root']);
                    $totalSpace = disk_total_space($config['root']);
                }

            } catch (\Exception $e) {
                $working = false;
                $error = $e->getMessage();
                $freeSpace = $totalSpace = null;
            }

            $disks[$name] = [
                'driver' => $config['driver'],
                'working' => $working,
                'error' => $error,
                'free_space' => $freeSpace,
                'total_space' => $totalSpace,
            ];
        }

        return $disks;
    }

    public function getQueueStatusProperty(): array
    {
        try {
            $driver = config('queue.default');
            $connection = config("queue.connections.{$driver}");

            // This is a simplified check - in a real application you might want to check queue depths
            $working = true;
            $error = null;

            return [
                'driver' => $driver,
                'working' => $working,
                'error' => $error,
                'connection' => $connection,
            ];
        } catch (\Exception $e) {
            return [
                'driver' => config('queue.default'),
                'working' => false,
                'error' => $e->getMessage(),
                'connection' => null,
            ];
        }
    }

    public function getApplicationStatsProperty(): array
    {
        return [
            'total_users' => \App\Models\User::count(),
            'total_students' => \App\Models\Student::count(),
            'total_teachers' => \App\Models\TeacherProfile::count(),
            'total_subjects' => \App\Models\Subject::count(),
            'total_curricula' => \App\Models\Curriculum::count(),
            'total_timetable_slots' => \App\Models\TimetableSlot::count(),
            'total_payments' => \App\Models\Payment::count(),
            'total_grades' => \App\Models\Grade::count(),
            'recent_logins' => \App\Models\User::where('last_login_at', '>=', now()->subDays(7))->count(),
            'active_sessions' => \App\Models\User::whereNotNull('last_login_at')->where('last_login_at', '>=', now()->subHour())->count(),
        ];
    }

    public function getSystemHealthProperty(): array
    {
        $checks = [
            'database' => $this->databaseStatus['connected'],
            'cache' => $this->cacheStatus['working'],
            'storage' => collect($this->storageStatus)->every(fn($disk) => $disk['working']),
            'queue' => $this->queueStatus['working'],
            'writable_storage' => is_writable(storage_path()),
            'writable_bootstrap_cache' => is_writable(bootstrap_path('cache')),
        ];

        $passing = collect($checks)->filter()->count();
        $total = count($checks);

        return [
            'checks' => $checks,
            'passing' => $passing,
            'total' => $total,
            'percentage' => $total > 0 ? round(($passing / $total) * 100) : 0,
            'status' => $passing === $total ? 'healthy' : ($passing >= $total * 0.8 ? 'warning' : 'critical'),
        ];
    }

    public function getRecentActivityProperty()
    {
        return Activity::with('causer')
            ->latest()
            ->limit(10)
            ->get();
    }

    public function getSecurityStatusProperty(): array
    {
        return [
            'https_enabled' => request()->isSecure(),
            'debug_mode' => config('app.debug'),
            'app_key_set' => !empty(config('app.key')),
            'environment' => app()->environment(),
            'failed_logins_today' => 0, // You'd implement this based on your login tracking
            'suspicious_activity' => 0, // You'd implement this based on your security monitoring
        ];
    }

    public function getDatabaseSize(): int
    {
        try {
            $dbName = config('database.connections.' . config('database.default') . '.database');
            $result = DB::select("
                SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                FROM information_schema.tables
                WHERE table_schema = ?
            ", [$dbName]);

            return $result[0]->size_mb ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function refreshStatus(): void
    {
        // Force refresh all computed properties
        $this->systemInfo;
        $this->databaseStatus;
        $this->cacheStatus;
        $this->storageStatus;
        $this->queueStatus;
        $this->applicationStats;
        $this->systemHealth;

        $this->success('System status refreshed!');
    }

    public function clearCache(): void
    {
        try {
            Cache::flush();

            activity()
                ->causedBy(auth()->user())
                ->log('System cache cleared from status page');

            $this->success('Cache cleared successfully!');
        } catch (\Exception $e) {
            $this->error('Failed to clear cache: ' . $e->getMessage());
        }
    }

    public function clearLogs(): void
    {
        try {
            $logFiles = glob(storage_path('logs/*.log'));
            foreach ($logFiles as $file) {
                if (is_writable($file)) {
                    file_put_contents($file, '');
                }
            }

            activity()
                ->causedBy(auth()->user())
                ->log('System logs cleared');

            $this->success('Logs cleared successfully!');
        } catch (\Exception $e) {
            $this->error('Failed to clear logs: ' . $e->getMessage());
        }
    }

    public function optimizeDatabase(): void
    {
        try {
            // Run database optimization commands
            $tables = DB::select('SHOW TABLES');
            foreach ($tables as $table) {
                $tableName = array_values((array) $table)[0];
                DB::statement("OPTIMIZE TABLE `{$tableName}`");
            }

            activity()
                ->causedBy(auth()->user())
                ->log('Database optimized');

            $this->success('Database optimized successfully!');
        } catch (\Exception $e) {
            $this->error('Failed to optimize database: ' . $e->getMessage());
        }
    }

    public function formatBytes($bytes): string
    {
        if ($bytes === null) return 'Unknown';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    public function getStatusBadgeClass($status): string
    {
        return match($status) {
            'healthy' => 'badge-success',
            'warning' => 'badge-warning',
            'critical' => 'badge-error',
            default => 'badge-neutral'
        };
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="System Status" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-4">
                <div class="flex items-center space-x-2">
                    <input
                        type="checkbox"
                        wire:model.live="autoRefresh"
                        class="checkbox checkbox-primary checkbox-sm"
                    />
                    <span class="text-sm text-gray-600">Auto-refresh</span>
                </div>

                @if($autoRefresh)
                    <select wire:model.live="refreshInterval" class="select select-bordered select-sm">
                        <option value="10">10s</option>
                        <option value="30">30s</option>
                        <option value="60">1m</option>
                        <option value="300">5m</option>
                    </select>
                @endif
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Clear Cache"
                icon="o-trash"
                wire:click="clearCache"
                wire:confirm="Are you sure you want to clear the cache?"
                class="btn-outline"
            />
            <x-button
                label="Refresh Status"
                icon="o-arrow-path"
                wire:click="refreshStatus"
                spinner="refreshStatus"
                class="btn-primary"
            />
        </x-slot:actions>
    </x-header>

    <!-- Auto-refresh script -->
    @if($autoRefresh)
        <script>
            setInterval(() => {
                @this.call('refreshStatus')
            }, {{ $refreshInterval * 1000 }});
        </script>
    @endif

    <!-- System Health Overview -->
    <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-4">
        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 {{ $this->systemHealth['status'] === 'healthy' ? 'bg-green-500' : ($this->systemHealth['status'] === 'warning' ? 'bg-yellow-500' : 'bg-red-500') }} rounded-full">
                        @if($this->systemHealth['status'] === 'healthy')
                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        @elseif($this->systemHealth['status'] === 'warning')
                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        @else
                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        @endif
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">System Health</p>
                    <p class="text-lg font-semibold {{ $this->systemHealth['status'] === 'healthy' ? 'text-green-600' : ($this->systemHealth['status'] === 'warning' ? 'text-yellow-600' : 'text-red-600') }}">
                        {{ $this->systemHealth['percentage'] }}%
                    </p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-blue-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Active Users</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->applicationStats['active_sessions'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-purple-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Database Size</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->databaseStatus['size'] }} MB</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-green-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Checks Passing</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->systemHealth['passing'] }}/{{ $this->systemHealth['total'] }}</p>
                </div>
            </div>
        </x-card>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- System Information -->
        <div class="space-y-6 lg:col-span-2">
            <!-- System Info -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">System Information</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <span class="text-sm font-medium text-gray-700">PHP Version:</span>
                            <span class="ml-2 text-sm text-gray-900">{{ $this->systemInfo['php_version'] }}</span>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-700">Laravel Version:</span>
                            <span class="ml-2 text-sm text-gray-900">{{ $this->systemInfo['laravel_version'] }}</span>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-700">Environment:</span>
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $this->systemInfo['environment'] === 'production' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                {{ ucfirst($this->systemInfo['environment']) }}
                            </span>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-700">Debug Mode:</span>
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $this->systemInfo['debug_mode'] ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                                {{ $this->systemInfo['debug_mode'] ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-700">Timezone:</span>
                            <span class="ml-2 text-sm text-gray-900">{{ $this->systemInfo['timezone'] }}</span>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-700">Server OS:</span>
                            <span class="ml-2 text-sm text-gray-900">{{ $this->systemInfo['server_os'] }}</span>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-700">Memory Limit:</span>
                            <span class="ml-2 text-sm text-gray-900">{{ $this->systemInfo['memory_limit'] }}</span>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-700">Max Upload Size:</span>
                            <span class="ml-2 text-sm text-gray-900">{{ $this->systemInfo['upload_max_filesize'] }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Service Status -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Service Status</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="space-y-4">
                        <!-- Database -->
                        <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-8 h-8 {{ $this->databaseStatus['connected'] ? 'bg-green-500' : 'bg-red-500' }} rounded-full flex items-center justify-center">
                                        @if($this->databaseStatus['connected'])
                                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                            </svg>
                                        @else
                                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                            </svg>
                                        @endif
                                    </div>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-gray-900">Database</h4>
                                    <p class="text-sm text-gray-500">{{ $this->databaseStatus['name'] }} ({{ $this->databaseStatus['table_count'] }} tables)</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $this->databaseStatus['connected'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $this->databaseStatus['connected'] ? 'Connected' : 'Disconnected' }}
                                </span>
                                @if($this->databaseStatus['connected'])
                                    <p class="mt-1 text-xs text-gray-500">{{ $this->databaseStatus['size'] }} MB</p>
                                @endif
                            </div>
                        </div>

                        <!-- Cache -->
                        <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-8 h-8 {{ $this->cacheStatus['working'] ? 'bg-green-500' : 'bg-red-500' }} rounded-full flex items-center justify-center">
                                        @if($this->cacheStatus['working'])
                                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                            </svg>
                                        @else
                                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                            </svg>
                                        @endif
                                    </div>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-gray-900">Cache</h4>
                                    <p class="text-sm text-gray-500">{{ ucfirst($this->cacheStatus['driver']) }} driver</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $this->cacheStatus['working'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $this->cacheStatus['working'] ? 'Working' : 'Error' }}
                                </span>
                            </div>
                        </div>

                        <!-- Queue -->
                        <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <div class="w-8 h-8 {{ $this->queueStatus['working'] ? 'bg-green-500' : 'bg-red-500' }} rounded-full flex items-center justify-center">
                                        @if($this->queueStatus['working'])
                                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                            </svg>
                                        @else
                                            <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                            </svg>
                                        @endif
                                    </div>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-gray-900">Queue</h4>
                                    <p class="text-sm text-gray-500">{{ ucfirst($this->queueStatus['driver']) }} driver</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $this->queueStatus['working'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $this->queueStatus['working'] ? 'Working' : 'Error' }}
                                </span>
                            </div>
                        </div>

                        <!-- Storage -->
                        @foreach($this->storageStatus as $diskName => $disk)
                            <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <div class="w-8 h-8 {{ $disk['working'] ? 'bg-green-500' : 'bg-red-500' }} rounded-full flex items-center justify-center">
                                            @if($disk['working'])
                                                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                                </svg>
                                            @else
                                                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                </svg>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="ml-3">
                                        <h4 class="text-sm font-medium text-gray-900">Storage ({{ $diskName }})</h4>
                                        <p class="text-sm text-gray-500">{{ ucfirst($disk['driver']) }} driver</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $disk['working'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                        {{ $disk['working'] ? 'Working' : 'Error' }}
                                    </span>
                                    @if($disk['free_space'] && $disk['total_space'])
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ $this->formatBytes($disk['free_space']) }} / {{ $this->formatBytes($disk['total_space']) }} free
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Application Statistics -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Application Statistics</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <div class="text-center">
                            <p class="text-2xl font-bold text-blue-600">{{ number_format($this->applicationStats['total_users']) }}</p>
                            <p class="text-sm text-gray-500">Total Users</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-green-600">{{ number_format($this->applicationStats['total_students']) }}</p>
                            <p class="text-sm text-gray-500">Students</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-purple-600">{{ number_format($this->applicationStats['total_teachers']) }}</p>
                            <p class="text-sm text-gray-500">Teachers</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-orange-600">{{ number_format($this->applicationStats['total_subjects']) }}</p>
                            <p class="text-sm text-gray-500">Subjects</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-red-600">{{ number_format($this->applicationStats['total_curricula']) }}</p>
                            <p class="text-sm text-gray-500">Curricula</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-indigo-600">{{ number_format($this->applicationStats['total_timetable_slots']) }}</p>
                            <p class="text-sm text-gray-500">Timetable Slots</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-yellow-600">{{ number_format($this->applicationStats['total_payments']) }}</p>
                            <p class="text-sm text-gray-500">Payments</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-pink-600">{{ number_format($this->applicationStats['total_grades']) }}</p>
                            <p class="text-sm text-gray-500">Grades</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Security Status -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Security Status</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">HTTPS Enabled</span>
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $this->securityStatus['https_enabled'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ $this->securityStatus['https_enabled'] ? 'Yes' : 'No' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">Debug Mode</span>
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $this->securityStatus['debug_mode'] ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                                {{ $this->securityStatus['debug_mode'] ? 'On' : 'Off' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">App Key Set</span>
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $this->securityStatus['app_key_set'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ $this->securityStatus['app_key_set'] ? 'Yes' : 'No' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">Environment</span>
                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $this->securityStatus['environment'] === 'production' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                {{ ucfirst($this->securityStatus['environment']) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Quick Actions</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="space-y-3">
                        <x-button
                            label="Clear Cache"
                            icon="o-trash"
                            wire:click="clearCache"
                            wire:confirm="Are you sure you want to clear the cache?"
                            class="w-full btn-outline"
                        />
                        <x-button
                            label="Clear Logs"
                            icon="o-document-minus"
                            wire:click="clearLogs"
                            wire:confirm="Are you sure you want to clear all logs?"
                            class="w-full btn-outline"
                        />
                        <x-button
                            label="Optimize Database"
                            icon="o-cog-6-tooth"
                            wire:click="optimizeDatabase"
                            wire:confirm="Are you sure you want to optimize the database?"
                            class="w-full btn-outline"
                        />
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Recent Activity</h3>
                </div>
                <div class="px-6 py-6">
                    <div class="space-y-3">
                        @forelse($this->recentActivity as $activity)
                            <div class="flex items-start space-x-3">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center w-6 h-6 bg-blue-100 rounded-full">
                                        <svg class="w-3 h-3 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-gray-900">
                                        <span class="font-medium">{{ $activity->causer->name ?? 'System' }}</span>
                                        {{ $activity->description }}
                                    </p>
                                    <p class="text-xs text-gray-500">{{ $activity->created_at->diffForHumans() }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">No recent activity</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
