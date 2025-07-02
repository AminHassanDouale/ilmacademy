<?php
// app/Services/SystemManagementService.php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SystemManagementService
{
    public static function getSystemHealth(): array
    {
        $checks = [
            'database' => self::checkDatabase(),
            'cache' => self::checkCache(),
            'storage' => self::checkStorage(),
            'queue' => self::checkQueue(),
            'logs' => self::checkLogs(),
            'permissions' => self::checkPermissions(),
            'dependencies' => self::checkDependencies(),
        ];

        $passing = collect($checks)->filter(fn($check) => $check['status'])->count();
        $total = count($checks);

        return [
            'checks' => $checks,
            'passing' => $passing,
            'total' => $total,
            'percentage' => $total > 0 ? round(($passing / $total) * 100) : 0,
            'status' => $passing === $total ? 'healthy' : ($passing >= $total * 0.8 ? 'warning' : 'critical'),
        ];
    }

    public static function checkDatabase(): array
    {
        try {
            $pdo = DB::connection()->getPdo();
            $version = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

            // Test a simple query
            DB::select('SELECT 1');

            return [
                'status' => true,
                'message' => "Connected (Version: {$version})",
                'details' => [
                    'driver' => config('database.default'),
                    'version' => $version,
                    'connection_name' => config('database.default'),
                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Database connection failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public static function checkCache(): array
    {
        try {
            $testKey = 'system_health_test_' . time();
            $testValue = 'test_value';

            Cache::put($testKey, $testValue, 60);
            $retrieved = Cache::get($testKey);
            Cache::forget($testKey);

            $working = $retrieved === $testValue;

            return [
                'status' => $working,
                'message' => $working ? 'Cache is working' : 'Cache test failed',
                'details' => [
                    'driver' => config('cache.default'),
                    'store' => config('cache.stores.' . config('cache.default')),
                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Cache test failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public static function checkStorage(): array
    {
        $issues = [];
        $paths = [
            'storage' => storage_path(),
            'bootstrap/cache' => bootstrap_path('cache'),
            'storage/logs' => storage_path('logs'),
            'storage/framework' => storage_path('framework'),
            'storage/app' => storage_path('app'),
        ];

        foreach ($paths as $name => $path) {
            if (!File::exists($path)) {
                $issues[] = "{$name} directory does not exist";
            } elseif (!is_writable($path)) {
                $issues[] = "{$name} directory is not writable";
            }
        }

        return [
            'status' => empty($issues),
            'message' => empty($issues) ? 'All storage paths are writable' : 'Storage issues detected',
            'issues' => $issues,
        ];
    }

    public static function checkQueue(): array
    {
        try {
            $driver = config('queue.default');
            $connection = config("queue.connections.{$driver}");

            // For database queues, check if jobs table exists
            if ($driver === 'database') {
                $hasJobsTable = DB::getSchemaBuilder()->hasTable('jobs');
                if (!$hasJobsTable) {
                    return [
                        'status' => false,
                        'message' => 'Jobs table does not exist',
                        'details' => ['driver' => $driver],
                    ];
                }
            }

            return [
                'status' => true,
                'message' => 'Queue configuration is valid',
                'details' => [
                    'driver' => $driver,
                    'connection' => $connection,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Queue check failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public static function checkLogs(): array
    {
        $logPath = storage_path('logs');
        $issues = [];

        if (!File::exists($logPath)) {
            $issues[] = 'Logs directory does not exist';
        } elseif (!is_writable($logPath)) {
            $issues[] = 'Logs directory is not writable';
        }

        // Check log file sizes
        if (File::exists($logPath)) {
            $logFiles = File::files($logPath);
            $largeFiles = [];

            foreach ($logFiles as $file) {
                if ($file->getSize() > 100 * 1024 * 1024) { // 100MB
                    $largeFiles[] = $file->getFilename();
                }
            }

            if (!empty($largeFiles)) {
                $issues[] = 'Large log files detected: ' . implode(', ', $largeFiles);
            }
        }

        return [
            'status' => empty($issues),
            'message' => empty($issues) ? 'Logging system is healthy' : 'Logging issues detected',
            'issues' => $issues,
        ];
    }

    public static function checkPermissions(): array
    {
        $issues = [];

        // Check if we can write to critical directories
        $testPaths = [
            storage_path('app'),
            storage_path('logs'),
            bootstrap_path('cache'),
        ];

        foreach ($testPaths as $path) {
            $testFile = $path . '/permission_test_' . time() . '.tmp';

            try {
                File::put($testFile, 'test');
                File::delete($testFile);
            } catch (\Exception $e) {
                $issues[] = "Cannot write to {$path}";
            }
        }

        return [
            'status' => empty($issues),
            'message' => empty($issues) ? 'File permissions are correct' : 'Permission issues detected',
            'issues' => $issues,
        ];
    }

    public static function checkDependencies(): array
    {
        $issues = [];

        // Check PHP version
        $minPhpVersion = '8.1.0';
        if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
            $issues[] = "PHP version {$minPhpVersion} or higher required";
        }

        // Check required PHP extensions
        $requiredExtensions = ['pdo', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json'];
        foreach ($requiredExtensions as $extension) {
            if (!extension_loaded($extension)) {
                $issues[] = "Required PHP extension missing: {$extension}";
            }
        }

        // Check if APP_KEY is set
        if (empty(config('app.key'))) {
            $issues[] = 'Application key is not set';
        }

        return [
            'status' => empty($issues),
            'message' => empty($issues) ? 'All dependencies are satisfied' : 'Dependency issues detected',
            'issues' => $issues,
        ];
    }

    public static function performMaintenance(array $tasks = []): array
    {
        $results = [];

        foreach ($tasks as $task) {
            try {
                $result = match($task) {
                    'clear_cache' => self::clearAllCaches(),
                    'optimize' => self::optimizeApplication(),
                    'clear_logs' => self::clearLogs(),
                    'optimize_db' => self::optimizeDatabase(),
                    'clear_sessions' => self::clearSessions(),
                    default => ['status' => false, 'message' => 'Unknown task']
                };

                $results[$task] = $result;
            } catch (\Exception $e) {
                $results[$task] = [
                    'status' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public static function clearAllCaches(): array
    {
        try {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');

            return [
                'status' => true,
                'message' => 'All caches cleared successfully',
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Failed to clear caches: ' . $e->getMessage(),
            ];
        }
    }

    public static function optimizeApplication(): array
    {
        try {
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            Artisan::call('view:cache');

            return [
                'status' => true,
                'message' => 'Application optimized successfully',
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Failed to optimize application: ' . $e->getMessage(),
            ];
        }
    }

    public static function clearLogs(): array
    {
        try {
            $logPath = storage_path('logs');
            $files = File::files($logPath);
            $cleared = 0;

            foreach ($files as $file) {
                if ($file->getExtension() === 'log') {
                    File::put($file->getPathname(), '');
                    $cleared++;
                }
            }

            return [
                'status' => true,
                'message' => "Cleared {$cleared} log files",
                'files_cleared' => $cleared,
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Failed to clear logs: ' . $e->getMessage(),
            ];
        }
    }

    public static function optimizeDatabase(): array
    {
        try {
            $tables = DB::select('SHOW TABLES');
            $optimized = 0;

            foreach ($tables as $table) {
                $tableName = array_values((array) $table)[0];
                DB::statement("OPTIMIZE TABLE `{$tableName}`");
                $optimized++;
            }

            return [
                'status' => true,
                'message' => "Optimized {$optimized} database tables",
                'tables_optimized' => $optimized,
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Failed to optimize database: ' . $e->getMessage(),
            ];
        }
    }

    public static function clearSessions(): array
    {
        try {
            Artisan::call('session:clear');

            return [
                'status' => true,
                'message' => 'Sessions cleared successfully',
            ];
        } catch (\Exception $e) {
            return [
                'status' => false,
                'message' => 'Failed to clear sessions: ' . $e->getMessage(),
            ];
        }
    }

    public static function getSystemMetrics(): array
    {
        return [
            'memory_usage' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => self::parseBytes(ini_get('memory_limit')),
            ],
            'disk_usage' => [
                'free' => disk_free_space(storage_path()),
                'total' => disk_total_space(storage_path()),
            ],
            'database_size' => self::getDatabaseSize(),
            'cache_size' => self::getCacheSize(),
            'log_size' => self::getLogSize(),
        ];
    }

    public static function getDatabaseSize(): int
    {
        try {
            $dbName = config('database.connections.' . config('database.default') . '.database');
            $result = DB::select("
                SELECT ROUND(SUM(data_length + index_length)) AS size_bytes
                FROM information_schema.tables
                WHERE table_schema = ?
            ", [$dbName]);

            return $result[0]->size_bytes ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public static function getCacheSize(): int
    {
        // This is a simplified implementation
        // Actual implementation would depend on cache driver
        return 0;
    }

    public static function getLogSize(): int
    {
        try {
            $logPath = storage_path('logs');
            $size = 0;

            if (File::exists($logPath)) {
                $files = File::allFiles($logPath);
                foreach ($files as $file) {
                    $size += $file->getSize();
                }
            }

            return $size;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public static function parseBytes(string $val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $val = (int) $val;

        switch ($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }

        return $val;
    }

    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}