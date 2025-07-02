<?php

use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Children Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $genderFilter = '';

    #[Url]
    public string $ageFilter = '';

    #[Url]
    public string $parentFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Bulk actions
    public array $selectedChildren = [];
    public bool $selectAll = false;

    // Stats
    public array $stats = [];

    // Filter options
    public array $genderOptions = [];
    public array $ageOptions = [];
    public array $parentOptions = [];

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed children management page',
            ChildProfile::class,
            null,
            ['ip' => request()->ip()]
        );

        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        // Gender options
        $this->genderOptions = [
            ['id' => '', 'name' => 'All Genders'],
            ['id' => 'male', 'name' => 'Male'],
            ['id' => 'female', 'name' => 'Female'],
            ['id' => 'other', 'name' => 'Other'],
        ];

        // Age options
        $this->ageOptions = [
            ['id' => '', 'name' => 'All Ages'],
            ['id' => '0-2', 'name' => '0-2 years'],
            ['id' => '3-5', 'name' => '3-5 years'],
            ['id' => '6-8', 'name' => '6-8 years'],
            ['id' => '9-12', 'name' => '9-12 years'],
            ['id' => '13-15', 'name' => '13-15 years'],
            ['id' => '16-18', 'name' => '16-18 years'],
            ['id' => '18+', 'name' => '18+ years'],
        ];

        // Parent options - get from database
        try {
            $parents = \App\Models\ParentProfile::with('user')
                ->whereHas('user')
                ->limit(100)
                ->get();

            $this->parentOptions = [
                ['id' => '', 'name' => 'All Parents'],
                ...$parents->map(fn($parent) => [
                    'id' => $parent->user_id,
                    'name' => $parent->user->name . ' (' . $parent->user->email . ')'
                ])->toArray()
            ];
        } catch (\Exception $e) {
            $this->parentOptions = [
                ['id' => '', 'name' => 'All Parents'],
            ];
        }
    }

    protected function loadStats(): void
    {
        try {
            $totalChildren = ChildProfile::count();
            $activeChildren = ChildProfile::whereNotNull('parent_id')->count();
            $recentChildren = ChildProfile::where('created_at', '>=', now()->subDays(30))->count();

            // Age distribution
            $ageGroups = [
                '0-2' => ChildProfile::byAge(0, 2)->count(),
                '3-5' => ChildProfile::byAge(3, 5)->count(),
                '6-8' => ChildProfile::byAge(6, 8)->count(),
                '9-12' => ChildProfile::byAge(9, 12)->count(),
                '13-15' => ChildProfile::byAge(13, 15)->count(),
                '16-18' => ChildProfile::byAge(16, 18)->count(),
                '18+' => ChildProfile::byAge(18)->count(),
            ];

            // Gender distribution
            $genderCounts = ChildProfile::selectRaw('gender, COUNT(*) as count')
                ->whereNotNull('gender')
                ->groupBy('gender')
                ->get()
                ->pluck('count', 'gender')
                ->toArray();

            // Children with special needs
            $specialNeedsCount = ChildProfile::whereNotNull('special_needs')
                ->orWhereNotNull('medical_conditions')
                ->orWhereNotNull('allergies')
                ->count();

            // Program enrollments count
            $enrolledCount = ChildProfile::whereHas('programEnrollments')->count();

            $this->stats = [
                'total_children' => $totalChildren,
                'active_children' => $activeChildren,
                'recent_children' => $recentChildren,
                'special_needs_count' => $specialNeedsCount,
                'enrolled_count' => $enrolledCount,
                'age_groups' => $ageGroups,
                'gender_counts' => $genderCounts,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_children' => 0,
                'active_children' => 0,
                'recent_children' => 0,
                'special_needs_count' => 0,
                'enrolled_count' => 0,
                'age_groups' => [],
                'gender_counts' => [],
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
    public function redirectToShow(int $childId): void
    {
        $this->redirect(route('admin.children.show', $childId));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedGenderFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAgeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedParentFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->genderFilter = '';
        $this->ageFilter = '';
        $this->parentFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated children
    public function children(): LengthAwarePaginator
    {
        return ChildProfile::query()
            ->with(['parent', 'user', 'programEnrollments'])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('first_name', 'like', "%{$this->search}%")
                      ->orWhere('last_name', 'like', "%{$this->search}%")
                      ->orWhere('email', 'like', "%{$this->search}%")
                      ->orWhereHas('parent', function ($parentQuery) {
                          $parentQuery->where('name', 'like', "%{$this->search}%");
                      });
                });
            })
            ->when($this->genderFilter, function (Builder $query) {
                $query->where('gender', $this->genderFilter);
            })
            ->when($this->ageFilter, function (Builder $query) {
                if ($this->ageFilter === '18+') {
                    $query->byAge(18);
                } else {
                    [$min, $max] = explode('-', $this->ageFilter);
                    $query->byAge((int)$min, (int)$max);
                }
            })
            ->when($this->parentFilter, function (Builder $query) {
                $query->where('parent_id', $this->parentFilter);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->genderFilter = '';
        $this->ageFilter = '';
        $this->parentFilter = '';
        $this->resetPage();
    }

    // Helper function to get gender color
    private function getGenderColor(string $gender): string
    {
        return match(strtolower($gender)) {
            'male' => 'bg-blue-100 text-blue-800',
            'female' => 'bg-pink-100 text-pink-800',
            'other' => 'bg-purple-100 text-purple-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to get age group color
    private function getAgeGroupColor(int $age): string
    {
        return match(true) {
            $age <= 2 => 'bg-green-100 text-green-800',
            $age <= 5 => 'bg-blue-100 text-blue-800',
            $age <= 8 => 'bg-yellow-100 text-yellow-800',
            $age <= 12 => 'bg-orange-100 text-orange-800',
            $age <= 15 => 'bg-red-100 text-red-800',
            $age <= 18 => 'bg-purple-100 text-purple-800',
            default => 'bg-gray-100 text-gray-800'
        };
    }

    public function with(): array
    {
        return [
            'children' => $this->children(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Children Management" subtitle="Manage student profiles and their information" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search children..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$genderFilter, $ageFilter, $parentFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="View Parents"
                icon="o-users"
                link="{{ route('admin.parents.index') }}"
                class="btn-outline"
                responsive />

            <x-button
                label="Add Child"
                icon="o-plus"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-5">
        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-blue-100 rounded-full">
                        <x-icon name="o-users" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_children']) }}</div>
                        <div class="text-sm text-gray-500">Total Children</div>
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
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['active_children']) }}</div>
                        <div class="text-sm text-gray-500">With Parents</div>
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
                        <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['recent_children']) }}</div>
                        <div class="text-sm text-gray-500">New (30 days)</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-academic-cap" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['enrolled_count']) }}</div>
                        <div class="text-sm text-gray-500">Enrolled</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-red-100 rounded-full">
                        <x-icon name="o-heart" class="w-8 h-8 text-red-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-red-600">{{ number_format($stats['special_needs_count']) }}</div>
                        <div class="text-sm text-gray-500">Special Needs</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Age Distribution Cards -->
    @if(!empty($stats['age_groups']))
    <div class="grid grid-cols-1 gap-4 mb-8 md:grid-cols-7">
        @foreach($stats['age_groups'] as $ageGroup => $count)
        <x-card class="border-indigo-200 bg-indigo-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-indigo-600">{{ number_format($count) }}</div>
                <div class="text-sm text-indigo-600">{{ $ageGroup }} years</div>
            </div>
        </x-card>
        @endforeach
    </div>
    @endif

    <!-- Children Table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th>
                            <x-checkbox wire:model.live="selectAll" />
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('first_name')">
                            <div class="flex items-center">
                                Name
                                @if ($sortBy['column'] === 'first_name')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('date_of_birth')">
                            <div class="flex items-center">
                                Age
                                @if ($sortBy['column'] === 'date_of_birth')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('gender')">
                            <div class="flex items-center">
                                Gender
                                @if ($sortBy['column'] === 'gender')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Parent</th>
                        <th>Contact</th>
                        <th>Enrollments</th>
                        <th>Special Needs</th>
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
                    @forelse($children as $child)
                        <tr class="hover">
                            <td>
                                <x-checkbox wire:model.live="selectedChildren" value="{{ $child->id }}" />
                            </td>
                            <td>
                                <div class="flex items-center">
                                    <div class="avatar">
                                        <div class="w-10 h-10 rounded-full bg-blue-100">
                                            <div class="flex items-center justify-center w-full h-full text-blue-600 font-semibold text-sm">
                                                {{ $child->initials }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="ml-3">
                                        <button wire:click="redirectToShow({{ $child->id }})" class="font-semibold text-blue-600 underline hover:text-blue-800">
                                            {{ $child->full_name }}
                                        </button>
                                        @if($child->email)
                                            <div class="text-xs text-gray-500">{{ $child->email }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if($child->age)
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getAgeGroupColor($child->age) }}">
                                        {{ $child->age }} years
                                    </span>
                                    @if($child->date_of_birth)
                                        <div class="text-xs text-gray-500">{{ $child->date_of_birth->format('M d, Y') }}</div>
                                    @endif
                                @else
                                    <span class="text-sm text-gray-500">Unknown</span>
                                @endif
                            </td>
                            <td>
                                @if($child->gender)
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getGenderColor($child->gender) }}">
                                        {{ ucfirst($child->gender) }}
                                    </span>
                                @else
                                    <span class="text-sm text-gray-500">Not specified</span>
                                @endif
                            </td>
                            <td>
                                @if($child->parent)
                                    <div class="text-sm font-medium">{{ $child->parent->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $child->parent->email }}</div>
                                @else
                                    <span class="text-sm text-gray-500">No parent assigned</span>
                                @endif
                            </td>
                            <td>
                                @if($child->phone)
                                    <div class="font-mono text-sm">{{ $child->phone }}</div>
                                @elseif($child->parent && $child->parent->phone)
                                    <div class="font-mono text-sm text-gray-500">{{ $child->parent->phone }}</div>
                                    <div class="text-xs text-gray-400">(Parent)</div>
                                @else
                                    <span class="text-sm text-gray-500">No contact</span>
                                @endif
                            </td>
                            <td>
                                <div class="text-sm font-medium">{{ $child->programEnrollments->count() }}</div>
                                @if($child->programEnrollments->count() > 0)
                                    <div class="text-xs text-green-600">Active programs</div>
                                @else
                                    <div class="text-xs text-gray-500">No enrollments</div>
                                @endif
                            </td>
                            <td>
                                @if($child->special_needs || $child->medical_conditions || $child->allergies)
                                    <div class="flex items-center">
                                        <x-icon name="o-heart" class="w-4 h-4 text-red-500 mr-1" />
                                        <span class="text-xs text-red-600">Yes</span>
                                    </div>
                                @else
                                    <span class="text-xs text-gray-500">None</span>
                                @endif
                            </td>
                            <td>
                                <div class="text-sm">{{ $child->created_at->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $child->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <button
                                        wire:click="redirectToShow({{ $child->id }})"
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
                            <td colspan="10" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-users" class="w-20 h-20 text-gray-300" />
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-600">No children found</h3>
                                        <p class="mt-1 text-gray-500">
                                            @if($search || $genderFilter || $ageFilter || $parentFilter)
                                                No children match your current filters.
                                            @else
                                                Get started by adding your first child profile.
                                            @endif
                                        </p>
                                    </div>
                                    @if($search || $genderFilter || $ageFilter || $parentFilter)
                                        <x-button
                                            label="Clear Filters"
                                            wire:click="resetFilters"
                                            color="secondary"
                                            size="sm"
                                        />
                                    @else
                                        <x-button
                                            label="Add First Child"
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
            {{ $children->links() }}
        </div>

        <!-- Results summary -->
        @if($children->count() > 0)
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $children->firstItem() ?? 0 }} to {{ $children->lastItem() ?? 0 }}
            of {{ $children->total() }} children
            @if($search || $genderFilter || $ageFilter || $parentFilter)
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
                    label="Search children"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by name, email, or parent..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by gender"
                    :options="$genderOptions"
                    wire:model.live="genderFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All genders"
                />
            </div>

            <div>
                <x-select
                    label="Filter by age"
                    :options="$ageOptions"
                    wire:model.live="ageFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All ages"
                />
            </div>

            <div>
                <x-select
                    label="Filter by parent"
                    :options="$parentOptions"
                    wire:model.live="parentFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All parents"
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
