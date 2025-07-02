<?php
// resources/views/livewire/admin/system/logs.blade.php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('System Logs')] class extends Component {
    use Toast, WithPagination;

    public string $selectedLog = '';
    public string $logLevel = '';
    public string $searchTerm = '';
    public string $dateFilter = '';
    public bool $autoRefresh = false;
    public int $refreshInterval = 30;
    public int $maxLines = 1000;

    public function mount(): void
    {
        $logs = $this->getAvailableLogs();
        if (!empty($logs)) {
            $this->selectedLog = $logs[0]['filename'];
        }
    }

    public function getAvailableLogsProperty(): array
    {
        $logPath = storage_path('logs');
        $logs = [];

        if (File::exists($logPath)) {
            $files = File::files($logPath);
            foreach ($files as $file) {
                if ($file->getExtension() === 'log') {
                    $logs[] = [
                        'filename' => $file->getFilename(),
                        'path' => $file->getPathname(),
                        'size' => $file->getSize(),
                        'modified' => $file->getMTime(),
                        'readable_size' => $this->formatBytes($file->getSize()),
                        'modified_human' => date('M d, Y H:i', $file->getMTime()),
                    ];
                }
            }
        }

        // Sort by modification time (newest first)
        usort($logs, fn($a, $b) => $b['modified'] <=> $a['modified']);

        return $logs;
    }

    public function getLogEntriesProperty(): array
    {
        if (!$this->selectedLog) {
            return [];
        }

        $logPath = storage_path('logs/' . $this->selectedLog);

        if (!File::exists($logPath)) {
            return [];
        }

        try {
            $content = File::get($logPath);
            $lines = explode("\n", $content);
            $entries = [];
            $currentEntry = null;

            foreach ($lines as $line) {
                if (empty(trim($line))) continue;

                // Check if this is a new log entry (starts with date)
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.+)/', $line, $matches)) {
                    // Save previous entry if exists
                    if ($currentEntry) {
                        $entries[] = $currentEntry;
                    }

                    // Start new entry
                    $currentEntry = [
                        'timestamp' => $matches[1],
                        'environment' => $matches[2],
                        'level' => strtoupper($matches[3]),
                        'message' => $matches[4],
                        'context' => '',
                        'parsed_timestamp' => \Carbon\Carbon::parse($matches[1]),
                    ];
                } else {
                    // This is a continuation of the previous entry
                    if ($currentEntry) {
                        $currentEntry['context'] .= "\n" . $line;
                    }
                }
            }

            // Add the last entry
            if ($currentEntry) {
                $entries[] = $currentEntry;
            }

            // Apply filters
            $entries = $this->applyFilters($entries);

            // Reverse to show newest first and limit
            $entries = array_reverse($entries);
            return array_slice($entries, 0, $this->maxLines);

        } catch (\Exception $e) {
            $this->error('Failed to read log file: ' . $e->getMessage());
            return [];
        }
    }

    public function applyFilters(array $entries): array
    {
        return array_filter($entries, function ($entry) {
            // Level filter
            if ($this->logLevel && $entry['level'] !== $this->logLevel) {
                return false;
            }

            // Search term filter
            if ($this->searchTerm) {
                $searchIn = $entry['message'] . ' ' . $entry['context'];
                if (stripos($searchIn, $this->searchTerm) === false) {
                    return false;
                }
            }

            // Date filter
            if ($this->dateFilter) {
                $entryDate = $entry['parsed_timestamp']->format('Y-m-d');
                if ($entryDate !== $this->dateFilter) {
                    return false;
                }
            }

            return true;
        });
    }

    public function downloadLog(): void
    {
        if (!$this->selectedLog) {
            $this->error('No log file selected.');
            return;
        }

        $logPath = storage_path('logs/' . $this->selectedLog);

        if (!File::exists($logPath)) {
            $this->error('Log file not found.');
            return;
        }

        return response()->download($logPath);
    }

    public function clearLog(): void
    {
        if (!$this->selectedLog) {
            $this->error('No log file selected.');
            return;
        }

        $logPath = storage_path('logs/' . $this->selectedLog);

        if (!File::exists($logPath)) {
            $this->error('Log file not found.');
            return;
        }

        try {
            File::put($logPath, '');

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['log_file' => $this->selectedLog])
                ->log('Log file cleared');

            $this->success('Log file cleared successfully!');

        } catch (\Exception $e) {
            $this->error('Failed to clear log file: ' . $e->getMessage());
        }
    }

    public function deleteLog(): void
    {
        if (!$this->selectedLog) {
            $this->error('No log file selected.');
            return;
        }

        $logPath = storage_path('logs/' . $this->selectedLog);

        if (!File::exists($logPath)) {
            $this->error('Log file not found.');
            return;
        }

        try {
            File::delete($logPath);

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['log_file' => $this->selectedLog])
                ->log('Log file deleted');

            $this->success('Log file deleted successfully!');

            // Select the next available log
            $logs = $this->availableLogs;
            $this->selectedLog = !empty($logs) ? $logs[0]['filename'] : '';

        } catch (\Exception $e) {
            $this->error('Failed to delete log file: ' . $e->getMessage());
        }
    }

    public function clearAllLogs(): void
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

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['files_cleared' => $cleared])
                ->log('All log files cleared');

            $this->success("Cleared {$cleared} log files successfully!");

        } catch (\Exception $e) {
            $this->error('Failed to clear log files: ' . $e->getMessage());
        }
    }

    public function refreshLogs(): void
    {
        $this->resetPage();
        $this->success('Logs refreshed!');
    }

    public function getLevelColorClass(string $level): string
    {
        return match($level) {
            'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR' => 'bg-red-100 text-red-800',
            'WARNING' => 'bg-yellow-100 text-yellow-800',
            'NOTICE', 'INFO' => 'bg-blue-100 text-blue-800',
            'DEBUG' => 'bg-gray-100 text-gray-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    public function getLevelIcon(string $level): string
    {
        return match($level) {
            'EMERGENCY', 'ALERT', 'CRITICAL' => 'o-exclamation-triangle',
            'ERROR' => 'o-x-circle',
            'WARNING' => 'o-exclamation-circle',
            'NOTICE', 'INFO' => 'o-information-circle',
            'DEBUG' => 'o-bug-ant',
            default => 'o-document-text',
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

    public function updatedSelectedLog(): void
    {
        $this->resetPage();
    }

    public function updatedLogLevel(): void
    {
        $this->resetPage();
    }

    public function updatedSearchTerm(): void
    {
        $this->resetPage();
    }

    public function updatedDateFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="System Logs" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-4">
                <!-- Log File Selection -->
                <div class="flex-1 max-w-xs">
                    <select wire:model.live="selectedLog" class="w-full select select-bordered select-sm">
                        <option value="">Select log file...</option>
                        @foreach($this->availableLogs as $log)
                            <option value="{{ $log['filename'] }}">
                                {{ $log['filename'] }} ({{ $log['readable_size'] }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Auto-refresh toggle -->
                <div class="flex items-center space-x-2">
                    <input
                        type="checkbox"
                        wire:model.live="autoRefresh"
                        class="checkbox checkbox-primary checkbox-sm"
                    />
                    <span class="text-sm text-gray-600">Auto-refresh</span>
                </div>
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Clear All"
                icon="o-trash"
                wire:click="clearAllLogs"
                wire:confirm="Are you sure you want to clear all log files?"
                class="btn-outline btn-error btn-sm"
            />
            <x-button
                label="Download"
                icon="o-arrow-down-tray"
                wire:click="downloadLog"
                class="btn-outline btn-sm"
            />
            <x-button
                label="Refresh"
                icon="o-arrow-path"
                wire:click="refreshLogs"
                class="btn-primary btn-sm"
            />
        </x-slot:actions>
    </x-header>

    <!-- Auto-refresh script -->
    @if($autoRefresh && $selectedLog)
        <script>
            setInterval(() => {
                @this.call('refreshLogs')
            }, {{ $refreshInterval * 1000 }});
        </script>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <!-- Log Files Sidebar -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow">
                <div class="px-4 py-3 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Log Files</h3>
                </div>
                <div class="p-4">
                    <div class="space-y-2">
                        @forelse($this->availableLogs as $log)
                            <div class="p-3 border border-gray-200 rounded-lg cursor-pointer transition-colors hover:bg-gray-50 {{ $selectedLog === $log['filename'] ? 'bg-blue-50 border-blue-200' : '' }}"
                                 wire:click="$set('selectedLog', '{{ $log['filename'] }}')">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-sm font-medium text-gray-900 truncate">
                                            {{ $log['filename'] }}
                                        </h4>
                                        <p class="text-xs text-gray-500">
                                            {{ $log['readable_size'] }}
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            {{ $log['modified_human'] }}
                                        </p>
                                    </div>
                                    @if($selectedLog === $log['filename'])
                                        <div class="flex space-x-1">
                                            <x-button
                                                icon="o-trash"
                                                wire:click.stop="clearLog"
                                                wire:confirm="Are you sure you want to clear this log file?"
                                                tooltip="Clear"
                                                class="text-yellow-600 btn-xs btn-ghost"
                                            />
                                            <x-button
                                                icon="o-x-mark"
                                                wire:click.stop="deleteLog"
                                                wire:confirm="Are you sure you want to delete this log file?"
                                                tooltip="Delete"
                                                class="text-red-600 btn-xs btn-ghost"
                                            />
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="py-4 text-sm text-center text-gray-500">No log files found</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <!-- Log Content -->
        <div class="lg:col-span-3">
            <div class="bg-white rounded-lg shadow">
                <!-- Filters -->
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <!-- Level Filter -->
                        <div>
                            <select wire:model.live="logLevel" class="w-full select select-bordered select-sm">
                                <option value="">All Levels</option>
                                <option value="EMERGENCY">Emergency</option>
                                <option value="ALERT">Alert</option>
                                <option value="CRITICAL">Critical</option>
                                <option value="ERROR">Error</option>
                                <option value="WARNING">Warning</option>
                                <option value="NOTICE">Notice</option>
                                <option value="INFO">Info</option>
                                <option value="DEBUG">Debug</option>
                            </select>
                        </div>

                        <!-- Search -->
                        <div>
                            <input
                                type="text"
                                wire:model.live.debounce.300ms="searchTerm"
                                placeholder="Search logs..."
                                class="w-full input input-bordered input-sm"
                            />
                        </div>

                        <!-- Date Filter -->
                        <div>
                            <input
                                type="date"
                                wire:model.live="dateFilter"
                                class="w-full input input-bordered input-sm"
                            />
                        </div>

                        <!-- Max Lines -->
                        <div>
                            <select wire:model.live="maxLines" class="w-full select select-bordered select-sm">
                                <option value="100">100 lines</option>
                                <option value="500">500 lines</option>
                                <option value="1000">1000 lines</option>
                                <option value="2000">2000 lines</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Log Entries -->
                <div class="px-6 py-6">
                    @if($selectedLog)
                        <div class="max-h-screen space-y-2 overflow-y-auto">
                            @forelse($this->logEntries as $entry)
                                <div class="p-4 border border-gray-200 rounded-lg">
                                    <div class="flex items-start space-x-3">
                                        <div class="flex-shrink-0">
                                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full {{ $this->getLevelColorClass($entry['level']) }}">
                                                <x-icon name="{{ $this->getLevelIcon($entry['level']) }}" class="w-3 h-3 mr-1" />
                                                {{ $entry['level'] }}
                                            </span>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between">
                                                <p class="text-sm font-medium text-gray-900">
                                                    {{ $entry['message'] }}
                                                </p>
                                                <p class="text-xs text-gray-500">
                                                    {{ $entry['parsed_timestamp']->format('M d, H:i:s') }}
                                                </p>
                                            </div>
                                            @if(trim($entry['context']))
                                                <div class="mt-2">
                                                    <pre class="p-2 font-mono text-xs text-gray-600 whitespace-pre-wrap rounded bg-gray-50">{{ trim($entry['context']) }}</pre>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="py-12 text-center">
                                    <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    <h3 class="mt-2 text-sm font-medium text-gray-900">No log entries found</h3>
                                    <p class="mt-1 text-sm text-gray-500">
                                        @if($logLevel || $searchTerm || $dateFilter)
                                            Try adjusting your filter criteria.
                                        @else
                                            This log file appears to be empty.
                                        @endif
                                    </p>
                                </div>
                            @endforelse
                        </div>
                    @else
                        <div class="py-12 text-center">
                            <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">Select a log file</h3>
                            <p class="mt-1 text-sm text-gray-500">Choose a log file from the sidebar to view its contents.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
