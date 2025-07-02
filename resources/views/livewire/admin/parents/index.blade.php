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

<div>
    <!-- Page header -->
    <x-header title="Parents Management" subtitle="Manage parent profiles and their children" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search parents..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$occupationFilter, $statusFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="View Children"
                icon="o-users"
                link="{{ route('admin.children.index') }}"
                class="btn-outline"
                responsive />

            <x-button
                label="Create Parent"
                icon="o-plus"
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
                        <x-icon name="o-users" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_parents']) }}</div>
                        <div class="text-sm text-gray-500">Total Parents</div>
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
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['active_parents']) }}</div>
                        <div class="text-sm text-gray-500">Active Parents</div>
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
                        <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['recent_parents']) }}</div>
                        <div class="text-sm text-gray-500">New (30 days)</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-heart" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['total_children']) }}</div>
                        <div class="text-sm text-gray-500">Total Children</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Occupation Distribution Cards -->
    @if($stats['occupation_counts']->count() > 0)
    <div class="grid grid-cols-1 gap-4 mb-8 md:grid-cols-5">
        @foreach($stats['occupation_counts'] as $occupation)
        <x-card class="border-indigo-200 bg-indigo-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-indigo-600">{{ number_format($occupation->count) }}</div>
                <div class="text-sm text-indigo-600">{{ ucfirst($occupation->occupation) }}</div>
            </div>
        </x-card>
        @endforeach
    </div>
    @endif

    <!-- Bulk Actions -->
    @if(count($selectedParents) > 0)
        <x-card class="mb-6">
            <div class="p-4 bg-blue-50">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-blue-800">
                        {{ count($selectedParents) }} parent(s) selected
                    </span>
                    <div class="flex gap-2">
                        <x-button
                            label="Activate"
                            icon="o-check"
                            wire:click="bulkActivate"
                            class="btn-sm btn-success"
                            wire:confirm="Are you sure you want to activate the selected parents?"
                        />
                        <x-button
                            label="Deactivate"
                            icon="o-x-mark"
                            wire:click="bulkDeactivate"
                            class="btn-sm btn-error"
                            wire:confirm="Are you sure you want to deactivate the selected parents?"
                        />
                    </div>
                </div>
            </div>
        </x-card>
    @endif

    <!-- Parents Table -->
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
                        <th>Phone</th>
                        <th class="cursor-pointer" wire:click="sortBy('occupation')">
                            <div class="flex items-center">
                                Occupation
                                @if ($sortBy['column'] === 'occupation')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Children</th>
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
                    @forelse($parents as $parent)
                        <tr class="hover">
                            <td>
                                <x-checkbox wire:model.live="selectedParents" value="{{ $parent->id }}" />
                            </td>
                            <td>
                                <div class="flex items-center">
                                    <div class="avatar">
                                        <div class="w-10 h-10 rounded-full">
                                            <img src="{{ $parent->user->profile_photo_url }}" alt="{{ $parent->user->name }}" />
                                        </div>
                                    </div>
                                    <div class="ml-3">
                                        <button wire:click="redirectToShow({{ $parent->id }})" class="font-semibold text-blue-600 underline hover:text-blue-800">
                                            {{ $parent->user->name }}
                                        </button>
                                        @if($parent->user_id === Auth::id())
                                            <div class="text-xs text-blue-500">(You)</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="font-mono text-sm">{{ $parent->user->email }}</div>
                                @if($parent->user->email_verified_at)
                                    <div class="text-xs text-green-600">Verified</div>
                                @else
                                    <div class="text-xs text-red-600">Not verified</div>
                                @endif
                            </td>
                            <td>
                                <div class="font-mono text-sm">{{ $parent->phone ?: $parent->user->phone ?: '-' }}</div>
                            </td>
                            <td>
                                @if($parent->occupation)
                                    <div class="text-sm">{{ $parent->occupation }}</div>
                                    @if($parent->company)
                                        <div class="text-xs text-gray-500">{{ $parent->company }}</div>
                                    @endif
                                @else
                                    <span class="text-sm text-gray-500">Not specified</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex items-center">
                                    <span class="text-sm font-medium">{{ $parent->children->count() }}</span>
                                    @if($parent->children->count() > 0)
                                        <div class="ml-2">
                                            <x-button
                                                label="View"
                                                icon="o-eye"
                                                link="{{ route('admin.children.index', ['parent' => $parent->id]) }}"
                                                class="btn-xs btn-ghost"
                                            />
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($parent->user->status) }}">
                                    {{ ucfirst($parent->user->status) }}
                                </span>
                            </td>
                            <td>
                                <div class="text-sm">{{ $parent->created_at->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $parent->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <button
                                        wire:click="redirectToShow({{ $parent->id }})"
                                        class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                        title="View"
                                    >
                                        👁️
                                    </button>
                                    <button
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
                                        />
                                    @else
                                        <x-button
                                            label="Create First Parent"
                                            icon="o-plus"
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
            {{ $parents->links() }}
        </div>

        <!-- Results summary -->
        @if($parents->count() > 0)
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $parents->firstItem() ?? 0 }} to {{ $parents->lastItem() ?? 0 }}
            of {{ $parents->total() }} parents
            @if($search || $occupationFilter || $statusFilter)
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
                    label="Search parents"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by name, email, phone, occupation..."
                    clearable
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
