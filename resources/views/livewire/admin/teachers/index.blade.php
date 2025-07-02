<?php

use App\Models\User;
use App\Models\TeacherProfile;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Teachers Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $specializationFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Bulk actions
    public array $selectedTeachers = [];
    public bool $selectAll = false;

    // Stats
    public array $stats = [];

    // Filter options
    public array $statusOptions = [];
    public array $specializationOptions = [];

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed teachers management page',
            TeacherProfile::class,
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

        // Specialization options - get from database
        try {
            $specializations = TeacherProfile::whereNotNull('specialization')
                ->distinct()
                ->pluck('specialization')
                ->filter()
                ->sort()
                ->values();

            $this->specializationOptions = [
                ['id' => '', 'name' => 'All Specializations'],
                ...$specializations->map(fn($spec) => [
                    'id' => $spec,
                    'name' => ucfirst($spec)
                ])->toArray()
            ];
        } catch (\Exception $e) {
            $this->specializationOptions = [
                ['id' => '', 'name' => 'All Specializations'],
            ];
        }
    }

    protected function loadStats(): void
    {
        try {
            $totalTeachers = TeacherProfile::count();
            $activeTeachers = TeacherProfile::whereHas('user', function ($query) {
                $query->where('status', 'active');
            })->count();
            $inactiveTeachers = TeacherProfile::whereHas('user', function ($query) {
                $query->where('status', 'inactive');
            })->count();
            $suspendedTeachers = TeacherProfile::whereHas('user', function ($query) {
                $query->where('status', 'suspended');
            })->count();
            $recentTeachers = TeacherProfile::where('created_at', '>=', now()->subDays(30))->count();

            // Get specialization counts
            $specializationCounts = TeacherProfile::whereNotNull('specialization')
                ->selectRaw('specialization, COUNT(*) as count')
                ->groupBy('specialization')
                ->orderByDesc('count')
                ->limit(5)
                ->get();

            $this->stats = [
                'total_teachers' => $totalTeachers,
                'active_teachers' => $activeTeachers,
                'inactive_teachers' => $inactiveTeachers,
                'suspended_teachers' => $suspendedTeachers,
                'recent_teachers' => $recentTeachers,
                'specialization_counts' => $specializationCounts,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_teachers' => 0,
                'active_teachers' => 0,
                'inactive_teachers' => 0,
                'suspended_teachers' => 0,
                'recent_teachers' => 0,
                'specialization_counts' => collect(),
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
        $this->redirect(route('admin.teachers.create'));
    }

    // Redirect to show page
    public function redirectToShow(int $teacherId): void
    {
        $this->redirect(route('admin.teachers.show', $teacherId));
    }

    // Redirect to edit page
    public function redirectToEdit(int $teacherId): void
    {
        $this->redirect(route('admin.teachers.edit', $teacherId));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSpecializationFilter(): void
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
        $this->specializationFilter = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    public function bulkActivate(): void
    {
        if (empty($this->selectedTeachers)) {
            $this->error('Please select teachers to activate.');
            return;
        }

        try {
            $updated = User::whereHas('teacherProfile', function ($query) {
                $query->whereIn('id', $this->selectedTeachers);
            })->where('status', '!=', 'active')
            ->update(['status' => 'active']);

            ActivityLog::log(
                Auth::id(),
                'bulk_update',
                "Bulk activated {$updated} teacher(s)",
                TeacherProfile::class,
                null,
                [
                    'teacher_ids' => $this->selectedTeachers,
                    'action' => 'activate',
                    'count' => $updated
                ]
            );

            $this->success("Activated {$updated} teacher(s).");
            $this->selectedTeachers = [];
            $this->selectAll = false;
            $this->loadStats();
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    public function bulkDeactivate(): void
    {
        if (empty($this->selectedTeachers)) {
            $this->error('Please select teachers to deactivate.');
            return;
        }

        try {
            // Don't deactivate if the current user is among the selected teachers
            $currentUserTeacher = TeacherProfile::where('user_id', Auth::id())->first();
            $teachersToUpdate = $this->selectedTeachers;

            if ($currentUserTeacher && in_array($currentUserTeacher->id, $this->selectedTeachers)) {
                $teachersToUpdate = array_filter($this->selectedTeachers, fn($id) => $id != $currentUserTeacher->id);
                if (empty($teachersToUpdate)) {
                    $this->error('Cannot deactivate your own account.');
                    return;
                }
            }

            $updated = User::whereHas('teacherProfile', function ($query) use ($teachersToUpdate) {
                $query->whereIn('id', $teachersToUpdate);
            })->where('status', '!=', 'inactive')
            ->update(['status' => 'inactive']);

            ActivityLog::log(
                Auth::id(),
                'bulk_update',
                "Bulk deactivated {$updated} teacher(s)",
                TeacherProfile::class,
                null,
                [
                    'teacher_ids' => $teachersToUpdate,
                    'action' => 'deactivate',
                    'count' => $updated
                ]
            );

            $this->success("Deactivated {$updated} teacher(s).");
            $this->selectedTeachers = [];
            $this->selectAll = false;
            $this->loadStats();
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Get filtered and paginated teachers
    public function teachers(): LengthAwarePaginator
    {
        return TeacherProfile::query()
            ->with(['user'])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->whereHas('user', function ($userQuery) {
                        $userQuery->where('name', 'like', "%{$this->search}%")
                                 ->orWhere('email', 'like', "%{$this->search}%");
                    })
                    ->orWhere('specialization', 'like', "%{$this->search}%")
                    ->orWhere('phone', 'like', "%{$this->search}%");
                });
            })
            ->when($this->statusFilter, function (Builder $query) {
                $query->whereHas('user', function ($userQuery) {
                    $userQuery->where('status', $this->statusFilter);
                });
            })
            ->when($this->specializationFilter, function (Builder $query) {
                $query->where('specialization', $this->specializationFilter);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->specializationFilter = '';
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

    public function with(): array
    {
        return [
            'teachers' => $this->teachers(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Teachers Management" subtitle="Manage teacher profiles and their information" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search teachers..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$specializationFilter, $statusFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="Create Teacher"
                icon="o-plus"
                wire:click="redirectToCreate"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-4">
        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-blue-100 rounded-full">
                        <x-icon name="o-academic-cap" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_teachers']) }}</div>
                        <div class="text-sm text-gray-500">Total Teachers</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-green-100 rounded-full">
                        <x-icon name="o-check-circle" class="w-8 h-8 text-green-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['active_teachers']) }}</div>
                        <div class="text-sm text-gray-500">Active Teachers</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-orange-100 rounded-full">
                        <x-icon name="o-user-plus" class="w-8 h-8 text-orange-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['recent_teachers']) }}</div>
                        <div class="text-sm text-gray-500">New (30 days)</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-red-100 rounded-full">
                        <x-icon name="o-x-circle" class="w-8 h-8 text-red-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-red-600">{{ number_format($stats['suspended_teachers']) }}</div>
                        <div class="text-sm text-gray-500">Suspended</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Specialization Distribution Cards -->
    @if($stats['specialization_counts']->count() > 0)
    <div class="grid grid-cols-1 gap-4 mb-8 md:grid-cols-5">
        @foreach($stats['specialization_counts'] as $spec)
        <x-card class="border-blue-200 bg-blue-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($spec->count) }}</div>
                <div class="text-sm text-blue-600">{{ ucfirst($spec->specialization) }}</div>
            </div>
        </x-card>
        @endforeach
    </div>
    @endif

    <!-- Bulk Actions -->
    @if(count($selectedTeachers) > 0)
        <x-card class="mb-6">
            <div class="p-4 bg-blue-50">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-blue-800">
                        {{ count($selectedTeachers) }} teacher(s) selected
                    </span>
                    <div class="flex gap-2">
                        <x-button
                            label="Activate"
                            icon="o-check"
                            wire:click="bulkActivate"
                            class="btn-sm btn-success"
                            wire:confirm="Are you sure you want to activate the selected teachers?"
                        />
                        <x-button
                            label="Deactivate"
                            icon="o-x-mark"
                            wire:click="bulkDeactivate"
                            class="btn-sm btn-error"
                            wire:confirm="Are you sure you want to deactivate the selected teachers?"
                        />
                    </div>
                </div>
            </div>
        </x-card>
    @endif

    <!-- Teachers Table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th>
                            <x-checkbox wire:model.live="selectAll" />
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('user.name')">
                            <div class="flex items-center">
                                Name
                                @if ($sortBy['column'] === 'user.name')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('user.email')">
                            <div class="flex items-center">
                                Email
                                @if ($sortBy['column'] === 'user.email')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('specialization')">
                            <div class="flex items-center">
                                Specialization
                                @if ($sortBy['column'] === 'specialization')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Phone</th>
                        <th class="cursor-pointer" wire:click="sortBy('user.status')">
                            <div class="flex items-center">
                                Status
                                @if ($sortBy['column'] === 'user.status')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('created_at')">
                            <div class="flex items-center">
                                Created
                                @if ($sortBy['column'] === 'created_at')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($teachers as $teacher)
                        <tr class="hover">
                            <td>
                                <x-checkbox wire:model.live="selectedTeachers" value="{{ $teacher->id }}" />
                            </td>
                            <td>
                                <div class="flex items-center">
                                    <div class="avatar">
                                        <div class="w-10 h-10 rounded-full">
                                            <img src="{{ $teacher->user->profile_photo_url }}" alt="{{ $teacher->user->name }}" />
                                        </div>
                                    </div>
                                    <div class="ml-3">
                                        <button wire:click="redirectToShow({{ $teacher->id }})" class="font-semibold text-blue-600 underline hover:text-blue-800">
                                            {{ $teacher->user->name }}
                                        </button>
                                        @if($teacher->user_id === Auth::id())
                                            <div class="text-xs text-blue-500">(You)</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="font-mono text-sm">{{ $teacher->user->email }}</div>
                                @if($teacher->user->email_verified_at)
                                    <div class="text-xs text-green-600">Verified</div>
                                @else
                                    <div class="text-xs text-red-600">Not verified</div>
                                @endif
                            </td>
                            <td>
                                @if($teacher->specialization)
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        {{ ucfirst($teacher->specialization) }}
                                    </span>
                                @else
                                    <span class="text-sm text-gray-500">Not specified</span>
                                @endif
                            </td>
                            <td>
                                <div class="font-mono text-sm">{{ $teacher->phone ?: $teacher->user->phone ?: '-' }}</div>
                            </td>
                            <td>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($teacher->user->status) }}">
                                    {{ ucfirst($teacher->user->status) }}
                                </span>
                            </td>
                            <td>
                                <div class="text-sm">{{ $teacher->created_at->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $teacher->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <button
                                        wire:click="redirectToShow({{ $teacher->id }})"
                                        class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                        title="View"
                                    >
                                        👁️
                                    </button>
                                    <button
                                        wire:click="redirectToEdit({{ $teacher->id }})"
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
                            <td colspan="8" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-academic-cap" class="w-20 h-20 text-gray-300" />
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-600">No teachers found</h3>
                                        <p class="mt-1 text-gray-500">
                                            @if($search || $specializationFilter || $statusFilter)
                                                No teachers match your current filters.
                                            @else
                                                Get started by creating your first teacher profile.
                                            @endif
                                        </p>
                                    </div>
                                    @if($search || $specializationFilter || $statusFilter)
                                        <x-button
                                            label="Clear Filters"
                                            wire:click="resetFilters"
                                            color="secondary"
                                            size="sm"
                                        />
                                    @else
                                        <x-button
                                            label="Create First Teacher"
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
            {{ $teachers->links() }}
        </div>

        <!-- Results summary -->
        @if($teachers->count() > 0)
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $teachers->firstItem() ?? 0 }} to {{ $teachers->lastItem() ?? 0 }}
            of {{ $teachers->total() }} teachers
            @if($search || $specializationFilter || $statusFilter)
                (filtered from total)
            @endif
        </div>
        @endif
    </x-card>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search teachers"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by name, email, specialization..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by specialization"
                    :options="$specializationOptions"
                    wire:model.live="specializationFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All specializations"
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
            <x-button label="Reset" icon="o-x-mark" wire:click="resetFilters" />
            <x-button label="Apply" icon="o-check" wire:click="$set('showFilters', false)" color="primary" />
        </x-slot:actions>
    </x-drawer>
</div>
