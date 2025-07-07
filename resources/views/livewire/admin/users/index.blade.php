<?php

use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Users Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $roleFilter = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Bulk actions
    public array $selectedUsers = [];
    public bool $selectAll = false;

    // Stats
    public array $stats = [];

    // Filter options
    public array $roleOptions = [];
    public array $statusOptions = [];

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed users management page',
            User::class,
            null,
            ['ip' => request()->ip()]
        );

        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        // Status options
        $this->statusOptions = [
            ['id' => '', 'name' => 'All Statuses'],
            ['id' => 'active', 'name' => 'Active'],
            ['id' => 'inactive', 'name' => 'Inactive'],
            ['id' => 'suspended', 'name' => 'Suspended'],
        ];

        // Role options - get from database
        try {
            $roles = \Spatie\Permission\Models\Role::orderBy('name')->get();
            $this->roleOptions = [
                ['id' => '', 'name' => 'All Roles'],
                ...$roles->map(fn($role) => [
                    'id' => $role->name,
                    'name' => ucfirst($role->name)
                ])->toArray()
            ];
        } catch (\Exception $e) {
            $this->roleOptions = [
                ['id' => '', 'name' => 'All Roles'],
                ['id' => 'admin', 'name' => 'Admin'],
                ['id' => 'teacher', 'name' => 'Teacher'],
                ['id' => 'parent', 'name' => 'Parent'],
                ['id' => 'student', 'name' => 'Student'],
            ];
        }
    }

    protected function loadStats(): void
    {
        try {
            $totalUsers = User::count();
            $activeUsers = User::where('status', 'active')->count();
            $inactiveUsers = User::where('status', 'inactive')->count();
            $suspendedUsers = User::where('status', 'suspended')->count();
            $recentUsers = User::where('created_at', '>=', now()->subDays(30))->count();

            // Get role counts
            $adminCount = User::role('admin')->count();
            $teacherCount = User::role('teacher')->count();
            $parentCount = User::role('parent')->count();
            $studentCount = User::role('student')->count();

            $this->stats = [
                'total_users' => $totalUsers,
                'active_users' => $activeUsers,
                'inactive_users' => $inactiveUsers,
                'suspended_users' => $suspendedUsers,
                'recent_users' => $recentUsers,
                'admin_count' => $adminCount,
                'teacher_count' => $teacherCount,
                'parent_count' => $parentCount,
                'student_count' => $studentCount,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_users' => 0,
                'active_users' => 0,
                'inactive_users' => 0,
                'suspended_users' => 0,
                'recent_users' => 0,
                'admin_count' => 0,
                'teacher_count' => 0,
                'parent_count' => 0,
                'student_count' => 0,
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

    // Redirect to create page
    public function redirectToCreate(): void
    {
        $this->redirect(route('admin.users.create'));
    }

    // Redirect to show page
    public function redirectToShow(int $userId): void
    {
        $this->redirect(route('admin.users.show', $userId));
    }

    // Redirect to edit page
    public function redirectToEdit(int $userId): void
    {
        $this->redirect(route('admin.users.edit', $userId));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->roleFilter = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    public function bulkActivate(): void
    {
        if (empty($this->selectedUsers)) {
            $this->error('Please select users to activate.');
            return;
        }

        try {
            $updated = User::whereIn('id', $this->selectedUsers)
                ->where('status', '!=', 'active')
                ->update(['status' => 'active']);

            ActivityLog::log(
                Auth::id(),
                'bulk_update',
                "Bulk activated {$updated} user(s)",
                User::class,
                null,
                [
                    'user_ids' => $this->selectedUsers,
                    'action' => 'activate',
                    'count' => $updated
                ]
            );

            $this->success("Activated {$updated} user(s).");
            $this->selectedUsers = [];
            $this->selectAll = false;
            $this->loadStats();
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    public function bulkDeactivate(): void
    {
        if (empty($this->selectedUsers)) {
            $this->error('Please select users to deactivate.');
            return;
        }

        try {
            // Don't deactivate the current user
            $usersToUpdate = array_filter($this->selectedUsers, fn($id) => $id != Auth::id());

            if (empty($usersToUpdate)) {
                $this->error('Cannot deactivate your own account.');
                return;
            }

            $updated = User::whereIn('id', $usersToUpdate)
                ->where('status', '!=', 'inactive')
                ->update(['status' => 'inactive']);

            ActivityLog::log(
                Auth::id(),
                'bulk_update',
                "Bulk deactivated {$updated} user(s)",
                User::class,
                null,
                [
                    'user_ids' => $usersToUpdate,
                    'action' => 'deactivate',
                    'count' => $updated
                ]
            );

            $this->success("Deactivated {$updated} user(s).");
            $this->selectedUsers = [];
            $this->selectAll = false;
            $this->loadStats();
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Get filtered and paginated users
    public function users(): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles'])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                      ->orWhere('email', 'like', "%{$this->search}%")
                      ->orWhere('phone', 'like', "%{$this->search}%");
                });
            })
            ->when($this->statusFilter, function (Builder $query) {
                $query->where('status', $this->statusFilter);
            })
            ->when($this->roleFilter, function (Builder $query) {
                $query->role($this->roleFilter);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->roleFilter = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    // Helper function to get status color
    private function getStatusColor(string $status): string
    {
        return match($status) {
            'active' => 'bg-green-100 text-green-800',
            'inactive' => 'bg-gray-100 text-gray-600',
            'suspended' => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to get role badge color
    private function getRoleColor(string $role): string
    {
        return match($role) {
            'admin' => 'bg-purple-100 text-purple-800',
            'teacher' => 'bg-blue-100 text-blue-800',
            'parent' => 'bg-green-100 text-green-800',
            'student' => 'bg-orange-100 text-orange-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function with(): array
    {
        return [
            'users' => $this->users(),
        ];
    }
};?>

<div>
    <!-- Mobile-first responsive page header -->
    <x-header title="Users Management" subtitle="Manage system users and their permissions" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input
                placeholder="Search users..."
                wire:model.live.debounce="search"
                icon="o-magnifying-glass"
                clearable
                class="w-full sm:w-64 lg:w-80"
            />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$roleFilter, $statusFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive
            />

            <x-button
                label="Create User"
                icon="o-plus"
                wire:click="redirectToCreate"
                class="btn-primary"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Responsive Stats Cards -->
    <div class="grid grid-cols-1 gap-4 mb-6 sm:grid-cols-2 lg:grid-cols-4 sm:gap-6 sm:mb-8">
        <x-card class="transition-all duration-300 hover:shadow-lg">
            <div class="p-4 sm:p-6">
                <div class="flex items-center">
                    <div class="p-2 mr-3 bg-blue-100 rounded-full sm:p-3 sm:mr-4">
                        <x-icon name="o-users" class="w-6 h-6 text-blue-600 sm:w-8 sm:h-8" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xl font-bold text-blue-600 sm:text-2xl">{{ number_format($stats['total_users']) }}</div>
                        <div class="text-xs text-gray-500 sm:text-sm">Total Users</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card class="transition-all duration-300 hover:shadow-lg">
            <div class="p-4 sm:p-6">
                <div class="flex items-center">
                    <div class="p-2 mr-3 bg-green-100 rounded-full sm:p-3 sm:mr-4">
                        <x-icon name="o-check-circle" class="w-6 h-6 text-green-600 sm:w-8 sm:h-8" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xl font-bold text-green-600 sm:text-2xl">{{ number_format($stats['active_users']) }}</div>
                        <div class="text-xs text-gray-500 sm:text-sm">Active Users</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card class="transition-all duration-300 hover:shadow-lg">
            <div class="p-4 sm:p-6">
                <div class="flex items-center">
                    <div class="p-2 mr-3 bg-orange-100 rounded-full sm:p-3 sm:mr-4">
                        <x-icon name="o-user-plus" class="w-6 h-6 text-orange-600 sm:w-8 sm:h-8" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xl font-bold text-orange-600 sm:text-2xl">{{ number_format($stats['recent_users']) }}</div>
                        <div class="text-xs text-gray-500 sm:text-sm">New (30 days)</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card class="transition-all duration-300 hover:shadow-lg">
            <div class="p-4 sm:p-6">
                <div class="flex items-center">
                    <div class="p-2 mr-3 bg-red-100 rounded-full sm:p-3 sm:mr-4">
                        <x-icon name="o-x-circle" class="w-6 h-6 text-red-600 sm:w-8 sm:h-8" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xl font-bold text-red-600 sm:text-2xl">{{ number_format($stats['suspended_users']) }}</div>
                        <div class="text-xs text-gray-500 sm:text-sm">Suspended</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Responsive Role Distribution Cards -->
    <div class="grid grid-cols-2 gap-3 mb-6 sm:grid-cols-4 sm:gap-4 sm:mb-8">
        <x-card class="transition-all duration-300 border-purple-200 bg-purple-50 hover:shadow-lg">
            <div class="p-3 text-center sm:p-4">
                <div class="text-lg font-bold text-purple-600 sm:text-2xl">{{ number_format($stats['admin_count']) }}</div>
                <div class="text-xs text-purple-600 sm:text-sm">Admins</div>
            </div>
        </x-card>

        <x-card class="transition-all duration-300 border-blue-200 bg-blue-50 hover:shadow-lg">
            <div class="p-3 text-center sm:p-4">
                <div class="text-lg font-bold text-blue-600 sm:text-2xl">{{ number_format($stats['teacher_count']) }}</div>
                <div class="text-xs text-blue-600 sm:text-sm">Teachers</div>
            </div>
        </x-card>

        <x-card class="transition-all duration-300 border-green-200 bg-green-50 hover:shadow-lg">
            <div class="p-3 text-center sm:p-4">
                <div class="text-lg font-bold text-green-600 sm:text-2xl">{{ number_format($stats['parent_count']) }}</div>
                <div class="text-xs text-green-600 sm:text-sm">Parents</div>
            </div>
        </x-card>

        <x-card class="transition-all duration-300 border-orange-200 bg-orange-50 hover:shadow-lg">
            <div class="p-3 text-center sm:p-4">
                <div class="text-lg font-bold text-orange-600 sm:text-2xl">{{ number_format($stats['student_count']) }}</div>
                <div class="text-xs text-orange-600 sm:text-sm">Students</div>
            </div>
        </x-card>
    </div>

    <!-- Responsive Bulk Actions -->
    @if(count($selectedUsers) > 0)
        <x-card class="mb-4 sm:mb-6">
            <div class="p-3 bg-blue-50 sm:p-4">
                <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-0">
                    <span class="text-sm font-medium text-blue-800">
                        {{ count($selectedUsers) }} user(s) selected
                    </span>
                    <div class="flex w-full gap-2 sm:w-auto">
                        <x-button
                            label="Activate"
                            icon="o-check"
                            wire:click="bulkActivate"
                            class="flex-1 btn-sm btn-success sm:flex-none"
                            wire:confirm="Are you sure you want to activate the selected users?"
                        />
                        <x-button
                            label="Deactivate"
                            icon="o-x-mark"
                            wire:click="bulkDeactivate"
                            class="flex-1 btn-sm btn-error sm:flex-none"
                            wire:confirm="Are you sure you want to deactivate the selected users?"
                        />
                    </div>
                </div>
            </div>
        </x-card>
    @endif

    <!-- Mobile Card View (shown on small screens) -->
    <div class="block mb-6 space-y-4 lg:hidden">
        @forelse($users as $user)
            <x-card class="transition-all duration-300 hover:shadow-lg">
                <div class="p-4">
                    <!-- User Info Header -->
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center flex-1 min-w-0">
                            <x-checkbox wire:model.live="selectedUsers" value="{{ $user->id }}" class="mr-3" />
                            <div class="avatar">
                                <div class="w-12 h-12 rounded-full">
                                    <img src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}" />
                                </div>
                            </div>
                            <div class="flex-1 min-w-0 ml-3">
                                <button wire:click="redirectToShow({{ $user->id }})" class="font-semibold text-left text-blue-600 underline hover:text-blue-800">
                                    {{ $user->name }}
                                </button>
                                @if($user->id === Auth::id())
                                    <div class="text-xs text-blue-500">(You)</div>
                                @endif
                                <div class="font-mono text-sm text-gray-600 truncate">{{ $user->email }}</div>
                            </div>
                        </div>
                        <div class="flex gap-1 ml-2">
                            <button
                                wire:click="redirectToShow({{ $user->id }})"
                                class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                title="View"
                            >
                                👁️
                            </button>
                            <button
                                wire:click="redirectToEdit({{ $user->id }})"
                                class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                title="Edit"
                            >
                                ✏️
                            </button>
                        </div>
                    </div>

                    <!-- User Details -->
                    <div class="space-y-2">
                        <!-- Status and Verification -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getStatusColor($user->status) }}">
                                    {{ ucfirst($user->status) }}
                                </span>
                                @if($user->email_verified_at)
                                    <span class="text-xs text-green-600">✓ Verified</span>
                                @else
                                    <span class="text-xs text-red-600">✗ Not verified</span>
                                @endif
                            </div>
                            @if($user->phone)
                                <div class="font-mono text-sm text-gray-600">{{ $user->phone }}</div>
                            @endif
                        </div>

                        <!-- Roles -->
                        <div>
                            <div class="mb-1 text-xs text-gray-500">Roles:</div>
                            <div class="flex flex-wrap gap-1">
                                @forelse($user->roles as $role)
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getRoleColor($role->name) }}">
                                        {{ ucfirst($role->name) }}
                                    </span>
                                @empty
                                    <span class="text-xs text-gray-500">No roles</span>
                                @endforelse
                            </div>
                        </div>

                        <!-- Last Login and Created -->
                        <div class="flex justify-between pt-2 text-xs text-gray-500 border-t">
                            <div>
                                Last login:
                                @if($user->last_login_at)
                                    {{ $user->last_login_at->format('M d, Y g:i A') }}
                                @else
                                    Never
                                @endif
                            </div>
                            <div>
                                Created: {{ $user->created_at->format('M d, Y') }}
                            </div>
                        </div>
                    </div>
                </div>
            </x-card>
        @empty
            <x-card>
                <div class="py-12 text-center">
                    <div class="flex flex-col items-center justify-center gap-4">
                        <x-icon name="o-users" class="w-16 h-16 text-gray-300" />
                        <div>
                            <h3 class="text-lg font-semibold text-gray-600">No users found</h3>
                            <p class="mt-1 text-gray-500">
                                @if($search || $roleFilter || $statusFilter)
                                    No users match your current filters.
                                @else
                                    Get started by creating your first user.
                                @endif
                            </p>
                        </div>
                        @if($search || $roleFilter || $statusFilter)
                            <x-button
                                label="Clear Filters"
                                wire:click="resetFilters"
                                color="secondary"
                                size="sm"
                            />
                        @else
                            <x-button
                                label="Create First User"
                                icon="o-plus"
                                wire:click="redirectToCreate"
                                color="primary"
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
                        <th class="w-12">
                            <x-checkbox wire:model.live="selectAll" />
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('name')">
                            <div class="flex items-center">
                                Name
                                @if ($sortBy['column'] === 'name')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('email')">
                            <div class="flex items-center">
                                Email
                                @if ($sortBy['column'] === 'email')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Roles</th>
                        <th class="hidden xl:table-cell">Phone</th>
                        <th class="cursor-pointer" wire:click="sortBy('status')">
                            <div class="flex items-center">
                                Status
                                @if ($sortBy['column'] === 'status')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="hidden cursor-pointer xl:table-cell" wire:click="sortBy('last_login_at')">
                            <div class="flex items-center">
                                Last Login
                                @if ($sortBy['column'] === 'last_login_at')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="hidden cursor-pointer 2xl:table-cell" wire:click="sortBy('created_at')">
                            <div class="flex items-center">
                                Created
                                @if ($sortBy['column'] === 'created_at')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="w-24 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr class="hover">
                            <td>
                                <x-checkbox wire:model.live="selectedUsers" value="{{ $user->id }}" />
                            </td>
                            <td>
                                <div class="flex items-center">
                                    <div class="avatar">
                                        <div class="w-10 h-10 rounded-full">
                                            <img src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}" />
                                        </div>
                                    </div>
                                    <div class="ml-3">
                                        <button wire:click="redirectToShow({{ $user->id }})" class="font-semibold text-blue-600 underline hover:text-blue-800">
                                            {{ $user->name }}
                                        </button>
                                        @if($user->id === Auth::id())
                                            <div class="text-xs text-blue-500">(You)</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="font-mono text-sm">{{ $user->email }}</div>
                                @if($user->email_verified_at)
                                    <div class="text-xs text-green-600">Verified</div>
                                @else
                                    <div class="text-xs text-red-600">Not verified</div>
                                @endif
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @forelse($user->roles as $role)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getRoleColor($role->name) }}">
                                            {{ ucfirst($role->name) }}
                                        </span>
                                    @empty
                                        <span class="text-sm text-gray-500">No roles</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="hidden xl:table-cell">
                                <div class="font-mono text-sm">{{ $user->phone ?: '-' }}</div>
                            </td>
                            <td>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($user->status) }}">
                                    {{ ucfirst($user->status) }}
                                </span>
                            </td>
                            <td class="hidden xl:table-cell">
                                @if($user->last_login_at)
                                    <div class="text-sm">{{ $user->last_login_at->format('M d, Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $user->last_login_at->format('g:i A') }}</div>
                                @else
                                    <span class="text-gray-500">Never</span>
                                @endif
                            </td>
                            <td class="hidden 2xl:table-cell">
                                <div class="text-sm">{{ $user->created_at->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $user->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <button
                                        wire:click="redirectToShow({{ $user->id }})"
                                        class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                        title="View"
                                    >
                                        👁️
                                    </button>
                                    <button
                                        wire:click="redirectToEdit({{ $user->id }})"
                                        class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                        title="Edit"
                                    >
                                        ✏️
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-users" class="w-20 h-20 text-gray-300" />
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-600">No users found</h3>
                                        <p class="mt-1 text-gray-500">
                                            @if($search || $roleFilter || $statusFilter)
                                                No users match your current filters.
                                            @else
                                                Get started by creating your first user.
                                            @endif
                                        </p>
                                    </div>
                                    @if($search || $roleFilter || $statusFilter)
                                        <x-button
                                            label="Clear Filters"
                                            wire:click="resetFilters"
                                            color="secondary"
                                            size="sm"
                                        />
                                    @else
                                        <x-button
                                            label="Create First User"
                                            icon="o-plus"
                                            wire:click="redirectToCreate"
                                            color="primary"
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
            {{ $users->links() }}
        </div>

        <!-- Results summary -->
        @if($users->count() > 0)
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $users->firstItem() ?? 0 }} to {{ $users->lastItem() ?? 0 }}
            of {{ $users->total() }} users
            @if($search || $roleFilter || $statusFilter)
                (filtered from total)
            @endif
        </div>
        @endif
    </x-card>

    <!-- Mobile/Tablet Pagination (shown when using card view) -->
    <div class="mt-4 lg:hidden">
        {{ $users->links() }}
    </div>

    <!-- Mobile Results Summary -->
    @if($users->count() > 0)
    <div class="pt-3 mt-4 text-sm text-center text-gray-600 border-t lg:hidden">
        Showing {{ $users->firstItem() ?? 0 }} to {{ $users->lastItem() ?? 0 }}
        of {{ $users->total() }} users
        @if($search || $roleFilter || $statusFilter)
            (filtered)
        @endif
    </div>
    @endif

    <!-- Responsive Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search users"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by name, email, or phone..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by role"
                    :options="$roleOptions"
                    wire:model.live="roleFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All roles"
                />
            </div>

            <div>
                <x-select
                    label="Filter by status"
                    :options="$statusOptions"
                    wire:model.live="statusFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All statuses"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[
                        ['id' => 10, 'name' => '10 per page'],
                        ['id' => 15, 'name' => '15 per page'],
                        ['id' => 25, 'name' => '25 per page'],
                        ['id' => 50, 'name' => '50 per page']
                    ]"
                    option-value="id"
                    option-label="name"
                    wire:model.live="perPage"
                />
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Reset" icon="o-x-mark" wire:click="resetFilters" class="w-full sm:w-auto" />
            <x-button label="Apply" icon="o-check" wire:click="$set('showFilters', false)" color="primary" class="w-full sm:w-auto" />
        </x-slot:actions>
    </x-drawer>
</div>
