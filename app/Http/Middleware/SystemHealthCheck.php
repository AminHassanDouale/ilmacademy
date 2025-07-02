<?php
// app/Http/Middleware/SystemHealthCheck.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\SystemManagementService;
use Illuminate\Support\Facades\Cache;

class SystemHealthCheck
{
    public function handle(Request $request, Closure $next)
    {
        // Only run health checks for admin users and non-AJAX requests
        if (!$request->user()?->hasRole('admin') || $request->ajax()) {
            return $next($request);
        }

        // Cache health check results for 5 minutes
        $healthKey = 'system_health_admin_' . $request->user()->id;
        $health = Cache::remember($healthKey, 300, function () {
            return SystemManagementService::getSystemHealth();
        });

        // Add health status to view data
        view()->share('systemHealth', $health);

        // Add critical alerts to session
        if ($health['status'] === 'critical') {
            $criticalIssues = collect($health['checks'])
                ->filter(fn($check) => !$check['status'])
                ->map(fn($check) => $check['message'])
                ->toArray();

            session()->flash('system_alerts', [
                'type' => 'critical',
                'message' => 'Critical system issues detected',
                'issues' => $criticalIssues,
            ]);
        }

        return $next($request);
    }
}

// app/Notifications/SystemMaintenanceNotification.php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;

class SystemMaintenanceNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $type,
        public string $title,
        public string $message,
        public array $details = []
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting('System Notification')
            ->line($this->message);

        if (!empty($this->details)) {
            $mail->line('Details:');
            foreach ($this->details as $detail) {
                $mail->line("• {$detail}");
            }
        }

        $mail->line('Please check the admin panel for more information.');

        return $mail;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'details' => $this->details,
            'timestamp' => now(),
        ];
    }
}

// app/Jobs/SystemHealthCheckJob.php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\SystemManagementService;
use App\Models\User;
use App\Notifications\SystemMaintenanceNotification;

class SystemHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $health = SystemManagementService::getSystemHealth();

        // Store health status in cache
        cache()->put('system_health_status', $health, now()->addMinutes(10));

        // Notify admins if system is in critical state
        if ($health['status'] === 'critical') {
            $failedChecks = collect($health['checks'])
                ->filter(fn($check) => !$check['status'])
                ->keys()
                ->map(fn($key) => ucwords(str_replace('_', ' ', $key)))
                ->toArray();

            $admins = User::whereHas('roles', function($q) {
                $q->where('name', 'admin');
            })->get();

            foreach ($admins as $admin) {
                $admin->notify(new SystemMaintenanceNotification(
                    'critical',
                    'Critical System Health Alert',
                    'System health checks have detected critical issues that require immediate attention.',
                    $failedChecks
                ));
            }
        }
    }
}

// app/Jobs/AutoBackupJob.php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Notifications\SystemMaintenanceNotification;

class AutoBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $settings = cache()->get('backup_settings', []);

        if (!($settings['auto_backup_enabled'] ?? false)) {
            return;
        }

        try {
            $timestamp = now()->format('Y_m_d_H_i_s');
            $backupName = "auto_backup_{$timestamp}";

            $this->createBackup($backupName);

            // Clean up old backups
            $this->cleanupOldBackups($settings['retention_days'] ?? 30);

            // Notify admins of successful backup
            $admins = User::whereHas('roles', function($q) {
                $q->where('name', 'admin');
            })->get();

            foreach ($admins as $admin) {
                $admin->notify(new SystemMaintenanceNotification(
                    'success',
                    'Automatic Backup Completed',
                    "Automatic backup '{$backupName}' has been created successfully.",
                    ["Backup created at: " . now()->format('Y-m-d H:i:s')]
                ));
            }

        } catch (\Exception $e) {
            // Notify admins of backup failure
            $admins = User::whereHas('roles', function($q) {
                $q->where('name', 'admin');
            })->get();

            foreach ($admins as $admin) {
                $admin->notify(new SystemMaintenanceNotification(
                    'error',
                    'Automatic Backup Failed',
                    'The scheduled automatic backup has failed.',
                    ["Error: " . $e->getMessage()]
                ));
            }
        }
    }

    protected function createBackup(string $name): void
    {
        $backupPath = storage_path('app/backups');
        if (!File::exists($backupPath)) {
            File::makeDirectory($backupPath, 0755, true);
        }

        // Create database backup
        $this->createDatabaseBackup($name);

        // Create files backup
        $this->createFilesBackup($name);
    }

    protected function createDatabaseBackup(string $name): void
    {
        $database = config('database.connections.' . config('database.default'));
        $filename = "{$name}_db.sql";
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
    }

    protected function createFilesBackup(string $name): void
    {
        $filename = "{$name}_files.zip";
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

    protected function cleanupOldBackups(int $retentionDays): void
    {
        $backupPath = storage_path('app/backups');
        if (!File::exists($backupPath)) {
            return;
        }

        $files = File::files($backupPath);
        $cutoffDate = now()->subDays($retentionDays)->timestamp;

        foreach ($files as $file) {
            if ($file->getMTime() < $cutoffDate) {
                File::delete($file->getPathname());
            }
        }
    }
}

// app/Console/Kernel.php - Add to schedule method

protected function schedule(Schedule $schedule)
{
    // System health checks every 15 minutes
    $schedule->job(new SystemHealthCheckJob())->everyFifteenMinutes();

    // Auto backups based on settings
    $schedule->call(function () {
        $settings = cache()->get('backup_settings', []);

        if ($settings['auto_backup_enabled'] ?? false) {
            dispatch(new AutoBackupJob());
        }
    })->when(function () {
        $settings = cache()->get('backup_settings', []);
        $schedule = $settings['auto_backup_schedule'] ?? 'daily';

        return match($schedule) {
            'daily' => now()->hour === 2, // Run at 2 AM daily
            'weekly' => now()->dayOfWeek === 0 && now()->hour === 2, // Sunday 2 AM
            'monthly' => now()->day === 1 && now()->hour === 2, // 1st of month 2 AM
            default => false
        };
    });

    // Clean up old logs weekly
    $schedule->call(function () {
        $logPath = storage_path('logs');
        $files = File::files($logPath);

        foreach ($files as $file) {
            if ($file->getExtension() === 'log' && $file->getMTime() < now()->subDays(30)->timestamp) {
                File::delete($file->getPathname());
            }
        }
    })->weekly();

    // Database optimization monthly
    $schedule->call(function () {
        try {
            $tables = DB::select('SHOW TABLES');
            foreach ($tables as $table) {
                $tableName = array_values((array) $table)[0];
                DB::statement("OPTIMIZE TABLE `{$tableName}`");
            }
        } catch (\Exception $e) {
            \Log::error('Database optimization failed: ' . $e->getMessage());
        }
    })->monthly();
}

// resources/views/components/system-health-alert.blade.php

@if(isset($systemHealth) && $systemHealth['status'] !== 'healthy')
    <div class="alert alert-{{ $systemHealth['status'] === 'critical' ? 'error' : 'warning' }} mb-4">
        <div class="flex items-center">
            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                @if($systemHealth['status'] === 'critical')
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                @else
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                @endif
            </svg>
            <div>
                <strong>System Health Alert:</strong> {{ $systemHealth['status'] === 'critical' ? 'Critical issues detected' : 'Some issues detected' }}
                ({{ $systemHealth['passing'] }}/{{ $systemHealth['total'] }} checks passing)
                <a href="{{ route('admin.system.status') }}" class="ml-2 underline">View Details</a>
            </div>
        </div>
    </div>
@endif

// app/Providers/SystemManagementServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use App\Jobs\SystemHealthCheckJob;
use App\Jobs\AutoBackupJob;

class SystemManagementServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('system-management', function () {
            return new \App\Services\SystemManagementService();
        });
    }

    public function boot()
    {
        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\SystemHealthCommand::class,
                \App\Console\Commands\SystemMaintenanceCommand::class,
                \App\Console\Commands\SystemBackupCommand::class,
                \App\Console\Commands\SystemMetricsCommand::class,
            ]);
        }

        // Register middleware
        $this->app['router']->aliasMiddleware('system.health', \App\Http\Middleware\SystemHealthCheck::class);
    }
}
