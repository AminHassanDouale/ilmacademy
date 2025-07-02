<?php
// app/Console/Commands/SystemHealthCommand.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SystemManagementService;

class SystemHealthCommand extends Command
{
    protected $signature = 'system:health {--format=table : Output format (table, json)}';
    protected $description = 'Check system health status';

    public function handle()
    {
        $this->info('Checking system health...');

        $health = SystemManagementService::getSystemHealth();

        if ($this->option('format') === 'json') {
            $this->line(json_encode($health, JSON_PRETTY_PRINT));
            return;
        }

        // Table format
        $headers = ['Component', 'Status', 'Message'];
        $rows = [];

        foreach ($health['checks'] as $name => $check) {
            $rows[] = [
                ucwords(str_replace('_', ' ', $name)),
                $check['status'] ? '✅ OK' : '❌ FAIL',
                $check['message']
            ];
        }

        $this->table($headers, $rows);

        $statusColor = match($health['status']) {
            'healthy' => 'info',
            'warning' => 'warn',
            'critical' => 'error',
        };

        $this->{$statusColor}("Overall Health: {$health['percentage']}% ({$health['passing']}/{$health['total']} checks passing)");

        return $health['status'] === 'healthy' ? 0 : 1;
    }
}

// app/Console/Commands/SystemMaintenanceCommand.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SystemManagementService;

class SystemMaintenanceCommand extends Command
{
    protected $signature = 'system:maintenance
                            {--tasks=* : Maintenance tasks to run}
                            {--all : Run all maintenance tasks}';

    protected $description = 'Run system maintenance tasks';

    public function handle()
    {
        $availableTasks = [
            'clear_cache' => 'Clear all caches',
            'optimize' => 'Optimize application',
            'clear_logs' => 'Clear log files',
            'optimize_db' => 'Optimize database',
            'clear_sessions' => 'Clear sessions',
        ];

        $tasks = $this->option('tasks');

        if ($this->option('all')) {
            $tasks = array_keys($availableTasks);
        }

        if (empty($tasks)) {
            $this->error('No tasks specified. Use --tasks or --all option.');
            $this->info('Available tasks:');
            foreach ($availableTasks as $task => $description) {
                $this->line("  {$task}: {$description}");
            }
            return 1;
        }

        $this->info('Running maintenance tasks...');
        $results = SystemManagementService::performMaintenance($tasks);

        foreach ($results as $task => $result) {
            $status = $result['status'] ? '✅' : '❌';
            $this->line("{$status} {$task}: {$result['message']}");
        }

        $successful = collect($results)->where('status', true)->count();
        $total = count($results);

        $this->info("Completed {$successful}/{$total} tasks successfully.");

        return $successful === $total ? 0 : 1;
    }
}

// app/Console/Commands/SystemBackupCommand.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class SystemBackupCommand extends Command
{
    protected $signature = 'system:backup
                            {--database : Include database}
                            {--files : Include files}
                            {--name= : Backup name}';

    protected $description = 'Create system backup';

    public function handle()
    {
        $includeDatabase = $this->option('database');
        $includeFiles = $this->option('files');
        $name = $this->option('name') ?: 'backup_' . now()->format('Y_m_d_H_i_s');

        if (!$includeDatabase && !$includeFiles) {
            $includeDatabase = $this->confirm('Include database?', true);
            $includeFiles = $this->confirm('Include files?', true);
        }

        $this->info("Creating backup: {$name}");

        $backupPath = storage_path('app/backups');
        if (!File::exists($backupPath)) {
            File::makeDirectory($backupPath, 0755, true);
        }

        if ($includeDatabase) {
            $this->info('Backing up database...');
            $this->createDatabaseBackup($name);
        }

        if ($includeFiles) {
            $this->info('Backing up files...');
            $this->createFilesBackup($name);
        }

        $this->info('Backup completed successfully!');

        return 0;
    }

    protected function createDatabaseBackup(string $name): void
    {
        $database = config('database.connections.' . config('database.default'));
        $filename = "{$name}_db_" . now()->format('Y_m_d_H_i_s') . ".sql";
        $filepath = storage_path('app/backups/' . $filename);

        $command = sprintf(
            'mysqldump --user=%s --password=%s --host=%s --single-transaction --routines --triggers %s > %s',
            escapeshellarg($database['username']),
            escapeshellarg($database['password']),
            escapeshellarg($database['host']),
            escapeshellarg($database['database']),
            escapeshellarg($filepath)
        );

        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new \Exception('Database backup failed');
        }

        $this->line("Database backup saved: {$filename}");
    }

    protected function createFilesBackup(string $name): void
    {
        $filename = "{$name}_files_" . now()->format('Y_m_d_H_i_s') . ".zip";
        $filepath = storage_path('app/backups/' . $filename);

        $zip = new \ZipArchive();
        if ($zip->open($filepath, \ZipArchive::CREATE) !== TRUE) {
            throw new \Exception('Cannot create zip file');
        }

        $this->addDirectoryToZip($zip, app_path(), 'app/');
        $this->addDirectoryToZip($zip, config_path(), 'config/');
        $this->addDirectoryToZip($zip, database_path(), 'database/');
        $this->addDirectoryToZip($zip, resource_path(), 'resources/');

        $zip->close();

        $this->line("Files backup saved: {$filename}");
    }

    protected function addDirectoryToZip(\ZipArchive $zip, string $directory, string $zipPath = ''): void
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
}

// app/Console/Commands/SystemMetricsCommand.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SystemManagementService;

class SystemMetricsCommand extends Command
{
    protected $signature = 'system:metrics {--format=table : Output format (table, json)}';
    protected $description = 'Display system metrics';

    public function handle()
    {
        $metrics = SystemManagementService::getSystemMetrics();

        if ($this->option('format') === 'json') {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT));
            return;
        }

        $this->info('System Metrics');
        $this->line('');

        // Memory usage
        $this->info('Memory Usage:');
        $this->line("  Current: " . SystemManagementService::formatBytes($metrics['memory_usage']['current']));
        $this->line("  Peak: " . SystemManagementService::formatBytes($metrics['memory_usage']['peak']));
        $this->line("  Limit: " . SystemManagementService::formatBytes($metrics['memory_usage']['limit']));
        $this->line('');

        // Disk usage
        $this->info('Disk Usage:');
        $this->line("  Free: " . SystemManagementService::formatBytes($metrics['disk_usage']['free']));
        $this->line("  Total: " . SystemManagementService::formatBytes($metrics['disk_usage']['total']));
        $usedPercentage = round((($metrics['disk_usage']['total'] - $metrics['disk_usage']['free']) / $metrics['disk_usage']['total']) * 100, 2);
        $this->line("  Used: {$usedPercentage}%");
        $this->line('');

        // Database size
        $this->info('Storage:');
        $this->line("  Database: " . SystemManagementService::formatBytes($metrics['database_size']));
        $this->line("  Logs: " . SystemManagementService::formatBytes($metrics['log_size']));

        return 0;
    }
}