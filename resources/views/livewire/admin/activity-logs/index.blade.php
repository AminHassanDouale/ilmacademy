<?php

use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Activity Logs')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $userFilter = '';

    #[Url]
    public string $actionFilter = '';

    #[Url]
    public string $dateFilter = '';

    #[Url]
    public string $subjectTypeFilter = '';

    #[Url]
    public int $perPage = 25;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Modal states
    public bool $showDetailsModal = false;
    public bool $showClearModal = false;
    public ?ActivityLog $selectedLog = null;

    // Stats
    public array $stats = [];

    // Filter options
    public array $userOptions = [];
    public array $actionOptions = [];
    public array $subjectTypeOptions = [];

    public function mount(): void
    {
        // Log activity for accessing this page
        ActivityLog::logActivity(
            Auth::id(),
            'access',
            'Accessed activity logs page',
            null,
            ['ip' => request()->ip()]
        );

        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        try {
            // User options
            $users = User::select('id', 'name', 'email')
                ->whereHas('activityLogs')
                ->orderBy('name')
                ->get();

            $this->userOptions = [
                ['id' => '', 'name' => 'All Users'],
                ...$users->map(fn($user) => [
                    'id' => $user->id,
                    'name' => $user->name . ' (' . $user->email . ')'
                ])->toArray()
            ];

            // Action options
            $actions = ActivityLog::select('action')
                ->whereNotNull('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->filter()
                ->values();

            // Also get activity_type for new schema compatibility
            $activityTypes = ActivityLog::select('activity_type')
                ->whereNotNull('activity_type')
                ->distinct()
                ->orderBy('activity_type')
                ->pluck('activity_type')
                ->filter()
                ->values();

            $allActions = $actions->merge($activityTypes)->unique()->sort()->values();

            $this->actionOptions = [
                ['id' => '', 'name' => 'All Actions'],
                ...$allActions->map(fn($action) => [
                    'id' => $action,
                    'name' => ucfirst($action)
                ])->toArray()
            ];

            // Subject type options
            $subjectTypes = ActivityLog::select('subject_type')
                ->whereNotNull('subject_type')
                ->distinct()
                ->pluck('subject_type')
                ->filter();

            // Also get loggable_type for old schema compatibility
            $loggableTypes = ActivityLog::select('loggable_type')
                ->whereNotNull('loggable_type')
                ->distinct()
                ->pluck('loggable_type')
                ->filter();

            $allSubjectTypes = $subjectTypes->merge($loggableTypes)->unique()->sort()->values();

            $this->subjectTypeOptions = [
                ['id' => '', 'name' => 'All Subject Types'],
                ...$allSubjectTypes->map(fn($type) => [
                    'id' => $type,
                    'name' => class_basename($type)
                ])->toArray()
            ];

        } catch (\Exception $e) {
            // Fallback options in case of error
            $this->userOptions = [['id' => '', 'name' => 'All Users']];
            $this->actionOptions = [
                ['id' => '', 'name' => 'All Actions'],
                ['id' => 'create', 'name' => 'Create'],
                ['id' => 'read', 'name' => 'Read'],
                ['id' => 'update', 'name' => 'Update'],
                ['id' => 'delete', 'name' => 'Delete'],
                ['id' => 'login', 'name' => 'Login'],
                ['id' => 'logout', 'name' => 'Logout'],
            ];
            $this->subjectTypeOptions = [['id' => '', 'name' => 'All Subject Types']];
        }
    }

    protected function loadStats(): void
    {
        try {
            $totalLogs = ActivityLog::count();
            $todayLogs = ActivityLog::whereDate('created_at', today())->count();
            $activeUsers = ActivityLog::whereDate('created_at', '>=', now()->subDays(7))
                ->distinct('user_id')
                ->whereNotNull('user_id')
                ->count();
            $errorLogs = ActivityLog::where(function($q) {
                $q->where('action', 'like', '%error%')
                  ->orWhere('activity_type', 'like', '%error%')
                  ->orWhere('description', 'like', '%error%')
                  ->orWhere('activity_description', 'like', '%error%');
            })->whereDate('created_at', '>=', now()->subDay())->count();

            // Activity type counts
            $createCount = ActivityLog::where(function($q) {
                $q->where('action', 'create')->orWhere('activity_type', 'create');
            })->count();

            $updateCount = ActivityLog::where(function($q) {
                $q->where('action', 'update')->orWhere('activity_type', 'update');
            })->count();

            $deleteCount = ActivityLog::where(function($q) {
                $q->where('action', 'delete')->orWhere('activity_type', 'delete');
            })->count();

            $loginCount = ActivityLog::where(function($q) {
                $q->where('action', 'login')->orWhere('activity_type', 'login');
            })->count();

            $otherCount = $totalLogs - ($createCount + $updateCount + $deleteCount + $loginCount);

            $this->stats = [
                'total_logs' => $totalLogs,
                'today_logs' => $todayLogs,
                'active_users' => $activeUsers,
                'error_logs' => $errorLogs,
                'create_count' => $createCount,
                'update_count' => $updateCount,
                'delete_count' => $deleteCount,
                'login_count' => $loginCount,
                'other_count' => max(0, $otherCount),
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_logs' => 0,
                'today_logs' => 0,
                'active_users' => 0,
                'error_logs' => 0,
                'create_count' => 0,
                'update_count' => 0,
                'delete_count' => 0,
                'login_count' => 0,
                'other_count' => 0,
            ];
        }
    }

    // Sort data
    public function sortBy(string $column): void
    {
        if ($this->sortBy['column'] === $column) {
            $this->sortBy['direction'] = $this->sortBy['direction'] === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy['column'] = $column;
            $this->sortBy['direction'] = 'asc';
        }
        $this->resetPage();
    }

    // Show log details
    public function showLogDetails(int $logId): void
    {
        $this->selectedLog = ActivityLog::with('user')->find($logId);
        $this->showDetailsModal = true;

        // Log viewing activity
        ActivityLog::logActivity(
            Auth::id(),
            'view',
            "Viewed activity log details for log ID: {$logId}",
            $this->selectedLog,
            ['viewed_log_id' => $logId]
        );
    }

    // Export logs
    public function exportLogs(): void
    {
        // This would typically generate a CSV or Excel file
        $this->info('Export functionality would be implemented here.');

        ActivityLog::logActivity(
            Auth::id(),
            'export',
            'Exported activity logs',
            null,
            [
                'filters' => [
                    'search' => $this->search,
                    'user_filter' => $this->userFilter,
                    'action_filter' => $this->actionFilter,
                    'date_filter' => $this->dateFilter,
                ]
            ]
        );
    }

    // Show clear modal
    public function showClearModal(): void
    {
        $this->showClearModal = true;
    }

    // Clear old logs
    public function clearOldLogs(int $days = 90): void
    {
        try {
            $deletedCount = ActivityLog::where('created_at', '<', now()->subDays($days))->delete();

            ActivityLog::logActivity(
                Auth::id(),
                'bulk_delete',
                "Cleared {$deletedCount} activity logs older than {$days} days",
                null,
                ['deleted_count' => $deletedCount, 'days' => $days]
            );

            $this->success("Cleared {$deletedCount} old activity logs.");
            $this->showClearModal = false;
            $this->loadStats();
        } catch (\Exception $e) {
            $this->error('An error occurred while clearing logs: ' . $e->getMessage());
        }
    }

    // Filter update methods
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedUserFilter(): void
    {
        $this->resetPage();
    }

    public function updatedActionFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSubjectTypeFilter(): void
    {
        $this->resetPage();
    }

    // Get filtered and paginated activity logs
    public function activityLogs(): LengthAwarePaginator
    {
        return ActivityLog::query()
            ->with(['user'])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('description', 'like', "%{$this->search}%")
                      ->orWhere('activity_description', 'like', "%{$this->search}%")
                      ->orWhere('action', 'like', "%{$this->search}%")
                      ->orWhere('activity_type', 'like', "%{$this->search}%")
                      ->orWhereHas('user', function ($userQuery) {
                          $userQuery->where('name', 'like', "%{$this->search}%")
                                   ->orWhere('email', 'like', "%{$this->search}%");
                      });
                });
            })
            ->when($this->userFilter, function (Builder $query) {
                $query->where('user_id', $this->userFilter);
            })
            ->when($this->actionFilter, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('action', $this->actionFilter)
                      ->orWhere('activity_type', $this->actionFilter);
                });
            })
            ->when($this->dateFilter, function (Builder $query) {
                $query->whereDate('created_at', $this->dateFilter);
            })
            ->when($this->subjectTypeFilter, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('subject_type', $this->subjectTypeFilter)
                      ->orWhere('loggable_type', $this->subjectTypeFilter);
                });
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->userFilter = '';
        $this->actionFilter = '';
        $this->dateFilter = '';
        $this->subjectTypeFilter = '';
        $this->resetPage();
    }

    // Helper function to get action color
    private function getActionColor(string $action): string
    {
        return match(strtolower($action)) {
            'create' => 'bg-green-100 text-green-800',
            'read', 'view', 'access' => 'bg-blue-100 text-blue-800',
            'update', 'edit' => 'bg-yellow-100 text-yellow-800',
            'delete', 'remove' => 'bg-red-100 text-red-800',
            'login', 'logout' => 'bg-purple-100 text-purple-800',
            'export' => 'bg-indigo-100 text-indigo-800',
            'bulk_update', 'bulk_delete' => 'bg-orange-100 text-orange-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function with(): array
    {
        return [
            'activityLogs' => $this->activityLogs(),
        ];
    }
};?>

<!-- Desktop Table View (hidden on small screens) -->
        <x-card class="hidden bg-white xl:block">
            <div class="overflow-x-auto">
                <table class="table w-full table-zebra">
                    <thead>
                        <tr>
                            <th class="cursor-pointer min-w-[120px]" wire:click="sortBy('action')">
                                <div class="flex items-center">
                                    Action
                                    @if ($sortBy['column'] === 'action')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="min-w-[250px]">Description</th>
                            <th class="cursor-pointer min-w-[150px]" wire:click="sortBy('user_id')">
                                <div class="flex items-center">
                                    User
                                    @if ($sortBy['column'] === 'user_id')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="min-w-[150px]">Subject</th>
                            <th class="hidden min-w-[120px] 2xl:table-cell">IP Address</th>
                            <th class="cursor-pointer min-w-[150px]" wire:click="sortBy('created_at')">
                                <div class="flex items-center">
                                    Timestamp
                                    @if ($sortBy['column'] === 'created_at')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="w-24 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($activityLogs as $log)
                            <tr class="hover">
                                <td>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getActionColor($log->action ?? $log->activity_type) }}">
                                        {{ ucfirst($log->action ?? $log->activity_type) }}
                                    </span>
                                </td>
                                <td>
                                    <div class="max-w-xs">
                                        <div class="text-sm font-medium text-gray-900 truncate">
                                            {{ $log->description ?? $log->activity_description }}
                                        </div>
                                        @if($log->additional_data && !empty($log->additional_data))
                                            <div class="mt-1 text-xs text-blue-600">
                                                +{{ count($log->additional_data) }} data fields
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    @if($log->user)
                                        <div class="flex items-center">
                                            <div class="flex items-center justify-center flex-shrink-0 w-8 h-8 mr-3 bg-blue-100 rounded-full">
                                                <span class="text-xs font-medium text-blue-600">
                                                    {{ substr($log->user->name, 0, 1) }}
                                                </span>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="text-sm font-medium text-gray-900 truncate">{{ $log->user->name }}</div>
                                                <div class="text-xs text-gray-500 truncate">{{ $log->user->email }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-sm text-gray-500">System</span>
                                    @endif
                                </td>
                                <td>
                                    @if($log->subject_type ?? $log->loggable_type)
                                        <div>
                                            <div class="font-mono text-sm">{{ class_basename($log->subject_type ?? $log->loggable_type) }}</div>
                                            @if($log->subject_id ?? $log->loggable_id)
                                                <div class="text-xs text-gray-500">ID: {{ $log->subject_id ?? $log->loggable_id }}</div>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-gray-500">-</span>
                                    @endif
                                </td>
                                <td class="hidden 2xl:table-cell">
                                    <div class="font-mono text-sm">{{ $log->ip_address ?: '-' }}</div>
                                </td>
                                <td>
                                    <div class="text-sm">{{ $log->created_at->format('M d, Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $log->created_at->format('g:i A') }}</div>
                                    <div class="text-xs text-gray-400">{{ $log->created_at->diffForHumans() }}</div>
                                </td>
                                <td class="text-right">
                                    <button
                                        wire:click="showLogDetails({{ $log->id }})"
                                        class="p-2 text-gray-600 transition-colors duration-150 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                        title="View Details"
                                    >
                                        👁️
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-12 text-center">
                                    <div class="flex flex-col items-center justify-center gap-4">
                                        <x-icon name="o-clipboard-document-list" class="w-20 h-20 text-gray-300" />
                                        <div>
                                            <h3 class="text-lg font-semibold text-gray-600">No activity logs found</h3>
                                            <p class="mt-1 text-gray-500">
                                                @if($search || $userFilter || $actionFilter || $dateFilter || $subjectTypeFilter)
                                                    No logs match your current filters.
                                                @else
                                                    Activity logs will appear here as users interact with the system.
                                                @endif
                                            </p>
                                        </div>
                                        @if($search || $userFilter || $actionFilter || $dateFilter || $subjectTypeFilter)
                                            <x-button
                                                label="Clear Filters"
                                                wire:click="resetFilters"
                                                color="secondary"
                                                size="sm"
                                            />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="mt-4">
                {{ $activityLogs->links() }}
            </div>

            <!-- Results summary -->
            @if($activityLogs->count() > 0)
            <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <span>
                        Showing {{ $activityLogs->firstItem() ?? 0 }} to {{ $activityLogs->lastItem() ?? 0 }}
                        of {{ $activityLogs->total() }} logs
                        @if($search || $userFilter || $actionFilter || $dateFilter || $subjectTypeFilter)
                            (filtered from total)
                        @endif
                    </span>
                    <div class="text-xs text-gray-500 sm:text-sm">
                        {{ $activityLogs->total() }} total records
                    </div>
                </div>
            </div>
            @endif
        </x-card>

        <!-- Mobile/Tablet Pagination (shown when using card view) -->
        <div class="xl:hidden">
            {{ $activityLogs->links() }}
        </div>

        <!-- Mobile Results Summary -->
        @if($activityLogs->count() > 0)
        <div class="pt-3 text-sm text-center text-gray-600 border-t xl:hidden">
            <div class="p-3 bg-white rounded-lg">
                Showing {{ $activityLogs->firstItem() ?? 0 }} to {{ $activityLogs->lastItem() ?? 0 }}
                of {{ $activityLogs->total() }} logs
                @if($search || $userFilter || $actionFilter || $dateFilter || $subjectTypeFilter)
                    (filtered)
                @endif
            </div>
        </div>
        @endif

        <!-- Responsive Filters drawer -->
        <x-drawer wire:model="showFilters" title="Activity Log Filters" position="right" class="w-full max-w-sm">
            <div class="flex flex-col h-full p-4">
                <div class="flex-1 mb-6 space-y-4">
                    <div>
                        <x-input
                            label="Search logs"
                            wire:model.live.debounce="search"
                            icon="o-magnifying-glass"
                            placeholder="Search by description, user, or action..."
                            clearable
                            class="w-full"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Filter by user"
                            :options="$userOptions"
                            wire:model.live="userFilter"
                            option-value="id"
                            option-label="name"
                            placeholder="All users"
                            class="w-full"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Filter by action"
                            :options="$actionOptions"
                            wire:model.live="actionFilter"
                            option-value="id"
                            option-label="name"
                            placeholder="All actions"
                            class="w-full"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Filter by subject type"
                            :options="$subjectTypeOptions"
                            wire:model.live="subjectTypeFilter"
                            option-value="id"
                            option-label="name"
                            placeholder="All subject types"
                            class="w-full"
                        />
                    </div>

                    <div>
                        <x-input
                            label="Date filter"
                            type="date"
                            wire:model.live="dateFilter"
                            placeholder="Select date"
                            class="w-full"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Items per page"
                            :options="[
                                ['id' => 15, 'name' => '15 per page'],
                                ['id' => 25, 'name' => '25 per page'],
                                ['id' => 50, 'name' => '50 per page'],
                                ['id' => 100, 'name' => '100 per page']
                            ]"
                            option-value="id"
                            option-label="name"
                            wire:model.live="perPage"
                            class="w-full"
                        />
                    </div>

                    <!-- Active Filters Summary -->
                    @if($search || $userFilter || $actionFilter || $dateFilter || $subjectTypeFilter)
                        <div class="p-3 border border-blue-200 rounded-lg bg-blue-50">
                            <div class="mb-2 text-sm font-medium text-blue-800">Active Filters:</div>
                            <div class="space-y-1">
                                @if($search)
                                    <div class="text-xs text-blue-600">Search: "{{ $search }}"</div>
                                @endif
                                @if($userFilter)
                                    <div class="text-xs text-blue-600">User: {{ collect($userOptions)->firstWhere('id', $userFilter)['name'] ?? $userFilter }}</div>
                                @endif
                                @if($actionFilter)
                                    <div class="text-xs text-blue-600">Action: {{ collect($actionOptions)->firstWhere('id', $actionFilter)['name'] ?? $actionFilter }}</div>
                                @endif
                                @if($subjectTypeFilter)
                                    <div class="text-xs text-blue-600">Subject: {{ collect($subjectTypeOptions)->firstWhere('id', $subjectTypeFilter)['name'] ?? $subjectTypeFilter }}</div>
                                @endif
                                @if($dateFilter)
                                    <div class="text-xs text-blue-600">Date: {{ $dateFilter }}</div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Sticky Actions at Bottom -->
                <div class="pt-4 space-y-3 border-t">
                    <x-button
                        label="Reset All Filters"
                        icon="o-x-mark"
                        wire:click="resetFilters"
                        class="w-full btn-outline"
                    />
                    <x-button
                        label="Apply & Close"
                        icon="o-check"
                        wire:click="$set('showFilters', false)"
                        color="primary"
                        class="w-full"
                    />
                </div>
            </div>
        </x-drawer>

        <!-- Log Details Modal -->
        <x-modal wire:model="showDetailsModal" title="Activity Log Details" class="backdrop-blur">
            @if($selectedLog)
            <div class="space-y-4">
                <!-- Basic Info Grid -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium text-gray-700">Action</label>
                        <div class="mt-1">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium {{ $this->getActionColor($selectedLog->action ?? $selectedLog->activity_type) }}">
                                {{ ucfirst($selectedLog->action ?? $selectedLog->activity_type) }}
                            </span>
                        </div>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-700">Timestamp</label>
                        <div class="mt-1 text-sm text-gray-900">{{ $selectedLog->created_at->format('M d, Y g:i A') }}</div>
                        <div class="text-xs text-gray-500">{{ $selectedLog->created_at->diffForHumans() }}</div>
                    </div>
                </div>

                <!-- Description -->
                <div>
                    <label class="text-sm font-medium text-gray-700">Description</label>
                    <div class="p-3 mt-1 text-sm text-gray-900 rounded-lg bg-gray-50">
                        {{ $selectedLog->description ?? $selectedLog->activity_description }}
                    </div>
                </div>

                <!-- User Info -->
                <div>
                    <label class="text-sm font-medium text-gray-700">User</label>
                    <div class="mt-1">
                        @if($selectedLog->user)
                            <div class="flex items-center p-3 space-x-3 rounded-lg bg-gray-50">
                                <div class="flex items-center justify-center flex-shrink-0 w-10 h-10 bg-blue-100 rounded-full">
                                    <span class="text-sm font-medium text-blue-600">
                                        {{ substr($selectedLog->user->name, 0, 1) }}
                                    </span>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-900">{{ $selectedLog->user->name }}</div>
                                    <div class="text-sm text-gray-500">{{ $selectedLog->user->email }}</div>
                                </div>
                            </div>
                        @else
                            <div class="p-3 rounded-lg bg-gray-50">
                                <span class="text-sm text-gray-500">System Action</span>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Subject Info -->
                @if($selectedLog->subject_type ?? $selectedLog->loggable_type)
                <div>
                    <label class="text-sm font-medium text-gray-700">Subject</label>
                    <div class="p-3 mt-1 rounded-lg bg-gray-50">
                        <div class="font-mono text-sm text-gray-900">{{ $selectedLog->subject_type ?? $selectedLog->loggable_type }}</div>
                        @if($selectedLog->subject_id ?? $selectedLog->loggable_id)
                            <div class="mt-1 text-sm text-gray-500">ID: {{ $selectedLog->subject_id ?? $selectedLog->loggable_id }}</div>
                        @endif
                    </div>
                </div>
                @endif

                <!-- IP Address -->
                @if($selectedLog->ip_address)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium text-gray-700">IP Address</label>
                        <div class="mt-1 font-mono text-sm text-gray-900">{{ $selectedLog->ip_address }}</div>
                    </div>
                </div>
                @endif

                <!-- Additional Data -->
                @if($selectedLog->additional_data && !empty($selectedLog->additional_data))
                <div>
                    <label class="text-sm font-medium text-gray-700">Additional Data</label>
                    <div class="p-3 mt-1 rounded-lg bg-gray-50">
                        <pre class="overflow-x-auto text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($selectedLog->additional_data, JSON_PRETTY_PRINT) }}</pre>
                    </div>
                </div>
                @endif
            </div>
            @endif

            <x-slot:actions>
                <x-button label="Close" wire:click="$set('showDetailsModal', false)" />
            </x-slot:actions>
        </x-modal>

        <!-- Clear Old Logs Modal -->
        <x-modal wire:model="showClearModal" title="Clear Old Activity Logs" class="backdrop-blur">
            <div class="space-y-4">
                <div class="p-4 border border-yellow-200 rounded-lg bg-yellow-50">
                    <div class="flex">
                        <x-icon name="o-exclamation-triangle" class="flex-shrink-0 w-5 h-5 text-yellow-400" />
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800">Warning</h3>
                            <div class="mt-2 text-sm text-yellow-700">
                                This action will permanently delete old activity logs and cannot be undone.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <p class="text-sm text-gray-600">Choose how many days of logs to keep:</p>

                    <div class="grid grid-cols-1 gap-3 xs:grid-cols-2">
                        <x-button
                            label="Keep 30 days"
                            wire:click="clearOldLogs(30)"
                            class="w-full btn-outline"
                            wire:confirm="Are you sure you want to delete logs older than 30 days?"
                        />
                        <x-button
                            label="Keep 90 days"
                            wire:click="clearOldLogs(90)"
                            class="w-full btn-outline"
                            wire:confirm="Are you sure you want to delete logs older than 90 days?"
                        />
                        <x-button
                            label="Keep 180 days"
                            wire:click="clearOldLogs(180)"
                            class="w-full btn-outline"
                            wire:confirm="Are you sure you want to delete logs older than 180 days?"
                        />
                        <x-button
                            label="Keep 1 year"
                            wire:click="clearOldLogs(365)"
                            class="w-full btn-outline"
                            wire:confirm="Are you sure you want to delete logs older than 1 year?"
                        />
                    </div>
                </div>
            </div>

            <x-slot:actions>
                <x-button label="Cancel" wire:click="$set('showClearModal', false)" class="w-full sm:w-auto" />
            </x-slot:actions>
        </x-modal>
    </div>
</div>
                                <div class="min-h-screen p-3 bg-gray-50 sm:p-4 lg:p-6">
    <div class="mx-auto space-y-4 max-w-7xl sm:space-y-6">
        <!-- Mobile-first responsive page header -->
        <x-header title="Activity Logs" subtitle="Monitor system activities and user actions" separator progress-indicator>
            <!-- SEARCH -->
            <x-slot:middle class="!justify-end w-full">
                <div class="w-full max-w-xs sm:max-w-sm md:max-w-md">
                    <x-input
                        placeholder="Search activities..."
                        wire:model.live.debounce="search"
                        icon="o-magnifying-glass"
                        clearable
                        class="w-full"
                    />
                </div>
            </x-slot:middle>

            <!-- ACTIONS -->
            <x-slot:actions>
                <div class="flex flex-col w-full gap-2 xs:flex-row sm:flex-row sm:w-auto">
                    <x-button
                        label="Filters"
                        icon="o-funnel"
                        :badge="count(array_filter([$userFilter, $actionFilter, $dateFilter, $subjectTypeFilter]))"
                        badge-classes="font-mono"
                        @click="$wire.showFilters = true"
                        class="order-2 w-full bg-base-300 xs:w-auto sm:w-auto xs:order-1"
                        responsive
                    />

                    <x-button
                        label="Export"
                        icon="o-arrow-down-tray"
                        wire:click="exportLogs"
                        class="order-3 w-full btn-outline xs:w-auto sm:w-auto xs:order-2"
                        responsive
                    />

                    <x-button
                        label="Clear Old Logs"
                        icon="o-trash"
                        wire:click="showClearModal"
                        class="order-1 w-full btn-error xs:w-auto sm:w-auto xs:order-3"
                        responsive
                    />
                </div>
            </x-slot:actions>
        </x-header>

        <!-- Responsive Stats Cards -->
        <div class="grid grid-cols-1 gap-3 xs:grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 sm:gap-4 lg:gap-6">
            <x-card class="transition-all duration-300 bg-white hover:shadow-lg">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 p-2 mr-3 bg-blue-100 rounded-full sm:p-3 sm:mr-4">
                            <x-icon name="o-clipboard-document-list" class="w-5 h-5 text-blue-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-blue-600 truncate sm:text-xl lg:text-2xl">{{ number_format($stats['total_logs']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm">Total Logs</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 bg-white hover:shadow-lg">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 p-2 mr-3 bg-green-100 rounded-full sm:p-3 sm:mr-4">
                            <x-icon name="o-clock" class="w-5 h-5 text-green-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-green-600 truncate sm:text-xl lg:text-2xl">{{ number_format($stats['today_logs']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm">Today</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 bg-white hover:shadow-lg">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 p-2 mr-3 bg-orange-100 rounded-full sm:p-3 sm:mr-4">
                            <x-icon name="o-users" class="w-5 h-5 text-orange-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-orange-600 truncate sm:text-xl lg:text-2xl">{{ number_format($stats['active_users']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm">Active Users (7d)</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 bg-white hover:shadow-lg">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 p-2 mr-3 bg-red-100 rounded-full sm:p-3 sm:mr-4">
                            <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-red-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-red-600 truncate sm:text-xl lg:text-2xl">{{ number_format($stats['error_logs']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm">Errors (24h)</div>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- Activity Type Distribution Cards -->
        <div class="grid grid-cols-2 gap-2 xs:gap-3 sm:grid-cols-5 sm:gap-4">
            <x-card class="transition-all duration-300 border-green-200 bg-green-50 hover:shadow-lg hover:bg-green-100">
                <div class="p-2 text-center sm:p-3 lg:p-4">
                    <div class="text-base font-bold text-green-600 sm:text-lg lg:text-xl">{{ number_format($stats['create_count']) }}</div>
                    <div class="text-xs text-green-600 sm:text-sm">Creates</div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 border-yellow-200 bg-yellow-50 hover:shadow-lg hover:bg-yellow-100">
                <div class="p-2 text-center sm:p-3 lg:p-4">
                    <div class="text-base font-bold text-yellow-600 sm:text-lg lg:text-xl">{{ number_format($stats['update_count']) }}</div>
                    <div class="text-xs text-yellow-600 sm:text-sm">Updates</div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 border-red-200 bg-red-50 hover:shadow-lg hover:bg-red-100">
                <div class="p-2 text-center sm:p-3 lg:p-4">
                    <div class="text-base font-bold text-red-600 sm:text-lg lg:text-xl">{{ number_format($stats['delete_count']) }}</div>
                    <div class="text-xs text-red-600 sm:text-sm">Deletes</div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 border-purple-200 bg-purple-50 hover:shadow-lg hover:bg-purple-100">
                <div class="p-2 text-center sm:p-3 lg:p-4">
                    <div class="text-base font-bold text-purple-600 sm:text-lg lg:text-xl">{{ number_format($stats['login_count']) }}</div>
                    <div class="text-xs text-purple-600 sm:text-sm">Logins</div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 border-gray-200 bg-gray-50 hover:shadow-lg hover:bg-gray-100">
                <div class="p-2 text-center sm:p-3 lg:p-4">
                    <div class="text-base font-bold text-gray-600 sm:text-lg lg:text-xl">{{ number_format($stats['other_count']) }}</div>
                    <div class="text-xs text-gray-600 sm:text-sm">Others</div>
                </div>
            </x-card>
        </div>

        <!-- Mobile Card View (shown on small screens) -->
        <div class="block space-y-3 xl:hidden">
            @forelse($activityLogs as $log)
                <x-card class="transition-all duration-300 bg-white hover:shadow-lg">
                    <div class="p-3 sm:p-4">
                        <!-- Log Info Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-start flex-1 min-w-0 space-x-3">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getActionColor($log->action ?? $log->activity_type) }} flex-shrink-0">
                                    {{ ucfirst($log->action ?? $log->activity_type) }}
                                </span>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-900 line-clamp-2">
                                        {{ $log->description ?? $log->activity_description }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ $log->created_at->format('M d, Y g:i A') }}
                                    </div>
                                </div>
                            </div>
                            <button
                                wire:click="showLogDetails({{ $log->id }})"
                                class="p-1.5 sm:p-2 ml-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200 transition-colors duration-150 flex-shrink-0"
                                title="View Details"
                            >
                                <span class="text-sm">👁️</span>
                            </button>
                        </div>

                        <!-- User and Subject -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center flex-1 min-w-0 space-x-2">
                                    @if($log->user)
                                        <div class="flex items-center justify-center flex-shrink-0 w-5 h-5 bg-blue-100 rounded-full sm:w-6 sm:h-6">
                                            <span class="text-xs font-medium text-blue-600">
                                                {{ substr($log->user->name, 0, 1) }}
                                            </span>
                                        </div>
                                        <span class="text-sm font-medium truncate">{{ $log->user->name }}</span>
                                    @else
                                        <span class="text-sm text-gray-500">System</span>
                                    @endif
                                </div>
                                <div class="flex-shrink-0 text-xs text-gray-500">
                                    {{ $log->created_at->diffForHumans() }}
                                </div>
                            </div>

                            @if($log->subject_type ?? $log->loggable_type)
                                <div class="text-xs">
                                    <span class="text-gray-500">Subject:</span>
                                    <span class="font-mono text-gray-600">{{ class_basename($log->subject_type ?? $log->loggable_type) }}</span>
                                    @if($log->subject_id ?? $log->loggable_id)
                                        <span class="text-gray-500">ID: {{ $log->subject_id ?? $log->loggable_id }}</span>
                                    @endif
                                </div>
                            @endif

                            @if($log->ip_address)
                                <div class="text-xs">
                                    <span class="text-gray-500">IP:</span>
                                    <span class="font-mono">{{ $log->ip_address }}</span>
                                </div>
                            @endif

                            @if($log->additional_data && !empty($log->additional_data))
                                <div class="flex items-center pt-2 space-x-2 border-t border-gray-100">
                                    <x-icon name="o-information-circle" class="flex-shrink-0 w-4 h-4 text-blue-500" />
                                    <span class="text-xs text-blue-600">{{ count($log->additional_data) }} additional data fields</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-card>
            @empty
                <x-card class="bg-white">
                    <div class="py-8 text-center sm:py-12">
                        <div class="flex flex-col items-center justify-center gap-4">
                            <x-icon name="o-clipboard-document-list" class="w-12 h-12 text-gray-300 sm:w-16 sm:h-16" />
                            <div>
                                <h3 class="text-base font-semibold text-gray-600 sm:text-lg">No activity logs found</h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    @if($search || $userFilter || $actionFilter || $dateFilter || $subjectTypeFilter)
                                        No logs match your current filters.
                                    @else
                                        Activity logs will appear here as users interact with the system.
                                    @endif
                                </p>
                            </div>
                            @if($search || $userFilter || $actionFilter || $dateFilter || $subjectTypeFilter)
                                <x-button
                                    label="Clear Filters"
                                    wire:click="resetFilters"
                                    color="secondary"
                                    size="sm"
                                    class="w-full xs:w-auto"
                                />
                            @endif
                        </div>
                    </div>
                </x-card>
            @endforelse
        </div>

    <!-- Desktop Table View (hidden on small screens) -->
    <x-card class="hidden lg:block">
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer min-w-[120px]" wire:click="sortBy('action')">
                            <div class="flex items-center">
                                Action
                                @if ($sortBy['column'] === 'action')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="min-w-[250px]">Description</th>
                        <th class="cursor-pointer min-w-[150px]" wire:click="sortBy('user_id')">
                            <div class="flex items-center">
                                User
                                @if ($sortBy['column'] === 'user_id')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="hidden min-w-[150px] xl:table-cell">Subject</th>
                        <th class="hidden min-w-[120px] 2xl:table-cell">IP Address</th>
                        <th class="cursor-pointer min-w-[150px]" wire:click="sortBy('created_at')">
                            <div class="flex items-center">
                                Timestamp
                                @if ($sortBy['column'] === 'created_at')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="w-24 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($activityLogs as $log)
                        <tr class="hover">
                            <td>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getActionColor($log->action ?? $log->activity_type) }}">
                                    {{ ucfirst($log->action ?? $log->activity_type) }}
                                </span>
                            </td>
                            <td>
                                <div class="max-w-xs">
                                    <div class="text-sm font-medium text-gray-900 truncate">
                                        {{ $log->description ?? $log->activity_description }}
                                    </div>
                                    @if($log->additional_data && !empty($log->additional_data))
                                        <div class="mt-1 text-xs text-blue-600">
                                            +{{ count($log->additional_data) }} data fields
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if($log->user)
                                    <div class="flex items-center">
                                        <div class="flex items-center justify-center w-8 h-8 mr-3 bg-blue-100 rounded-full">
                                            <span class="text-xs font-medium text-blue-600">
                                                {{ substr($log->user->name, 0, 1) }}
                                            </span>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-sm font-medium text-gray-900 truncate">{{ $log->user->name }}</div>
                                            <div class="text-xs text-gray-500 truncate">{{ $log->user->email }}</div>
                                        </div>
                                    </div>
                                @else
                                    <span class="text-sm text-gray-500">System</span>
                                @endif
                            </td>
                            <td class="hidden xl:table-cell">
                                @if($log->subject_type ?? $log->loggable_type)
                                    <div>
                                        <div class="font-mono text-sm">{{ class_basename($log->subject_type ?? $log->loggable_type) }}</div>
                                        @if($log->subject_id ?? $log->loggable_id)
                                            <div class="text-xs text-gray-500">ID: {{ $log->subject_id ?? $log->loggable_id }}</div>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-gray-500">-</span>
                                @endif
                            </td>
                            <td class="hidden 2xl:table-cell">
                                <div class="font-mono text-sm">{{ $log->ip_address ?: '-' }}</div>
                            </td>
                            <td>
                                <div class="text-sm">{{ $log->created_at->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $log->created_at->format('g:i A') }}</div>
                                <div class="text-xs text-gray-400">{{ $log->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="text-right">
                                <button
                                    wire:click="showLogDetails({{ $log->id }})"
                                    class="p-2 text-gray-600 transition-colors duration-150 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                    title="View Details"
                                >
                                    👁️
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-clipboard-document-list" class="w-20 h-20 text-gray-300" />
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-600">No activity logs found</h3>
                                        <p class="mt-1 text-gray-500">
                                            @if($search || $userFilter || $actionFilter || $dateFilter || $subjectTypeFilter)
                                                No logs match your current filters.
                                            @else
                                                Activity logs will appear here as users interact with the system.
                                            @endif
                                        </p>
                                    </div>
                                    @if($search || $userFilter || $actionFilter || $dateFilter || $subjectTypeFilter)
                                        <x-button
                                            label="Clear Filters"
                                            wire:click="resetFilters"
                                            color="secondary"
                                            size="sm"
                                        />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $activityLogs->links() }}
        </div>

        <!-- Results summary -->
        @if($activityLogs->count() > 0)
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $activityLogs->firstItem() ?? 0 }} to {{ $activityLogs->lastItem() ?? 0 }}
            of {{ $activityLogs->total() }} logs
            @if($search || $userFilter || $actionFilter || $dateFilter || $subjectTypeFilter)
                (filtered from total)
            @endif
        </div>
        @endif
    </x-card>

    <!-- Mobile/Tablet Pagination (shown when using card view) -->
    <div class="mt-4 lg:hidden">
        {{ $activityLogs->links() }}
    </div>

    <!-- Mobile Results Summary -->
    @if($activityLogs->count() > 0)
    <div class="pt-3 mt-4 text-sm text-center text-gray-600 border-t lg:hidden">
        Showing {{ $activityLogs->firstItem() ?? 0 }} to {{ $activityLogs->lastItem() ?? 0 }}
        of {{ $activityLogs->total() }} logs
        @if($search || $userFilter || $actionFilter || $dateFilter || $subjectTypeFilter)
            (filtered)
        @endif
    </div>
    @endif

    <!-- Responsive Filters drawer -->
    <x-drawer wire:model="showFilters" title="Activity Log Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search logs"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by description, user, or action..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by user"
                    :options="$userOptions"
                    wire:model.live="userFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All users"
                />
            </div>

            <div>
                <x-select
                    label="Filter by action"
                    :options="$actionOptions"
                    wire:model.live="actionFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All actions"
                />
            </div>

            <div>
                <x-select
                    label="Filter by subject type"
                    :options="$subjectTypeOptions"
                    wire:model.live="subjectTypeFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All subject types"
                />
            </div>

            <div>
                <x-input
                    label="Date filter"
                    type="date"
                    wire:model.live="dateFilter"
                    placeholder="Select date"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[
                        ['id' => 15, 'name' => '15 per page'],
                        ['id' => 25, 'name' => '25 per page'],
                        ['id' => 50, 'name' => '50 per page'],
                        ['id' => 100, 'name' => '100 per page']
                    ]"
                    option-value="id"
                    option-label="name"
                    wire:model.live="perPage"
                />
            </div>

            <!-- Active Filters Summary -->
            @if($search || $userFilter || $actionFilter || $dateFilter || $subjectTypeFilter)
                <div class="p-3 border border-blue-200 rounded-lg bg-blue-50">
                    <div class="mb-2 text-sm font-medium text-blue-800">Active Filters:</div>
                    <div class="space-y-1">
                        @if($search)
                            <div class="text-xs text-blue-600">Search: "{{ $search }}"</div>
                        @endif
                        @if($userFilter)
                            <div class="text-xs text-blue-600">User: {{ collect($userOptions)->firstWhere('id', $userFilter)['name'] ?? $userFilter }}</div>
                        @endif
                        @if($actionFilter)
                            <div class="text-xs text-blue-600">Action: {{ collect($actionOptions)->firstWhere('id', $actionFilter)['name'] ?? $actionFilter }}</div>
                        @endif
                        @if($subjectTypeFilter)
                            <div class="text-xs text-blue-600">Subject: {{ collect($subjectTypeOptions)->firstWhere('id', $subjectTypeFilter)['name'] ?? $subjectTypeFilter }}</div>
                        @endif
                        @if($dateFilter)
                            <div class="text-xs text-blue-600">Date: {{ $dateFilter }}</div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <x-slot:actions>
            <x-button label="Reset" icon="o-x-mark" wire:click="resetFilters" class="w-full sm:w-auto" />
            <x-button label="Apply" icon="o-check" wire:click="$set('showFilters', false)" color="primary" class="w-full sm:w-auto" />
        </x-slot:actions>
    </x-drawer>

    <!-- Log Details Modal -->
    <x-modal wire:model="showDetailsModal" title="Activity Log Details" class="backdrop-blur">
        @if($selectedLog)
        <div class="space-y-4">
            <!-- Basic Info Grid -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="text-sm font-medium text-gray-700">Action</label>
                    <div class="mt-1">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium {{ $this->getActionColor($selectedLog->action ?? $selectedLog->activity_type) }}">
                            {{ ucfirst($selectedLog->action ?? $selectedLog->activity_type) }}
                        </span>
                    </div>
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-700">Timestamp</label>
                    <div class="mt-1 text-sm text-gray-900">{{ $selectedLog->created_at->format('M d, Y g:i A') }}</div>
                    <div class="text-xs text-gray-500">{{ $selectedLog->created_at->diffForHumans() }}</div>
                </div>
            </div>

            <!-- Description -->
            <div>
                <label class="text-sm font-medium text-gray-700">Description</label>
                <div class="p-3 mt-1 text-sm text-gray-900 rounded-lg bg-gray-50">
                    {{ $selectedLog->description ?? $selectedLog->activity_description }}
                </div>
            </div>

            <!-- User Info -->
            <div>
                <label class="text-sm font-medium text-gray-700">User</label>
                <div class="mt-1">
                    @if($selectedLog->user)
                        <div class="flex items-center p-3 space-x-3 rounded-lg bg-gray-50">
                            <div class="flex items-center justify-center w-10 h-10 bg-blue-100 rounded-full">
                                <span class="text-sm font-medium text-blue-600">
                                    {{ substr($selectedLog->user->name, 0, 1) }}
                                </span>
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-900">{{ $selectedLog->user->name }}</div>
                                <div class="text-sm text-gray-500">{{ $selectedLog->user->email }}</div>
                            </div>
                        </div>
                    @else
                        <div class="p-3 rounded-lg bg-gray-50">
                            <span class="text-sm text-gray-500">System Action</span>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Subject Info -->
            @if($selectedLog->subject_type ?? $selectedLog->loggable_type)
            <div>
                <label class="text-sm font-medium text-gray-700">Subject</label>
                <div class="p-3 mt-1 rounded-lg bg-gray-50">
                    <div class="font-mono text-sm text-gray-900">{{ $selectedLog->subject_type ?? $selectedLog->loggable_type }}</div>
                    @if($selectedLog->subject_id ?? $selectedLog->loggable_id)
                        <div class="mt-1 text-sm text-gray-500">ID: {{ $selectedLog->subject_id ?? $selectedLog->loggable_id }}</div>
                    @endif
                </div>
            </div>
            @endif

            <!-- IP Address -->
            @if($selectedLog->ip_address)
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="text-sm font-medium text-gray-700">IP Address</label>
                    <div class="mt-1 font-mono text-sm text-gray-900">{{ $selectedLog->ip_address }}</div>
                </div>
            </div>
            @endif

            <!-- Additional Data -->
            @if($selectedLog->additional_data && !empty($selectedLog->additional_data))
            <div>
                <label class="text-sm font-medium text-gray-700">Additional Data</label>
                <div class="p-3 mt-1 rounded-lg bg-gray-50">
                    <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($selectedLog->additional_data, JSON_PRETTY_PRINT) }}</pre>
                </div>
            </div>
            @endif
        </div>
        @endif

        <x-slot:actions>
            <x-button label="Close" wire:click="$set('showDetailsModal', false)" />
        </x-slot:actions>
    </x-modal>

    <!-- Clear Old Logs Modal -->
    <x-modal wire:model="showClearModal" title="Clear Old Activity Logs" class="backdrop-blur">
        <div class="space-y-4">
            <div class="p-4 border border-yellow-200 rounded-lg bg-yellow-50">
                <div class="flex">
                    <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-yellow-400" />
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-yellow-800">Warning</h3>
                        <div class="mt-2 text-sm text-yellow-700">
                            This action will permanently delete old activity logs and cannot be undone.
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-3">
                <p class="text-sm text-gray-600">Choose how many days of logs to keep:</p>

                <div class="grid grid-cols-2 gap-3">
                    <x-button
                        label="Keep 30 days"
                        wire:click="clearOldLogs(30)"
                        class="btn-outline"
                        wire:confirm="Are you sure you want to delete logs older than 30 days?"
                    />
                    <x-button
                        label="Keep 90 days"
                        wire:click="clearOldLogs(90)"
                        class="btn-outline"
                        wire:confirm="Are you sure you want to delete logs older than 90 days?"
                    />
                    <x-button
                        label="Keep 180 days"
                        wire:click="clearOldLogs(180)"
                        class="btn-outline"
                        wire:confirm="Are you sure you want to delete logs older than 180 days?"
                    />
                    <x-button
                        label="Keep 1 year"
                        wire:click="clearOldLogs(365)"
                        class="btn-outline"
                        wire:confirm="Are you sure you want to delete logs older than 1 year?"
                    />
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showClearModal', false)" />
        </x-slot:actions>
    </x-modal>
</div>
