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

<div class="min-h-screen bg-gradient-to-br from-slate-50 to-blue-50">
    <!-- Ultra-responsive page header -->
    <x-header title="Teachers Management" subtitle="Manage teacher profiles and their information" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input
                placeholder="Search teachers..."
                wire:model.live.debounce="search"
                icon="o-magnifying-glass"
                clearable
                class="w-full min-w-0 sm:w-48 md:w-64 lg:w-80 xl:w-96"
            />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$specializationFilter, $statusFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="text-xs bg-base-300 sm:text-sm"
                responsive
            />

            <x-button
                label="Create Teacher"
                icon="o-plus"
                wire:click="redirectToCreate"
                class="text-xs btn-primary sm:text-sm"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Adaptive container with responsive padding -->
    <div class="container px-2 mx-auto space-y-4 sm:px-4 md:px-6 lg:px-8 sm:space-y-6 lg:space-y-8">

        <!-- Ultra-responsive Stats Cards with micro-animations -->
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 sm:gap-4 lg:gap-6">
            <x-card class="transition-all duration-300 border-0 shadow-md group hover:shadow-xl hover:-translate-y-1">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 transition-transform duration-300 rounded-full bg-gradient-to-br from-blue-100 to-blue-200 sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110">
                            <x-icon name="o-academic-cap" class="w-5 h-5 text-blue-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-blue-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['total_teachers']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">Total Teachers</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 border-0 shadow-md group hover:shadow-xl hover:-translate-y-1">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 transition-transform duration-300 rounded-full bg-gradient-to-br from-green-100 to-green-200 sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110">
                            <x-icon name="o-check-circle" class="w-5 h-5 text-green-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-green-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['active_teachers']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">Active Teachers</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 border-0 shadow-md group hover:shadow-xl hover:-translate-y-1">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 transition-transform duration-300 rounded-full bg-gradient-to-br from-orange-100 to-orange-200 sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110">
                            <x-icon name="o-user-plus" class="w-5 h-5 text-orange-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-orange-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['recent_teachers']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">New (30 days)</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 border-0 shadow-md group hover:shadow-xl hover:-translate-y-1">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 transition-transform duration-300 rounded-full bg-gradient-to-br from-red-100 to-red-200 sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110">
                            <x-icon name="o-x-circle" class="w-5 h-5 text-red-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-red-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['suspended_teachers']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">Suspended</div>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- Hyper-responsive Specialization Distribution with smart wrapping -->
        @if($stats['specialization_counts']->count() > 0)
        <div class="overflow-hidden">
            <h3 class="mb-3 text-sm font-semibold text-gray-700 sm:text-base lg:text-lg">Specialization Distribution</h3>
            <div class="flex pb-3 space-x-2 overflow-x-auto sm:grid sm:grid-cols-2 sm:gap-3 sm:space-x-0 sm:overflow-visible sm:pb-0 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6">
                @foreach($stats['specialization_counts'] as $spec)
                <x-card class="flex-shrink-0 w-32 transition-all duration-300 border-blue-200 sm:w-auto bg-gradient-to-br from-blue-50 to-indigo-50 hover:shadow-lg hover:scale-105">
                    <div class="p-3 text-center sm:p-4">
                        <div class="text-lg font-bold text-blue-600 sm:text-xl lg:text-2xl">{{ number_format($spec->count) }}</div>
                        <div class="text-xs text-blue-600 truncate sm:text-sm" title="{{ ucfirst($spec->specialization) }}">{{ ucfirst($spec->specialization) }}</div>
                    </div>
                </x-card>
                @endforeach
            </div>
        </div>
        @endif

        <!-- Enhanced Bulk Actions with micro-interactions -->
        @if(count($selectedTeachers) > 0)
            <x-card class="border-blue-200 shadow-lg animate-fade-in">
                <div class="p-3 bg-gradient-to-r from-blue-50 to-indigo-50 sm:p-4">
                    <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-0">
                        <div class="flex items-center gap-2">
                            <x-icon name="o-check-circle" class="w-4 h-4 text-blue-600" />
                            <span class="text-sm font-medium text-blue-800">
                                {{ count($selectedTeachers) }} teacher(s) selected
                            </span>
                        </div>
                        <div class="flex w-full gap-2 sm:w-auto">
                            <x-button
                                label="Activate"
                                icon="o-check"
                                wire:click="bulkActivate"
                                class="flex-1 transition-transform btn-sm btn-success hover:scale-105 sm:flex-none"
                                wire:confirm="Are you sure you want to activate the selected teachers?"
                            />
                            <x-button
                                label="Deactivate"
                                icon="o-x-mark"
                                wire:click="bulkDeactivate"
                                class="flex-1 transition-transform btn-sm btn-error hover:scale-105 sm:flex-none"
                                wire:confirm="Are you sure you want to deactivate the selected teachers?"
                            />
                        </div>
                    </div>
                </div>
            </x-card>
        @endif

        <!-- Ultra-responsive Mobile Card View with enhanced interactions -->
        <div class="block space-y-3 lg:hidden sm:space-y-4">
            @forelse($teachers as $teacher)
                <x-card class="overflow-hidden transition-all duration-300 border-0 shadow-md group hover:shadow-xl hover:-translate-y-1">
                    <div class="p-3 sm:p-4">
                        <!-- Enhanced Teacher Info Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center flex-1 min-w-0">
                                <x-checkbox
                                    wire:model.live="selectedTeachers"
                                    value="{{ $teacher->id }}"
                                    class="mr-2 transition-transform scale-110 sm:mr-3 hover:scale-125"
                                />
                                <div class="transition-transform duration-300 avatar group-hover:scale-110">
                                    <div class="w-10 h-10 transition-all duration-300 rounded-full ring-2 ring-transparent group-hover:ring-blue-200 sm:w-12 sm:h-12">
                                        <img src="{{ $teacher->user->profile_photo_url }}" alt="{{ $teacher->user->name }}" />
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0 ml-2 sm:ml-3">
                                    <button wire:click="redirectToShow({{ $teacher->id }})" class="text-sm font-semibold text-left text-blue-600 underline transition-colors hover:text-blue-800 sm:text-base group-hover:text-blue-700">
                                        {{ $teacher->user->name }}
                                    </button>
                                    @if($teacher->user_id === Auth::id())
                                        <div class="text-xs font-medium text-blue-500">(You)</div>
                                    @endif
                                    <div class="font-mono text-xs text-gray-600 truncate sm:text-sm">{{ $teacher->user->email }}</div>
                                </div>
                            </div>
                            <div class="flex gap-1 ml-2">
                                <button
                                    wire:click="redirectToShow({{ $teacher->id }})"
                                    class="p-1.5 sm:p-2 text-gray-600 bg-gray-100 rounded-lg hover:text-gray-900 hover:bg-gray-200 hover:scale-110 transition-all duration-200"
                                    title="View"
                                >
                                    <span class="text-sm">👁️</span>
                                </button>
                                <button
                                    wire:click="redirectToEdit({{ $teacher->id }})"
                                    class="p-1.5 sm:p-2 text-blue-600 bg-blue-100 rounded-lg hover:text-blue-900 hover:bg-blue-200 hover:scale-110 transition-all duration-200"
                                    title="Edit"
                                >
                                    <span class="text-sm">✏️</span>
                                </button>
                            </div>
                        </div>

                        <!-- Enhanced Teacher Details with better spacing -->
                        <div class="space-y-2">
                            <!-- Status and Verification Row -->
                            <div class="flex items-center justify-between">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium transition-all duration-200 hover:scale-105 {{ $this->getStatusColor($teacher->user->status) }}">
                                        {{ ucfirst($teacher->user->status) }}
                                    </span>
                                    @if($teacher->user->email_verified_at)
                                        <span class="px-2 py-1 text-xs text-green-600 rounded-full bg-green-50">✓ Verified</span>
                                    @else
                                        <span class="px-2 py-1 text-xs text-red-600 rounded-full bg-red-50">✗ Not verified</span>
                                    @endif
                                </div>
                                @if($teacher->phone || $teacher->user->phone)
                                    <div class="px-2 py-1 font-mono text-xs text-gray-600 rounded bg-gray-50 sm:text-sm">{{ $teacher->phone ?: $teacher->user->phone }}</div>
                                @endif
                            </div>

                            <!-- Specialization with enhanced styling -->
                            <div class="p-2 rounded-lg bg-gray-50">
                                <div class="mb-1 text-xs text-gray-500">Specialization:</div>
                                @if($teacher->specialization)
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-800 transition-transform rounded-full bg-gradient-to-r from-blue-100 to-indigo-100 hover:scale-105">
                                        {{ ucfirst($teacher->specialization) }}
                                    </span>
                                @else
                                    <span class="text-xs italic text-gray-500">Not specified</span>
                                @endif
                            </div>

                            <!-- Created Date with better formatting -->
                            <div class="flex justify-between pt-2 text-xs text-gray-500 border-t border-gray-100">
                                <div class="flex items-center gap-1">
                                    <x-icon name="o-calendar" class="w-3 h-3" />
                                    <span>{{ $teacher->created_at->format('M d, Y') }}</span>
                                </div>
                                <div class="px-2 py-1 bg-gray-100 rounded">
                                    {{ $teacher->created_at->diffForHumans() }}
                                </div>
                            </div>
                        </div>
                    </div>
                </x-card>
            @empty
                <x-card class="border-2 border-gray-200 border-dashed">
                    <div class="py-8 text-center sm:py-12">
                        <div class="flex flex-col items-center justify-center gap-3 sm:gap-4">
                            <div class="p-4 bg-gray-100 rounded-full">
                                <x-icon name="o-academic-cap" class="w-12 h-12 text-gray-400 sm:w-16 sm:h-16" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-600 sm:text-lg">No teachers found</h3>
                                <p class="mt-1 text-sm text-gray-500">
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
                                    class="transition-transform hover:scale-105"
                                />
                            @else
                                <x-button
                                    label="Create First Teacher"
                                    icon="o-plus"
                                    wire:click="redirectToCreate"
                                    color="primary"
                                    class="transition-transform hover:scale-105"
                                />
                            @endif
                        </div>
                    </div>
                </x-card>
            @endforelse
        </div>

        <!-- Enhanced Desktop Table View with better responsive behavior -->
        <x-card class="hidden overflow-hidden border-0 shadow-lg lg:block">
            <div class="overflow-x-auto">
                <table class="table w-full table-zebra">
                    <thead class="bg-gradient-to-r from-gray-50 to-blue-50">
                        <tr>
                            <th class="w-12">
                                <x-checkbox wire:model.live="selectAll" class="transition-transform scale-110 hover:scale-125" />
                            </th>
                            <th class="transition-colors cursor-pointer hover:bg-blue-100" wire:click="sortBy('user.name')">
                                <div class="flex items-center">
                                    Name
                                    @if ($sortBy['column'] === 'user.name')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-blue-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="transition-colors cursor-pointer hover:bg-blue-100" wire:click="sortBy('user.email')">
                                <div class="flex items-center">
                                    Email
                                    @if ($sortBy['column'] === 'user.email')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-blue-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="transition-colors cursor-pointer hover:bg-blue-100" wire:click="sortBy('specialization')">
                                <div class="flex items-center">
                                    Specialization
                                    @if ($sortBy['column'] === 'specialization')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-blue-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="hidden xl:table-cell">Phone</th>
                            <th class="transition-colors cursor-pointer hover:bg-blue-100" wire:click="sortBy('user.status')">
                                <div class="flex items-center">
                                    Status
                                    @if ($sortBy['column'] === 'user.status')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-blue-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="hidden transition-colors cursor-pointer hover:bg-blue-100 2xl:table-cell" wire:click="sortBy('created_at')">
                                <div class="flex items-center">
                                    Created
                                    @if ($sortBy['column'] === 'created_at')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-blue-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="w-24 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($teachers as $teacher)
                            <tr class="transition-colors hover:bg-blue-50">
                                <td>
                                    <x-checkbox wire:model.live="selectedTeachers" value="{{ $teacher->id }}" class="transition-transform scale-110 hover:scale-125" />
                                </td>
                                <td>
                                    <div class="flex items-center">
                                        <div class="transition-transform duration-300 avatar hover:scale-110">
                                            <div class="w-10 h-10 transition-all rounded-full ring-2 ring-transparent hover:ring-blue-200">
                                                <img src="{{ $teacher->user->profile_photo_url }}" alt="{{ $teacher->user->name }}" />
                                            </div>
                                        </div>
                                        <div class="ml-3">
                                            <button wire:click="redirectToShow({{ $teacher->id }})" class="font-semibold text-blue-600 underline transition-colors hover:text-blue-800">
                                                {{ $teacher->user->name }}
                                            </button>
                                            @if($teacher->user_id === Auth::id())
                                                <div class="text-xs font-medium text-blue-500">(You)</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="font-mono text-sm">{{ $teacher->user->email }}</div>
                                    @if($teacher->user->email_verified_at)
                                        <div class="inline-block px-2 py-1 text-xs text-green-600 rounded-full bg-green-50">Verified</div>
                                    @else
                                        <div class="inline-block px-2 py-1 text-xs text-red-600 rounded-full bg-red-50">Not verified</div>
                                    @endif
                                </td>
                                <td>
                                    @if($teacher->specialization)
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-800 transition-transform rounded-full bg-gradient-to-r from-blue-100 to-indigo-100 hover:scale-105">
                                            {{ ucfirst($teacher->specialization) }}
                                        </span>
                                    @else
                                        <span class="text-sm italic text-gray-500">Not specified</span>
                                    @endif
                                </td>
                                <td class="hidden xl:table-cell">
                                    <div class="font-mono text-sm">{{ $teacher->phone ?: $teacher->user->phone ?: '-' }}</div>
                                </td>
                                <td>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium hover:scale-105 transition-transform {{ $this->getStatusColor($teacher->user->status) }}">
                                        {{ ucfirst($teacher->user->status) }}
                                    </span>
                                </td>
                                <td class="hidden 2xl:table-cell">
                                    <div class="text-sm">{{ $teacher->created_at->format('M d, Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $teacher->created_at->diffForHumans() }}</div>
                                </td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            wire:click="redirectToShow({{ $teacher->id }})"
                                            class="p-2 text-gray-600 transition-all duration-200 bg-gray-100 rounded-lg hover:text-gray-900 hover:bg-gray-200 hover:scale-110"
                                            title="View"
                                        >
                                            👁️
                                        </button>
                                        <button
                                            wire:click="redirectToEdit({{ $teacher->id }})"
                                            class="p-2 text-blue-600 transition-all duration-200 bg-blue-100 rounded-lg hover:text-blue-900 hover:bg-blue-200 hover:scale-110"
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
                                        <div class="p-4 bg-gray-100 rounded-full">
                                            <x-icon name="o-academic-cap" class="w-20 h-20 text-gray-300" />
                                        </div>
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
                                                class="transition-transform hover:scale-105"
                                            />
                                        @else
                                            <x-button
                                                label="Create First Teacher"
                                                icon="o-plus"
                                                wire:click="redirectToCreate"
                                                color="primary"
                                                class="transition-transform hover:scale-105"
                                            />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Enhanced Pagination -->
            <div class="p-4 mt-4 bg-gray-50">
                {{ $teachers->links() }}
            </div>

            <!-- Enhanced Results summary -->
            @if($teachers->count() > 0)
            <div class="px-4 pt-3 pb-4 mt-4 text-sm text-gray-600 border-t bg-gray-50">
                <div class="flex items-center justify-between">
                    <span>
                        Showing {{ $teachers->firstItem() ?? 0 }} to {{ $teachers->lastItem() ?? 0 }}
                        of {{ $teachers->total() }} teachers
                        @if($search || $specializationFilter || $statusFilter)
                            (filtered from total)
                        @endif
                    </span>
                    <div class="text-xs text-gray-500">
                        Page {{ $teachers->currentPage() }} of {{ $teachers->lastPage() }}
                    </div>
                </div>
            </div>
            @endif
        </x-card>

        <!-- Enhanced Mobile/Tablet Pagination -->
        <div class="lg:hidden">
            {{ $teachers->links() }}
        </div>

        <!-- Enhanced Mobile Results Summary -->
        @if($teachers->count() > 0)
        <div class="p-4 pt-3 text-sm text-center text-gray-600 bg-white border-t rounded-lg shadow-sm lg:hidden">
            <div class="space-y-1">
                <div>
                    Showing {{ $teachers->firstItem() ?? 0 }} to {{ $teachers->lastItem() ?? 0 }}
                    of {{ $teachers->total() }} teachers
                </div>
                @if($search || $specializationFilter || $statusFilter)
                    <div class="text-xs text-blue-600">(filtered results)</div>
                @endif
                <div class="text-xs text-gray-500">
                    Page {{ $teachers->currentPage() }} of {{ $teachers->lastPage() }}
                </div>
            </div>
        </div>
        @endif
    </div>

    <!-- Enhanced Responsive Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search teachers"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by name, email, specialization..."
                    clearable
                    class="w-full"
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
                    class="w-full"
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
                    class="w-full"
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
                    class="w-full"
                />
            </div>
        </div>

        <x-slot:actions>
            <x-button
                label="Reset"
                icon="o-x-mark"
                wire:click="resetFilters"
                class="w-full mb-2 transition-transform sm:w-auto sm:mb-0 hover:scale-105"
            />
            <x-button
                label="Apply"
                icon="o-check"
                wire:click="$set('showFilters', false)"
                color="primary"
                class="w-full transition-transform sm:w-auto hover:scale-105"
            />
        </x-slot:actions>
    </x-drawer>

    <!-- Enhanced Floating Action Button with micro-interactions -->
    <div class="fixed z-50 bottom-4 right-4 lg:hidden">
        <div class="flex flex-col gap-3">
            <!-- Primary Action -->
            <button
                wire:click="redirectToCreate"
                class="relative flex items-center justify-center text-white transition-all duration-300 transform rounded-full shadow-lg group w-14 h-14 bg-gradient-to-r from-blue-600 to-indigo-600 hover:shadow-xl hover:scale-110 animate-bounce-slow"
                title="Create Teacher"
            >
                <x-icon name="o-plus" class="w-6 h-6 transition-transform duration-300 group-hover:rotate-90" />

                <!-- Ripple effect -->
                <div class="absolute inset-0 transition-all duration-300 bg-white rounded-full opacity-0 group-hover:opacity-20 group-hover:scale-150"></div>
            </button>

            <!-- Secondary Action (Filters) -->
            <button
                @click="$wire.showFilters = true"
                class="relative flex items-center justify-center w-12 h-12 text-white transition-all duration-300 transform rounded-full shadow-lg group bg-gradient-to-r from-gray-600 to-gray-700 hover:shadow-xl hover:scale-110"
                title="Open Filters"
            >
                <x-icon name="o-funnel" class="w-5 h-5 transition-transform duration-300 group-hover:rotate-12" />
                @if(count(array_filter([$specializationFilter, $statusFilter])) > 0)
                    <div class="absolute flex items-center justify-center w-4 h-4 text-xs text-white bg-red-500 rounded-full -top-1 -right-1 animate-pulse">
                        {{ count(array_filter([$specializationFilter, $statusFilter])) }}
                    </div>
                @endif
            </button>
        </div>
    </div>

    <!-- Enhanced Mobile Navigation Helper -->
    <div class="block mt-6 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-gray-50 to-blue-50">
            <div class="p-4">
                <h3 class="flex items-center mb-3 text-sm font-bold text-gray-800">
                    <x-icon name="o-eye" class="w-4 h-4 mr-2 text-blue-600" />
                    Quick Actions
                </h3>
                <div class="grid grid-cols-2 gap-3">
                    <button
                        wire:click="redirectToCreate"
                        class="flex items-center justify-center p-3 text-xs font-medium text-blue-800 transition-all duration-300 group bg-gradient-to-r from-blue-100 to-blue-200 rounded-xl hover:from-blue-200 hover:to-blue-300 hover:scale-105 hover:shadow-md"
                    >
                        <x-icon name="o-plus" class="w-4 h-4 mr-2 transition-transform duration-300 group-hover:rotate-90" />
                        Add Teacher
                    </button>
                    <button
                        @click="$wire.showFilters = true"
                        class="flex items-center justify-center p-3 text-xs font-medium transition-all duration-300 group text-emerald-800 bg-gradient-to-r from-emerald-100 to-emerald-200 rounded-xl hover:from-emerald-200 hover:to-emerald-300 hover:scale-105 hover:shadow-md"
                    >
                        <x-icon name="o-funnel" class="w-4 h-4 mr-2 transition-transform duration-300 group-hover:rotate-12" />
                        Filters
                        @if(count(array_filter([$specializationFilter, $statusFilter])) > 0)
                            <span class="ml-1 px-1.5 py-0.5 bg-emerald-600 text-white rounded-full text-xs">
                                {{ count(array_filter([$specializationFilter, $statusFilter])) }}
                            </span>
                        @endif
                    </button>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Enhanced Status Information Panel -->
    <div class="mt-4 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-blue-50 to-indigo-50">
            <div class="p-4">
                <div class="flex items-start">
                    <div class="p-2 mr-3 bg-blue-100 rounded-full">
                        <x-icon name="o-information-circle" class="w-5 h-5 text-blue-600" />
                    </div>
                    <div class="flex-1">
                        <h4 class="mb-2 font-semibold text-blue-800">Teacher Status Guide</h4>
                        <div class="space-y-2">
                            <div class="flex items-center gap-3 p-2 bg-white rounded-lg">
                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-green-800 bg-green-100 rounded-full">Active</span>
                                <span class="text-sm text-gray-700">Can teach and access all features</span>
                            </div>
                            <div class="flex items-center gap-3 p-2 bg-white rounded-lg">
                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-red-800 bg-red-100 rounded-full">Suspended</span>
                                <span class="text-sm text-gray-700">Temporarily blocked from teaching</span>
                            </div>
                            <div class="flex items-center gap-3 p-2 bg-white rounded-lg">
                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-800 bg-gray-100 rounded-full">Inactive</span>
                                <span class="text-sm text-gray-700">Account disabled</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Advanced Filter Summary (Mobile) -->
    @if($search || $specializationFilter || $statusFilter)
    <div class="mt-4 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-amber-50 to-orange-50">
            <div class="p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h4 class="flex items-center mb-2 font-semibold text-amber-800">
                            <x-icon name="o-funnel" class="w-4 h-4 mr-2" />
                            Active Filters
                        </h4>
                        <div class="space-y-1">
                            @if($search)
                                <div class="text-sm text-amber-700">
                                    <strong>Search:</strong> "{{ $search }}"
                                </div>
                            @endif
                            @if($specializationFilter)
                                <div class="text-sm text-amber-700">
                                    <strong>Specialization:</strong> {{ collect($specializationOptions)->firstWhere('id', $specializationFilter)['name'] ?? $specializationFilter }}
                                </div>
                            @endif
                            @if($statusFilter)
                                <div class="text-sm text-amber-700">
                                    <strong>Status:</strong> {{ collect($statusOptions)->firstWhere('id', $statusFilter)['name'] ?? $statusFilter }}
                                </div>
                            @endif
                        </div>
                    </div>
                    <button
                        wire:click="resetFilters"
                        class="px-3 py-1 text-xs font-medium text-red-800 transition-colors bg-red-100 rounded-full hover:bg-red-200"
                        title="Clear all filters"
                    >
                        Clear All
                    </button>
                </div>
            </div>
        </x-card>
    </div>
    @endif

    <!-- Performance Statistics (Hidden on mobile, collapsible on tablet) -->
    <div class="hidden mt-6 sm:block">
        <details class="group sm:hidden md:block">
            <summary class="flex items-center justify-between p-4 font-medium text-gray-900 border border-gray-200 rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100 sm:hidden">
                <span class="flex items-center">
                    <x-icon name="o-chart-bar" class="w-5 h-5 mr-2 text-gray-600" />
                    Performance Overview
                </span>
                <svg class="w-5 h-5 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </summary>
            <div class="mt-2 sm:mt-0">
                <x-card class="border-0 shadow-lg bg-gradient-to-r from-purple-50 to-pink-50">
                    <div class="p-4 md:p-6">
                        <h3 class="flex items-center mb-4 text-lg font-bold text-gray-800">
                            <x-icon name="o-chart-bar" class="w-5 h-5 mr-2 text-purple-600" />
                            System Performance
                        </h3>
                        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                            <div class="p-3 text-center bg-white rounded-lg shadow-sm">
                                <div class="text-2xl font-bold text-purple-600">
                                    {{ number_format(($stats['active_teachers'] / max($stats['total_teachers'], 1)) * 100, 1) }}%
                                </div>
                                <div class="text-xs text-gray-600">Active Rate</div>
                            </div>
                            <div class="p-3 text-center bg-white rounded-lg shadow-sm">
                                <div class="text-2xl font-bold text-green-600">
                                    {{ number_format($stats['recent_teachers']) }}
                                </div>
                                <div class="text-xs text-gray-600">New This Month</div>
                            </div>
                            <div class="p-3 text-center bg-white rounded-lg shadow-sm">
                                <div class="text-2xl font-bold text-blue-600">
                                    {{ $stats['specialization_counts']->count() }}
                                </div>
                                <div class="text-xs text-gray-600">Specializations</div>
                            </div>
                            <div class="p-3 text-center bg-white rounded-lg shadow-sm">
                                <div class="text-2xl font-bold text-orange-600">
                                    {{ number_format(($stats['suspended_teachers'] / max($stats['total_teachers'], 1)) * 100, 1) }}%
                                </div>
                                <div class="text-xs text-gray-600">Suspended Rate</div>
                            </div>
                        </div>
                    </div>
                </x-card>
            </div>
        </details>
    </div>

    <!-- Loading States and Animations -->

</div>

<style>
/* Custom animations */
@keyframes bounce-slow {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
}

.animate-bounce-slow {
    animation: bounce-slow 3s ease-in-out infinite;
}

@keyframes fade-in {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.animate-fade-in {
    animation: fade-in 0.3s ease-out;
}

/* Enhanced hover effects */
.hover-lift:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
}

/* Smooth transitions for all interactive elements */
* {
    transition-property: transform, box-shadow, background-color, border-color;
    transition-duration: 200ms;
    transition-timing-function: ease-in-out;
}

/* Enhanced focus states for accessibility */
button:focus,
input:focus,
select:focus {
    outline: 2px solid #3B82F6;
    outline-offset: 2px;
}

/* Mobile-optimized touch targets */
@media (max-width: 768px) {
    button, .clickable {
        min-height: 44px;
        min-width: 44px;
    }
}

/* Responsive text scaling */
@media (max-width: 640px) {
    .responsive-text {
        font-size: 0.875rem;
    }
}

@media (min-width: 640px) {
    .responsive-text {
        font-size: 1rem;
    }
}

@media (min-width: 1024px) {
    .responsive-text {
        font-size: 1.125rem;
    }
}
</style>
