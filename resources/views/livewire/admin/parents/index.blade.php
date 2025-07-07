<?php

use App\Models\User;
use App\Models\ParentProfile;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Parents Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $occupationFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Bulk actions
    public array $selectedParents = [];
    public bool $selectAll = false;

    // Stats
    public array $stats = [];

    // Filter options
    public array $statusOptions = [];
    public array $occupationOptions = [];

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed parents management page',
            ParentProfile::class,
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

        // Occupation options - get from database
        try {
            $occupations = ParentProfile::whereNotNull('occupation')
                ->distinct()
                ->pluck('occupation')
                ->filter()
                ->sort()
                ->values();

            $this->occupationOptions = [
                ['id' => '', 'name' => 'All Occupations'],
                ...$occupations->map(fn($occupation) => [
                    'id' => $occupation,
                    'name' => ucfirst($occupation)
                ])->toArray()
            ];
        } catch (\Exception $e) {
            $this->occupationOptions = [
                ['id' => '', 'name' => 'All Occupations'],
            ];
        }
    }

    protected function loadStats(): void
    {
        try {
            $totalParents = ParentProfile::count();
            $activeParents = ParentProfile::whereHas('user', function ($query) {
                $query->where('status', 'active');
            })->count();
            $inactiveParents = ParentProfile::whereHas('user', function ($query) {
                $query->where('status', 'inactive');
            })->count();
            $suspendedParents = ParentProfile::whereHas('user', function ($query) {
                $query->where('status', 'suspended');
            })->count();
            $recentParents = ParentProfile::where('created_at', '>=', now()->subDays(30))->count();

            // Get total children count
            $totalChildren = \App\Models\ChildProfile::count();

            // Get parents with most children
            $parentsWithChildren = ParentProfile::withCount('children')
                ->having('children_count', '>', 0)
                ->count();

            // Get occupation counts
            $occupationCounts = ParentProfile::whereNotNull('occupation')
                ->selectRaw('occupation, COUNT(*) as count')
                ->groupBy('occupation')
                ->orderByDesc('count')
                ->limit(5)
                ->get();

            $this->stats = [
                'total_parents' => $totalParents,
                'active_parents' => $activeParents,
                'inactive_parents' => $inactiveParents,
                'suspended_parents' => $suspendedParents,
                'recent_parents' => $recentParents,
                'total_children' => $totalChildren,
                'parents_with_children' => $parentsWithChildren,
                'occupation_counts' => $occupationCounts,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_parents' => 0,
                'active_parents' => 0,
                'inactive_parents' => 0,
                'suspended_parents' => 0,
                'recent_parents' => 0,
                'total_children' => 0,
                'parents_with_children' => 0,
                'occupation_counts' => collect(),
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

    // Redirect to show page
    public function redirectToShow(int $parentId): void
    {
        $this->redirect(route('admin.parents.show', $parentId));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedOccupationFilter(): void
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
        $this->occupationFilter = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    public function bulkActivate(): void
    {
        if (empty($this->selectedParents)) {
            $this->error('Please select parents to activate.');
            return;
        }

        try {
            $updated = User::whereHas('parentProfile', function ($query) {
                $query->whereIn('id', $this->selectedParents);
            })->where('status', '!=', 'active')
            ->update(['status' => 'active']);

            ActivityLog::log(
                Auth::id(),
                'bulk_update',
                "Bulk activated {$updated} parent(s)",
                ParentProfile::class,
                null,
                [
                    'parent_ids' => $this->selectedParents,
                    'action' => 'activate',
                    'count' => $updated
                ]
            );

            $this->success("Activated {$updated} parent(s).");
            $this->selectedParents = [];
            $this->selectAll = false;
            $this->loadStats();
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    public function bulkDeactivate(): void
    {
        if (empty($this->selectedParents)) {
            $this->error('Please select parents to deactivate.');
            return;
        }

        try {
            // Don't deactivate if the current user is among the selected parents
            $currentUserParent = ParentProfile::where('user_id', Auth::id())->first();
            $parentsToUpdate = $this->selectedParents;

            if ($currentUserParent && in_array($currentUserParent->id, $this->selectedParents)) {
                $parentsToUpdate = array_filter($this->selectedParents, fn($id) => $id != $currentUserParent->id);
                if (empty($parentsToUpdate)) {
                    $this->error('Cannot deactivate your own account.');
                    return;
                }
            }

            $updated = User::whereHas('parentProfile', function ($query) use ($parentsToUpdate) {
                $query->whereIn('id', $parentsToUpdate);
            })->where('status', '!=', 'inactive')
            ->update(['status' => 'inactive']);

            ActivityLog::log(
                Auth::id(),
                'bulk_update',
                "Bulk deactivated {$updated} parent(s)",
                ParentProfile::class,
                null,
                [
                    'parent_ids' => $parentsToUpdate,
                    'action' => 'deactivate',
                    'count' => $updated
                ]
            );

            $this->success("Deactivated {$updated} parent(s).");
            $this->selectedParents = [];
            $this->selectAll = false;
            $this->loadStats();
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Get filtered and paginated parents
    public function parents(): LengthAwarePaginator
    {
        return ParentProfile::query()
            ->with(['user', 'children'])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->whereHas('user', function ($userQuery) {
                        $userQuery->where('name', 'like', "%{$this->search}%")
                                 ->orWhere('email', 'like', "%{$this->search}%");
                    })
                    ->orWhere('phone', 'like', "%{$this->search}%")
                    ->orWhere('occupation', 'like', "%{$this->search}%")
                    ->orWhere('company', 'like', "%{$this->search}%");
                });
            })
            ->when($this->statusFilter, function (Builder $query) {
                $query->whereHas('user', function ($userQuery) {
                    $userQuery->where('status', $this->statusFilter);
                });
            })
            ->when($this->occupationFilter, function (Builder $query) {
                $query->where('occupation', $this->occupationFilter);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->occupationFilter = '';
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
            'parents' => $this->parents(),
        ];
    }
};?>

<div class="min-h-screen bg-gradient-to-br from-rose-50 via-pink-50 to-purple-50">
    <!-- Ultra-responsive page header -->
    <x-header title="Parents Management" subtitle="Manage parent profiles and their children" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input
                placeholder="Search parents..."
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
                :badge="count(array_filter([$occupationFilter, $statusFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300 text-xs sm:text-sm"
                responsive
            />

            <x-button
                label="View Children"
                icon="o-users"
                link="{{ route('admin.children.index') }}"
                class="btn-outline text-xs sm:text-sm hidden sm:flex"
                responsive
            />

            <x-button
                label="Create Parent"
                icon="o-plus"
                class="btn-primary text-xs sm:text-sm"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Adaptive container with responsive padding -->
    <div class="container mx-auto px-2 sm:px-4 md:px-6 lg:px-8 space-y-4 sm:space-y-6 lg:space-y-8">

        <!-- Ultra-responsive Stats Cards with enhanced animations -->
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 sm:gap-4 lg:gap-6">
            <x-card class="group transition-all duration-300 hover:shadow-xl hover:-translate-y-1 border-0 shadow-md bg-gradient-to-br from-blue-50 to-blue-100">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 bg-gradient-to-br from-blue-100 to-blue-200 rounded-full sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110 transition-transform duration-300">
                            <x-icon name="o-users" class="w-5 h-5 text-blue-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-lg font-bold text-blue-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['total_parents']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">Total Parents</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="group transition-all duration-300 hover:shadow-xl hover:-translate-y-1 border-0 shadow-md bg-gradient-to-br from-green-50 to-green-100">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 bg-gradient-to-br from-green-100 to-green-200 rounded-full sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110 transition-transform duration-300">
                            <x-icon name="o-check-circle" class="w-5 h-5 text-green-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-lg font-bold text-green-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['active_parents']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">Active Parents</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="group transition-all duration-300 hover:shadow-xl hover:-translate-y-1 border-0 shadow-md bg-gradient-to-br from-orange-50 to-orange-100">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 bg-gradient-to-br from-orange-100 to-orange-200 rounded-full sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110 transition-transform duration-300">
                            <x-icon name="o-user-plus" class="w-5 h-5 text-orange-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-lg font-bold text-orange-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['recent_parents']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">New (30 days)</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="group transition-all duration-300 hover:shadow-xl hover:-translate-y-1 border-0 shadow-md bg-gradient-to-br from-purple-50 to-purple-100">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 bg-gradient-to-br from-purple-100 to-purple-200 rounded-full sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110 transition-transform duration-300">
                            <x-icon name="o-heart" class="w-5 h-5 text-purple-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-lg font-bold text-purple-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['total_children']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">Total Children</div>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- Hyper-responsive Occupation Distribution with smart wrapping -->
        @if($stats['occupation_counts']->count() > 0)
        <div class="overflow-hidden">
            <h3 class="text-sm font-semibold text-gray-700 mb-3 sm:text-base lg:text-lg flex items-center">
                <x-icon name="o-briefcase" class="w-4 h-4 mr-2 text-indigo-600" />
                Occupation Distribution
            </h3>
            <div class="flex overflow-x-auto space-x-2 pb-3 sm:grid sm:grid-cols-2 sm:gap-3 sm:space-x-0 sm:overflow-visible sm:pb-0 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6">
                @foreach($stats['occupation_counts'] as $occupation)
                <x-card class="flex-shrink-0 w-32 sm:w-auto transition-all duration-300 border-indigo-200 bg-gradient-to-br from-indigo-50 to-purple-50 hover:shadow-lg hover:scale-105">
                    <div class="p-3 text-center sm:p-4">
                        <div class="text-lg font-bold text-indigo-600 sm:text-xl lg:text-2xl">{{ number_format($occupation->count) }}</div>
                        <div class="text-xs text-indigo-600 sm:text-sm truncate" title="{{ ucfirst($occupation->occupation) }}">{{ ucfirst($occupation->occupation) }}</div>
                    </div>
                </x-card>
                @endforeach
            </div>
        </div>
        @endif

        <!-- Enhanced Bulk Actions with micro-interactions -->
        @if(count($selectedParents) > 0)
            <x-card class="border-blue-200 shadow-lg animate-fade-in">
                <div class="p-3 bg-gradient-to-r from-blue-50 to-indigo-50 sm:p-4">
                    <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-0">
                        <div class="flex items-center gap-2">
                            <x-icon name="o-check-circle" class="w-4 h-4 text-blue-600" />
                            <span class="text-sm font-medium text-blue-800">
                                {{ count($selectedParents) }} parent(s) selected
                            </span>
                        </div>
                        <div class="flex w-full gap-2 sm:w-auto">
                            <x-button
                                label="Activate"
                                icon="o-check"
                                wire:click="bulkActivate"
                                class="flex-1 btn-sm btn-success hover:scale-105 transition-transform sm:flex-none"
                                wire:confirm="Are you sure you want to activate the selected parents?"
                            />
                            <x-button
                                label="Deactivate"
                                icon="o-x-mark"
                                wire:click="bulkDeactivate"
                                class="flex-1 btn-sm btn-error hover:scale-105 transition-transform sm:flex-none"
                                wire:confirm="Are you sure you want to deactivate the selected parents?"
                            />
                        </div>
                    </div>
                </div>
            </x-card>
        @endif

        <!-- Ultra-responsive Mobile Card View with enhanced interactions -->
        <div class="block space-y-3 lg:hidden sm:space-y-4">
            @forelse($parents as $parent)
                <x-card class="group transition-all duration-300 hover:shadow-xl hover:-translate-y-1 border-0 shadow-md overflow-hidden">
                    <div class="p-3 sm:p-4">
                        <!-- Enhanced Parent Info Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center flex-1 min-w-0">
                                <x-checkbox
                                    wire:model.live="selectedParents"
                                    value="{{ $parent->id }}"
                                    class="mr-2 sm:mr-3 scale-110 hover:scale-125 transition-transform"
                                />
                                <div class="avatar group-hover:scale-110 transition-transform duration-300">
                                    <div class="w-10 h-10 rounded-full ring-2 ring-transparent group-hover:ring-purple-200 transition-all duration-300 sm:w-12 sm:h-12">
                                        <img src="{{ $parent->user->profile_photo_url }}" alt="{{ $parent->user->name }}" />
                                    </div>
                                </div>
                                <div class="ml-2 min-w-0 flex-1 sm:ml-3">
                                    <button wire:click="redirectToShow({{ $parent->id }})" class="font-semibold text-purple-600 underline hover:text-purple-800 text-left text-sm sm:text-base group-hover:text-purple-700 transition-colors">
                                        {{ $parent->user->name }}
                                    </button>
                                    @if($parent->user_id === Auth::id())
                                        <div class="text-xs text-purple-500 font-medium">(You)</div>
                                    @endif
                                    <div class="text-xs text-gray-600 font-mono truncate sm:text-sm">{{ $parent->user->email }}</div>
                                </div>
                            </div>
                            <div class="flex gap-1 ml-2">
                                <button
                                    wire:click="redirectToShow({{ $parent->id }})"
                                    class="p-1.5 sm:p-2 text-gray-600 bg-gray-100 rounded-lg hover:text-gray-900 hover:bg-gray-200 hover:scale-110 transition-all duration-200"
                                    title="View"
                                >
                                    <span class="text-sm">👁️</span>
                                </button>
                                <button
                                    class="p-1.5 sm:p-2 text-purple-600 bg-purple-100 rounded-lg hover:text-purple-900 hover:bg-purple-200 hover:scale-110 transition-all duration-200"
                                    title="Edit"
                                >
                                    <span class="text-sm">✏️</span>
                                </button>
                            </div>
                        </div>

                        <!-- Enhanced Parent Details with better spacing -->
                        <div class="space-y-2">
                            <!-- Status and Verification Row -->
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium transition-all duration-200 hover:scale-105 {{ $this->getStatusColor($parent->user->status) }}">
                                        {{ ucfirst($parent->user->status) }}
                                    </span>
                                    @if($parent->user->email_verified_at)
                                        <span class="text-xs text-green-600 bg-green-50 px-2 py-1 rounded-full">✓ Verified</span>
                                    @else
                                        <span class="text-xs text-red-600 bg-red-50 px-2 py-1 rounded-full">✗ Not verified</span>
                                    @endif
                                </div>
                                @if($parent->phone || $parent->user->phone)
                                    <div class="text-xs text-gray-600 font-mono bg-gray-50 px-2 py-1 rounded sm:text-sm">{{ $parent->phone ?: $parent->user->phone }}</div>
                                @endif
                            </div>

                            <!-- Occupation and Company -->
                            <div class="bg-gray-50 rounded-lg p-2">
                                <div class="text-xs text-gray-500 mb-1">Occupation:</div>
                                @if($parent->occupation)
                                    <div class="text-sm font-medium text-gray-800">{{ $parent->occupation }}</div>
                                    @if($parent->company)
                                        <div class="text-xs text-gray-600 mt-1">at {{ $parent->company }}</div>
                                    @endif
                                @else
                                    <span class="text-xs text-gray-500 italic">Not specified</span>
                                @endif
                            </div>

                            <!-- Children Count with enhanced styling -->
                            <div class="bg-purple-50 rounded-lg p-2">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-heart" class="w-4 h-4 text-purple-600" />
                                        <span class="text-sm font-medium text-purple-800">
                                            {{ $parent->children->count() }}
                                            {{ Str::plural('Child', $parent->children->count()) }}
                                        </span>
                                    </div>
                                    @if($parent->children->count() > 0)
                                        <x-button
                                            label="View"
                                            icon="o-eye"
                                            link="{{ route('admin.children.index', ['parent' => $parent->id]) }}"
                                            class="btn-xs btn-ghost text-purple-600 hover:scale-105 transition-transform"
                                        />
                                    @endif
                                </div>
                            </div>

                            <!-- Created Date with better formatting -->
                            <div class="flex justify-between text-xs text-gray-500 pt-2 border-t border-gray-100">
                                <div class="flex items-center gap-1">
                                    <x-icon name="o-calendar" class="w-3 h-3" />
                                    <span>{{ $parent->created_at->format('M d, Y') }}</span>
                                </div>
                                <div class="bg-gray-100 px-2 py-1 rounded">
                                    {{ $parent->created_at->diffForHumans() }}
                                </div>
                            </div>
                        </div>
                    </div>
                </x-card>
            @empty
                <x-card class="border-dashed border-2 border-gray-200">
                    <div class="py-8 sm:py-12 text-center">
                        <div class="flex flex-col items-center justify-center gap-3 sm:gap-4">
                            <div class="p-4 bg-gradient-to-br from-purple-100 to-pink-100 rounded-full">
                                <x-icon name="o-users" class="w-12 h-12 text-purple-400 sm:w-16 sm:h-16" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-600 sm:text-lg">No parents found</h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    @if($search || $occupationFilter || $statusFilter)
                                        No parents match your current filters.
                                    @else
                                        Get started by creating your first parent profile.
                                    @endif
                                </p>
                            </div>
                            @if($search || $occupationFilter || $statusFilter)
                                <x-button
                                    label="Clear Filters"
                                    wire:click="resetFilters"
                                    color="secondary"
                                    size="sm"
                                    class="hover:scale-105 transition-transform"
                                />
                            @else
                                <x-button
                                    label="Create First Parent"
                                    icon="o-plus"
                                    color="primary"
                                    class="hover:scale-105 transition-transform"
                                />
                            @endif
                        </div>
                    </div>
                </x-card>
            @endforelse
        </div>

        <!-- Enhanced Desktop Table View with better responsive behavior -->
        <x-card class="hidden lg:block border-0 shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table w-full table-zebra">
                    <thead class="bg-gradient-to-r from-gray-50 to-purple-50">
                        <tr>
                            <th class="w-12">
                                <x-checkbox wire:model.live="selectAll" class="scale-110 hover:scale-125 transition-transform" />
                            </th>
                            <th class="cursor-pointer hover:bg-purple-100 transition-colors" wire:click="sortBy('user.name')">
                                <div class="flex items-center">
                                    Name
                                    @if ($sortBy['column'] === 'user.name')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-purple-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="cursor-pointer hover:bg-purple-100 transition-colors" wire:click="sortBy('user.email')">
                                <div class="flex items-center">
                                    Email
                                    @if ($sortBy['column'] === 'user.email')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-purple-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="hidden xl:table-cell">Phone</th>
                            <th class="cursor-pointer hover:bg-purple-100 transition-colors" wire:click="sortBy('occupation')">
                                <div class="flex items-center">
                                    Occupation
                                    @if ($sortBy['column'] === 'occupation')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-purple-600" />
                                    @endif
                                </div>
                            </th>
                            <th>Children</th>
                            <th class="cursor-pointer hover:bg-purple-100 transition-colors" wire:click="sortBy('user.status')">
                                <div class="flex items-center">
                                    Status
                                    @if ($sortBy['column'] === 'user.status')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-purple-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="cursor-pointer hover:bg-purple-100 transition-colors hidden 2xl:table-cell" wire:click="sortBy('created_at')">
                                <div class="flex items-center">
                                    Created
                                    @if ($sortBy['column'] === 'created_at')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-purple-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="text-right w-24">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($parents as $parent)
                            <tr class="hover:bg-purple-50 transition-colors">
                                <td>
                                    <x-checkbox wire:model.live="selectedParents" value="{{ $parent->id }}" class="scale-110 hover:scale-125 transition-transform" />
                                </td>
                                <td>
                                    <div class="flex items-center">
                                        <div class="avatar hover:scale-110 transition-transform duration-300">
                                            <div class="w-10 h-10 rounded-full ring-2 ring-transparent hover:ring-purple-200 transition-all">
                                                <img src="{{ $parent->user->profile_photo_url }}" alt="{{ $parent->user->name }}" />
                                            </div>
                                        </div>
                                        <div class="ml-3">
                                            <button wire:click="redirectToShow({{ $parent->id }})" class="font-semibold text-purple-600 underline hover:text-purple-800 transition-colors">
                                                {{ $parent->user->name }}
                                            </button>
                                            @if($parent->user_id === Auth::id())
                                                <div class="text-xs text-purple-500 font-medium">(You)</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="font-mono text-sm">{{ $parent->user->email }}</div>
                                    @if($parent->user->email_verified_at)
                                        <div class="text-xs text-green-600 bg-green-50 px-2 py-1 rounded-full inline-block">Verified</div>
                                    @else
                                        <div class="text-xs text-red-600 bg-red-50 px-2 py-1 rounded-full inline-block">Not verified</div>
                                    @endif
                                </td>
                                <td class="hidden xl:table-cell">
                                    <div class="font-mono text-sm">{{ $parent->phone ?: $parent->user->phone ?: '-' }}</div>
                                </td>
                                <td>
                                    @if($parent->occupation)
                                        <div class="text-sm font-medium">{{ $parent->occupation }}</div>
                                        @if($parent->company)
                                            <div class="text-xs text-gray-500 bg-gray-50 px-2 py-1 rounded inline-block mt-1">{{ $parent->company }}</div>
                                        @endif
                                    @else
                                        <span class="text-sm text-gray-500 italic">Not specified</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center">
                                        <div class="flex items-center gap-2 bg-purple-50 px-2 py-1 rounded">
                                            <x-icon name="o-heart" class="w-4 h-4 text-purple-600" />
                                            <span class="text-sm font-medium text-purple-800">{{ $parent->children->count() }}</span>
                                        </div>
                                        @if($parent->children->count() > 0)
                                            <div class="ml-2">
                                                <x-button
                                                    label="View"
                                                    icon="o-eye"
                                                    link="{{ route('admin.children.index', ['parent' => $parent->id]) }}"
                                                    class="btn-xs btn-ghost text-purple-600 hover:scale-105 transition-transform"
                                                />
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium hover:scale-105 transition-transform {{ $this->getStatusColor($parent->user->status) }}">
                                        {{ ucfirst($parent->user->status) }}
                                    </span>
                                </td>
                                <td class="hidden 2xl:table-cell">
                                    <div class="text-sm">{{ $parent->created_at->format('M d, Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $parent->created_at->diffForHumans() }}</div>
                                </td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            wire:click="redirectToShow({{ $parent->id }})"
                                            class="p-2 text-gray-600 bg-gray-100 rounded-lg hover:text-gray-900 hover:bg-gray-200 hover:scale-110 transition-all duration-200"
                                            title="View"
                                        >
                                            👁️
                                        </button>
                                        <button
                                            class="p-2 text-purple-600 bg-purple-100 rounded-lg hover:text-purple-900 hover:bg-purple-200 hover:scale-110 transition-all duration-200"
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
                                        <div class="p-4 bg-gradient-to-br from-purple-100 to-pink-100 rounded-full">
                                            <x-icon name="o-users" class="w-20 h-20 text-purple-300" />
                                        </div>
                                        <div>
                                            <h3 class="text-lg font-semibold text-gray-600">No parents found</h3>
                                            <p class="mt-1 text-gray-500">
                                                @if($search || $occupationFilter || $statusFilter)
                                                    No parents match your current filters.
                                                @else
                                                    Get started by creating your first parent profile.
                                                @endif
                                            </p>
                                        </div>
                                        @if($search || $occupationFilter || $statusFilter)
                                            <x-button
                                                label="Clear Filters"
                                                wire:click="resetFilters"
                                                color="secondary"
                                                size="sm"
                                                class="hover:scale-105 transition-transform"
                                            />
                                        @else
                                            <x-button
                                                label="Create First Parent"
                                                icon="o-plus"
                                                color="primary"
                                                class="hover:scale-105 transition-transform"
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
            <div class="mt-4 p-4 bg-gradient-to-r from-gray-50 to-purple-50">
                {{ $parents->links() }}
            </div>

            <!-- Enhanced Results summary -->
            @if($parents->count() > 0)
            <div class="pt-3 mt-4 text-sm text-gray-600 border-t bg-gradient-to-r from-gray-50 to-purple-50 px-4 pb-4">
                <div class="flex items-center justify-between">
                    <span>
                        Showing {{ $parents->firstItem() ?? 0 }} to {{ $parents->lastItem() ?? 0 }}
                        of {{ $parents->total() }} parents
                        @if($search || $occupationFilter || $statusFilter)
                            (filtered from total)
                        @endif
                    </span>
                    <div class="text-xs text-gray-500">
                        Page {{ $parents->currentPage() }} of {{ $parents->lastPage() }}
                    </div>
                </div>
            </div>
            @endif
        </x-card>

        <!-- Enhanced Mobile/Tablet Pagination -->
        <div class="lg:hidden">
            {{ $parents->links() }}
        </div>

        <!-- Enhanced Mobile Results Summary -->
        @if($parents->count() > 0)
        <div class="pt-3 text-sm text-center text-gray-600 border-t lg:hidden bg-white rounded-lg p-4 shadow-sm">
            <div class="space-y-1">
                <div>
                    Showing {{ $parents->firstItem() ?? 0 }} to {{ $parents->lastItem() ?? 0 }}
                    of {{ $parents->total() }} parents
                </div>
                @if($search || $occupationFilter || $statusFilter)
                    <div class="text-xs text-purple-600">(filtered results)</div>
                @endif
                <div class="text-xs text-gray-500">
                    Page {{ $parents->currentPage() }} of {{ $parents->lastPage() }}
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
                    label="Search parents"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by name, email, phone, occupation..."
                    clearable
                    class="w-full"
                />
            </div>

            <div>
                <x-select
                    label="Filter by occupation"
                    :options="$occupationOptions"
                    wire:model.live="occupationFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All occupations"
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
                class="w-full sm:w-auto mb-2 sm:mb-0 hover:scale-105 transition-transform"
            />
            <x-button
                label="Apply"
                icon="o-check"
                wire:click="$set('showFilters', false)"
                color="primary"
                class="w-full sm:w-auto hover:scale-105 transition-transform"
            />
        </x-slot:actions>
    </x-drawer>

    <!-- Enhanced Floating Action Button with micro-interactions -->
    <div class="fixed bottom-4 right-4 lg:hidden z-50">
        <div class="flex flex-col gap-3">
            <!-- Primary Action -->
            <button
                class="group relative flex items-center justify-center w-14 h-14 bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-full shadow-lg hover:shadow-xl transform hover:scale-110 transition-all duration-300 animate-bounce-slow"
                title="Create Parent"
            >
                <x-icon name="o-plus" class="w-6 h-6 group-hover:rotate-90 transition-transform duration-300" />

                <!-- Ripple effect -->
                <div class="absolute inset-0 rounded-full bg-white opacity-0 group-hover:opacity-20 group-hover:scale-150 transition-all duration-300"></div>
            </button>

            <!-- Secondary Actions -->
            <div class="flex flex-col gap-2">
                <!-- View Children -->
                <button
                    onclick="window.location.href='{{ route('admin.children.index') }}'"
                    class="group relative flex items-center justify-center w-12 h-12 bg-gradient-to-r from-pink-500 to-rose-500 text-white rounded-full shadow-lg hover:shadow-xl transform hover:scale-110 transition-all duration-300"
                    title="View Children"
                >
                    <x-icon name="o-heart" class="w-5 h-5 group-hover:pulse transition-transform duration-300" />
                </button>

                <!-- Filters -->
                <button
                    @click="$wire.showFilters = true"
                    class="group relative flex items-center justify-center w-12 h-12 bg-gradient-to-r from-gray-600 to-gray-700 text-white rounded-full shadow-lg hover:shadow-xl transform hover:scale-110 transition-all duration-300"
                    title="Open Filters"
                >
                    <x-icon name="o-funnel" class="w-5 h-5 group-hover:rotate-12 transition-transform duration-300" />
                    @if(count(array_filter([$occupationFilter, $statusFilter])) > 0)
                        <div class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 rounded-full text-xs flex items-center justify-center text-white animate-pulse">
                            {{ count(array_filter([$occupationFilter, $statusFilter])) }}
                        </div>
                    @endif
                </button>
            </div>
        </div>
    </div>

    <!-- Enhanced Mobile Navigation Helper -->
    <div class="block mt-6 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-purple-50 to-pink-50">
            <div class="p-4">
                <h3 class="text-sm font-bold text-gray-800 mb-3 flex items-center">
                    <x-icon name="o-eye" class="w-4 h-4 mr-2 text-purple-600" />
                    Quick Actions
                </h3>
                <div class="grid grid-cols-2 gap-3">
                    <button
                        class="group flex items-center justify-center p-3 text-xs font-medium text-purple-800 transition-all duration-300 bg-gradient-to-r from-purple-100 to-purple-200 rounded-xl hover:from-purple-200 hover:to-purple-300 hover:scale-105 hover:shadow-md"
                    >
                        <x-icon name="o-plus" class="w-4 h-4 mr-2 group-hover:rotate-90 transition-transform duration-300" />
                        Add Parent
                    </button>
                    <button
                        onclick="window.location.href='{{ route('admin.children.index') }}'"
                        class="group flex items-center justify-center p-3 text-xs font-medium transition-all duration-300 text-pink-800 bg-gradient-to-r from-pink-100 to-pink-200 rounded-xl hover:from-pink-200 hover:to-pink-300 hover:scale-105 hover:shadow-md"
                    >
                        <x-icon name="o-heart" class="w-4 h-4 mr-2 group-hover:pulse transition-transform duration-300" />
                        View Children
                    </button>
                    <button
                        @click="$wire.showFilters = true"
                        class="group flex items-center justify-center p-3 text-xs font-medium transition-all duration-300 text-indigo-800 bg-gradient-to-r from-indigo-100 to-indigo-200 rounded-xl hover:from-indigo-200 hover:to-indigo-300 hover:scale-105 hover:shadow-md"
                    >
                        <x-icon name="o-funnel" class="w-4 h-4 mr-2 group-hover:rotate-12 transition-transform duration-300" />
                        Filters
                        @if(count(array_filter([$occupationFilter, $statusFilter])) > 0)
                            <span class="ml-1 px-1.5 py-0.5 bg-indigo-600 text-white rounded-full text-xs">
                                {{ count(array_filter([$occupationFilter, $statusFilter])) }}
                            </span>
                        @endif
                    </button>
                    <button
                        class="group flex items-center justify-center p-3 text-xs font-medium transition-all duration-300 text-emerald-800 bg-gradient-to-r from-emerald-100 to-emerald-200 rounded-xl hover:from-emerald-200 hover:to-emerald-300 hover:scale-105 hover:shadow-md"
                    >
                        <x-icon name="o-chart-bar" class="w-4 h-4 mr-2 group-hover:scale-110 transition-transform duration-300" />
                        Reports
                    </button>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Parent-Child Relationship Guide (mobile only) -->
    <div class="mt-4 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-pink-50 to-purple-50">
            <div class="p-4">
                <div class="flex items-start">
                    <div class="p-2 bg-pink-100 rounded-full mr-3">
                        <x-icon name="o-heart" class="w-5 h-5 text-pink-600" />
                    </div>
                    <div class="flex-1">
                        <h4 class="font-semibold text-pink-800 mb-2">Parent Information</h4>
                        <div class="space-y-2">
                            <div class="flex items-center gap-3 p-2 bg-white rounded-lg">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
                                <span class="text-sm text-gray-700">Can access parent portal and manage children</span>
                            </div>
                            <div class="flex items-center gap-3 p-2 bg-white rounded-lg">
                                <div class="flex items-center gap-1">
                                    <x-icon name="o-heart" class="w-3 h-3 text-purple-600" />
                                    <span class="text-xs font-medium text-purple-800">Children</span>
                                </div>
                                <span class="text-sm text-gray-700">Click to view associated children profiles</span>
                            </div>
                            <div class="flex items-center gap-3 p-2 bg-white rounded-lg">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">✓ Verified</span>
                                <span class="text-sm text-gray-700">Email address has been verified</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Advanced Filter Summary (Mobile) -->
    @if($search || $occupationFilter || $statusFilter)
    <div class="mt-4 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-amber-50 to-orange-50">
            <div class="p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h4 class="font-semibold text-amber-800 mb-2 flex items-center">
                            <x-icon name="o-funnel" class="w-4 h-4 mr-2" />
                            Active Filters
                        </h4>
                        <div class="space-y-1">
                            @if($search)
                                <div class="text-sm text-amber-700">
                                    <strong>Search:</strong> "{{ $search }}"
                                </div>
                            @endif
                            @if($occupationFilter)
                                <div class="text-sm text-amber-700">
                                    <strong>Occupation:</strong> {{ collect($occupationOptions)->firstWhere('id', $occupationFilter)['name'] ?? $occupationFilter }}
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
                        class="px-3 py-1 text-xs font-medium text-red-800 bg-red-100 rounded-full hover:bg-red-200 transition-colors"
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
    <div class="hidden sm:block mt-6">
        <details class="group sm:hidden md:block">
            <summary class="flex items-center justify-between p-4 font-medium text-gray-900 bg-gradient-to-r from-purple-50 to-pink-50 border border-purple-200 rounded-lg cursor-pointer hover:from-purple-100 hover:to-pink-100 sm:hidden">
                <span class="flex items-center">
                    <x-icon name="o-chart-bar" class="w-5 h-5 mr-2 text-purple-600" />
                    Family Overview
                </span>
                <svg class="w-5 h-5 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </summary>
            <div class="mt-2 sm:mt-0">
                <x-card class="border-0 shadow-lg bg-gradient-to-r from-purple-50 to-pink-50">
                    <div class="p-4 md:p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                            <x-icon name="o-chart-bar" class="w-5 h-5 mr-2 text-purple-600" />
                            Family Statistics
                        </h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="text-center p-3 bg-white rounded-lg shadow-sm border border-purple-100">
                                <div class="text-2xl font-bold text-purple-600">
                                    {{ number_format(($stats['active_parents'] / max($stats['total_parents'], 1)) * 100, 1) }}%
                                </div>
                                <div class="text-xs text-gray-600">Active Rate</div>
                            </div>
                            <div class="text-center p-3 bg-white rounded-lg shadow-sm border border-pink-100">
                                <div class="text-2xl font-bold text-pink-600">
                                    {{ number_format($stats['total_children'] / max($stats['total_parents'], 1), 1) }}
                                </div>
                                <div class="text-xs text-gray-600">Avg Children/Parent</div>
                            </div>
                            <div class="text-center p-3 bg-white rounded-lg shadow-sm border border-orange-100">
                                <div class="text-2xl font-bold text-orange-600">
                                    {{ number_format($stats['recent_parents']) }}
                                </div>
                                <div class="text-xs text-gray-600">New This Month</div>
                            </div>
                            <div class="text-center p-3 bg-white rounded-lg shadow-sm border border-indigo-100">
                                <div class="text-2xl font-bold text-indigo-600">
                                    {{ $stats['occupation_counts']->count() }}
                                </div>
                                <div class="text-xs text-gray-600">Occupations</div>
                            </div>
                        </div>
                    </div>
                </x-card>
            </div>
        </details>
    </div>


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

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.group-hover\:pulse:hover {
    animation: pulse 1s ease-in-out infinite;
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
    outline: 2px solid #9333EA;
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

/* Custom gradients for parent theme */
.bg-parent-gradient {
    background: linear-gradient(135deg, #fdf2f8 0%, #fce7f3 50%, #f3e8ff 100%);
}

.text-parent-primary {
    color: #9333ea;
}

.border-parent-primary {
    border-color: #9333ea;
}
</style>
