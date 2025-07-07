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

<div class="min-h-screen bg-gradient-to-br from-blue-50 via-cyan-50 to-teal-50">
    <!-- Ultra-responsive page header -->
    <x-header title="Children Management" subtitle="Manage student profiles and their information" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input
                placeholder="Search children..."
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
                :badge="count(array_filter([$genderFilter, $ageFilter, $parentFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="text-xs bg-base-300 sm:text-sm"
                responsive
            />

            <x-button
                label="View Parents"
                icon="o-users"
                link="{{ route('admin.parents.index') }}"
                class="hidden text-xs btn-outline sm:text-sm sm:flex"
                responsive
            />

        </x-slot:actions>
    </x-header>

    <!-- Adaptive container with responsive padding -->
    <div class="container px-2 mx-auto space-y-4 sm:px-4 md:px-6 lg:px-8 sm:space-y-6 lg:space-y-8">

        <!-- Ultra-responsive Stats Cards with child-friendly design -->
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5 sm:gap-4 lg:gap-6">
            <x-card class="transition-all duration-300 border-0 shadow-md group hover:shadow-xl hover:-translate-y-1 bg-gradient-to-br from-blue-50 to-blue-100">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 transition-transform duration-300 rounded-full bg-gradient-to-br from-blue-100 to-blue-200 sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110">
                            <x-icon name="o-users" class="w-5 h-5 text-blue-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-blue-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['total_children']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">Total Children</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 border-0 shadow-md group hover:shadow-xl hover:-translate-y-1 bg-gradient-to-br from-green-50 to-green-100">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 transition-transform duration-300 rounded-full bg-gradient-to-br from-green-100 to-green-200 sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110">
                            <x-icon name="o-check-circle" class="w-5 h-5 text-green-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-green-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['active_children']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">With Parents</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 border-0 shadow-md group hover:shadow-xl hover:-translate-y-1 bg-gradient-to-br from-orange-50 to-orange-100">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 transition-transform duration-300 rounded-full bg-gradient-to-br from-orange-100 to-orange-200 sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110">
                            <x-icon name="o-user-plus" class="w-5 h-5 text-orange-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-orange-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['recent_children']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">New (30 days)</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 border-0 shadow-md group hover:shadow-xl hover:-translate-y-1 bg-gradient-to-br from-purple-50 to-purple-100">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 transition-transform duration-300 rounded-full bg-gradient-to-br from-purple-100 to-purple-200 sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110">
                            <x-icon name="o-academic-cap" class="w-5 h-5 text-purple-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-purple-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['enrolled_count']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">Enrolled</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <x-card class="transition-all duration-300 border-0 shadow-md group hover:shadow-xl hover:-translate-y-1 bg-gradient-to-br from-red-50 to-pink-100">
                <div class="p-3 sm:p-4 lg:p-6">
                    <div class="flex items-center">
                        <div class="p-2 mr-2 transition-transform duration-300 rounded-full bg-gradient-to-br from-red-100 to-pink-200 sm:p-3 sm:mr-3 lg:mr-4 group-hover:scale-110">
                            <x-icon name="o-heart" class="w-5 h-5 text-red-600 sm:w-6 sm:h-6 lg:w-8 lg:h-8" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-lg font-bold text-red-600 sm:text-xl lg:text-2xl xl:text-3xl">{{ number_format($stats['special_needs_count']) }}</div>
                            <div class="text-xs text-gray-500 sm:text-sm lg:text-base">Special Needs</div>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- Hyper-responsive Age Distribution with playful design -->
        @if(!empty($stats['age_groups']))
        <div class="overflow-hidden">
            <h3 class="flex items-center mb-3 text-sm font-semibold text-gray-700 sm:text-base lg:text-lg">
                <x-icon name="o-cake" class="w-4 h-4 mr-2 text-indigo-600" />
                Age Distribution
            </h3>
            <div class="flex pb-3 space-x-2 overflow-x-auto sm:grid sm:grid-cols-3 sm:gap-3 sm:space-x-0 sm:overflow-visible sm:pb-0 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-7 2xl:grid-cols-7">
                @foreach($stats['age_groups'] as $ageGroup => $count)
                <x-card class="flex-shrink-0 w-24 transition-all duration-300 border-indigo-200 sm:w-auto bg-gradient-to-br from-indigo-50 to-cyan-50 hover:shadow-lg hover:scale-105">
                    <div class="p-2 text-center sm:p-3">
                        <div class="text-base font-bold text-indigo-600 sm:text-lg lg:text-xl">{{ number_format($count) }}</div>
                        <div class="text-xs text-indigo-600 sm:text-sm">{{ $ageGroup }} years</div>
                    </div>
                </x-card>
                @endforeach
            </div>
        </div>
        @endif

        <!-- Ultra-responsive Mobile Card View with child-friendly interactions -->
        <div class="block space-y-3 lg:hidden sm:space-y-4">
            @forelse($children as $child)
                <x-card class="overflow-hidden transition-all duration-300 border-0 shadow-md group hover:shadow-xl hover:-translate-y-1">
                    <div class="p-3 sm:p-4">
                        <!-- Enhanced Child Info Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center flex-1 min-w-0">
                                <x-checkbox
                                    wire:model.live="selectedChildren"
                                    value="{{ $child->id }}"
                                    class="mr-2 transition-transform scale-110 sm:mr-3 hover:scale-125"
                                />
                                <div class="transition-transform duration-300 avatar group-hover:scale-110">
                                    <div class="w-10 h-10 transition-all duration-300 rounded-full bg-gradient-to-br from-blue-100 to-cyan-100 ring-2 ring-transparent group-hover:ring-blue-200 sm:w-12 sm:h-12">
                                        <div class="flex items-center justify-center w-full h-full text-sm font-semibold text-blue-600">
                                            {{ $child->initials }}
                                        </div>
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0 ml-2 sm:ml-3">
                                    <button wire:click="redirectToShow({{ $child->id }})" class="text-sm font-semibold text-left text-blue-600 underline transition-colors hover:text-blue-800 sm:text-base group-hover:text-blue-700">
                                        {{ $child->full_name }}
                                    </button>
                                    @if($child->email)
                                        <div class="text-xs text-gray-600 truncate sm:text-sm">{{ $child->email }}</div>
                                    @endif
                                </div>
                            </div>
                            <div class="flex gap-1 ml-2">
                                <button
                                    wire:click="redirectToShow({{ $child->id }})"
                                    class="p-1.5 sm:p-2 text-gray-600 bg-gray-100 rounded-lg hover:text-gray-900 hover:bg-gray-200 hover:scale-110 transition-all duration-200"
                                    title="View"
                                >
                                    <span class="text-sm">👁️</span>
                                </button>
                                <button
                                    class="p-1.5 sm:p-2 text-blue-600 bg-blue-100 rounded-lg hover:text-blue-900 hover:bg-blue-200 hover:scale-110 transition-all duration-200"
                                    title="Edit"
                                >
                                    <span class="text-sm">✏️</span>
                                </button>
                            </div>
                        </div>

                        <!-- Enhanced Child Details with playful design -->
                        <div class="space-y-2">
                            <!-- Age and Gender Row -->
                            <div class="flex items-center justify-between">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if($child->age)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium transition-all duration-200 hover:scale-105 {{ $this->getAgeGroupColor($child->age) }}">
                                            🎂 {{ $child->age }} years
                                        </span>
                                    @endif
                                    @if($child->gender)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium transition-all duration-200 hover:scale-105 {{ $this->getGenderColor($child->gender) }}">
                                            {{ $child->gender === 'male' ? '👦' : '👧' }} {{ ucfirst($child->gender) }}
                                        </span>
                                    @endif
                                </div>
                                @if($child->date_of_birth)
                                    <div class="px-2 py-1 text-xs text-gray-600 rounded bg-gray-50">{{ $child->date_of_birth->format('M d, Y') }}</div>
                                @endif
                            </div>

                            <!-- Parent Information -->
                            @if($child->parent)
                            <div class="p-2 rounded-lg bg-blue-50">
                                <div class="flex items-center mb-1 text-xs text-blue-600">
                                    <x-icon name="o-users" class="w-3 h-3 mr-1" />
                                    Parent:
                                </div>
                                <div class="text-sm font-medium text-blue-800">{{ $child->parent->name }}</div>
                                <div class="text-xs text-blue-600">{{ $child->parent->email }}</div>
                            </div>
                            @else
                            <div class="p-2 rounded-lg bg-yellow-50">
                                <div class="text-xs italic text-yellow-600">No parent assigned</div>
                            </div>
                            @endif

                            <!-- Contact and Programs -->
                            <div class="grid grid-cols-2 gap-2">
                                <!-- Contact -->
                                <div class="p-2 rounded-lg bg-gray-50">
                                    <div class="mb-1 text-xs text-gray-500">Contact:</div>
                                    @if($child->phone)
                                        <div class="font-mono text-xs text-gray-800">{{ $child->phone }}</div>
                                    @elseif($child->parent && $child->parent->phone)
                                        <div class="font-mono text-xs text-gray-600">{{ $child->parent->phone }}</div>
                                        <div class="text-xs text-gray-400">(Parent)</div>
                                    @else
                                        <span class="text-xs italic text-gray-500">No contact</span>
                                    @endif
                                </div>

                                <!-- Programs -->
                                <div class="p-2 rounded-lg bg-purple-50">
                                    <div class="flex items-center mb-1 text-xs text-purple-600">
                                        <x-icon name="o-academic-cap" class="w-3 h-3 mr-1" />
                                        Programs:
                                    </div>
                                    <div class="text-sm font-medium text-purple-800">{{ $child->programEnrollments->count() }}</div>
                                    @if($child->programEnrollments->count() > 0)
                                        <div class="text-xs text-green-600">Active</div>
                                    @else
                                        <div class="text-xs text-gray-500">None</div>
                                    @endif
                                </div>
                            </div>

                            <!-- Special Needs Indicator -->
                            @if($child->special_needs || $child->medical_conditions || $child->allergies)
                            <div class="p-2 rounded-lg bg-red-50">
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-heart" class="w-4 h-4 text-red-500" />
                                    <span class="text-sm font-medium text-red-800">Special Care Required</span>
                                </div>
                                <div class="mt-1 text-xs text-red-600">Has special needs or medical conditions</div>
                            </div>
                            @endif

                            <!-- Created Date -->
                            <div class="flex justify-between pt-2 text-xs text-gray-500 border-t border-gray-100">
                                <div class="flex items-center gap-1">
                                    <x-icon name="o-calendar" class="w-3 h-3" />
                                    <span>{{ $child->created_at->format('M d, Y') }}</span>
                                </div>
                                <div class="px-2 py-1 bg-gray-100 rounded">
                                    {{ $child->created_at->diffForHumans() }}
                                </div>
                            </div>
                        </div>
                    </div>
                </x-card>
            @empty
                <x-card class="border-2 border-gray-200 border-dashed">
                    <div class="py-8 text-center sm:py-12">
                        <div class="flex flex-col items-center justify-center gap-3 sm:gap-4">
                            <div class="p-4 rounded-full bg-gradient-to-br from-blue-100 to-cyan-100">
                                <x-icon name="o-users" class="w-12 h-12 text-blue-400 sm:w-16 sm:h-16" />
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-600 sm:text-lg">No children found</h3>
                                <p class="mt-1 text-sm text-gray-500">
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
                                    class="transition-transform hover:scale-105"
                                />
                            @else
                                <x-button
                                    label="Add First Child"
                                    icon="o-plus"
                                    color="primary"
                                    class="transition-transform hover:scale-105"
                                />
                            @endif
                        </div>
                    </div>
                </x-card>
            @endforelse
        </div>

        <!-- Enhanced Desktop Table View -->
        <x-card class="hidden overflow-hidden border-0 shadow-lg lg:block">
            <div class="overflow-x-auto">
                <table class="table w-full table-zebra">
                    <thead class="bg-gradient-to-r from-gray-50 to-blue-50">
                        <tr>
                            <th class="w-12">
                                <x-checkbox wire:model.live="selectAll" class="transition-transform scale-110 hover:scale-125" />
                            </th>
                            <th class="transition-colors cursor-pointer hover:bg-blue-100" wire:click="sortBy('first_name')">
                                <div class="flex items-center">
                                    Name
                                    @if ($sortBy['column'] === 'first_name')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-blue-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="transition-colors cursor-pointer hover:bg-blue-100" wire:click="sortBy('date_of_birth')">
                                <div class="flex items-center">
                                    Age
                                    @if ($sortBy['column'] === 'date_of_birth')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-blue-600" />
                                    @endif
                                </div>
                            </th>
                            <th class="transition-colors cursor-pointer hover:bg-blue-100" wire:click="sortBy('gender')">
                                <div class="flex items-center">
                                    Gender
                                    @if ($sortBy['column'] === 'gender')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1 text-blue-600" />
                                    @endif
                                </div>
                            </th>
                            <th>Parent</th>
                            <th class="hidden xl:table-cell">Contact</th>
                            <th>Programs</th>
                            <th class="hidden xl:table-cell">Special Needs</th>
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
                        @forelse($children as $child)
                            <tr class="transition-colors hover:bg-blue-50">
                                <td>
                                    <x-checkbox wire:model.live="selectedChildren" value="{{ $child->id }}" class="transition-transform scale-110 hover:scale-125" />
                                </td>
                                <td>
                                    <div class="flex items-center">
                                        <div class="transition-transform duration-300 avatar hover:scale-110">
                                            <div class="w-10 h-10 transition-all rounded-full bg-gradient-to-br from-blue-100 to-cyan-100 ring-2 ring-transparent hover:ring-blue-200">
                                                <div class="flex items-center justify-center w-full h-full text-sm font-semibold text-blue-600">
                                                    {{ $child->initials }}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="ml-3">
                                            <button wire:click="redirectToShow({{ $child->id }})" class="font-semibold text-blue-600 underline transition-colors hover:text-blue-800">
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
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium hover:scale-105 transition-transform {{ $this->getAgeGroupColor($child->age) }}">
                                            🎂 {{ $child->age }} years
                                        </span>
                                        @if($child->date_of_birth)
                                            <div class="inline-block px-2 py-1 mt-1 text-xs text-gray-500 rounded bg-gray-50">{{ $child->date_of_birth->format('M d, Y') }}</div>
                                        @endif
                                    @else
                                        <span class="text-sm italic text-gray-500">Unknown</span>
                                    @endif
                                </td>
                                <td>
                                    @if($child->gender)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium hover:scale-105 transition-transform {{ $this->getGenderColor($child->gender) }}">
                                            {{ $child->gender === 'male' ? '👦' : '👧' }} {{ ucfirst($child->gender) }}
                                        </span>
                                    @else
                                        <span class="text-sm italic text-gray-500">Not specified</span>
                                    @endif
                                </td>
                                <td>
                                    @if($child->parent)
                                        <div class="text-sm font-medium text-blue-600">{{ $child->parent->name }}</div>
                                        <div class="inline-block px-2 py-1 text-xs text-gray-500 rounded bg-blue-50">{{ $child->parent->email }}</div>
                                    @else
                                        <span class="px-2 py-1 text-sm text-yellow-600 rounded bg-yellow-50">No parent assigned</span>
                                    @endif
                                </td>
                                <td class="hidden xl:table-cell">
                                    @if($child->phone)
                                        <div class="font-mono text-sm">{{ $child->phone }}</div>
                                    @elseif($child->parent && $child->parent->phone)
                                        <div class="font-mono text-sm text-gray-500">{{ $child->parent->phone }}</div>
                                        <div class="inline-block px-2 py-1 text-xs text-gray-400 rounded bg-gray-50">(Parent)</div>
                                    @else
                                        <span class="text-sm italic text-gray-500">No contact</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="flex items-center gap-1 px-2 py-1 rounded bg-purple-50">
                                            <x-icon name="o-academic-cap" class="w-4 h-4 text-purple-600" />
                                            <span class="text-sm font-medium text-purple-800">{{ $child->programEnrollments->count() }}</span>
                                        </div>
                                        @if($child->programEnrollments->count() > 0)
                                            <div class="px-2 py-1 text-xs text-green-600 rounded bg-green-50">Active</div>
                                        @else
                                            <div class="px-2 py-1 text-xs text-gray-500 rounded bg-gray-50">None</div>
                                        @endif
                                    </div>
                                </td>
                                <td class="hidden xl:table-cell">
                                    @if($child->special_needs || $child->medical_conditions || $child->allergies)
                                        <div class="flex items-center gap-1 px-2 py-1 rounded bg-red-50">
                                            <x-icon name="o-heart" class="w-4 h-4 text-red-500" />
                                            <span class="text-xs font-medium text-red-600">Yes</span>
                                        </div>
                                    @else
                                        <span class="px-2 py-1 text-xs text-gray-500 rounded bg-gray-50">None</span>
                                    @endif
                                </td>
                                <td class="hidden 2xl:table-cell">
                                    <div class="text-sm">{{ $child->created_at->format('M d, Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $child->created_at->diffForHumans() }}</div>
                                </td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            wire:click="redirectToShow({{ $child->id }})"
                                            class="p-2 text-gray-600 transition-all duration-200 bg-gray-100 rounded-lg hover:text-gray-900 hover:bg-gray-200 hover:scale-110"
                                            title="View"
                                        >
                                            👁️
                                        </button>
                                        <button
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
                                <td colspan="10" class="py-12 text-center">
                                    <div class="flex flex-col items-center justify-center gap-4">
                                        <div class="p-4 rounded-full bg-gradient-to-br from-blue-100 to-cyan-100">
                                            <x-icon name="o-users" class="w-20 h-20 text-blue-300" />
                                        </div>
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
                                                class="transition-transform hover:scale-105"
                                            />
                                        @else
                                            <x-button
                                                label="Add First Child"
                                                icon="o-plus"
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
            <div class="p-4 mt-4 bg-gradient-to-r from-gray-50 to-blue-50">
                {{ $children->links() }}
            </div>

            <!-- Enhanced Results summary -->
            @if($children->count() > 0)
            <div class="px-4 pt-3 pb-4 mt-4 text-sm text-gray-600 border-t bg-gradient-to-r from-gray-50 to-blue-50">
                <div class="flex items-center justify-between">
                    <span>
                        Showing {{ $children->firstItem() ?? 0 }} to {{ $children->lastItem() ?? 0 }}
                        of {{ $children->total() }} children
                        @if($search || $genderFilter || $ageFilter || $parentFilter)
                            (filtered from total)
                        @endif
                    </span>
                    <div class="text-xs text-gray-500">
                        Page {{ $children->currentPage() }} of {{ $children->lastPage() }}
                    </div>
                </div>
            </div>
            @endif
        </x-card>

        <!-- Enhanced Mobile/Tablet Pagination -->
        <div class="lg:hidden">
            {{ $children->links() }}
        </div>

        <!-- Enhanced Mobile Results Summary -->
        @if($children->count() > 0)
        <div class="p-4 pt-3 text-sm text-center text-gray-600 bg-white border-t rounded-lg shadow-sm lg:hidden">
            <div class="space-y-1">
                <div>
                    Showing {{ $children->firstItem() ?? 0 }} to {{ $children->lastItem() ?? 0 }}
                    of {{ $children->total() }} children
                </div>
                @if($search || $genderFilter || $ageFilter || $parentFilter)
                    <div class="text-xs text-blue-600">(filtered results)</div>
                @endif
                <div class="text-xs text-gray-500">
                    Page {{ $children->currentPage() }} of {{ $children->lastPage() }}
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
                    label="Search children"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by name, email, or parent..."
                    clearable
                    class="w-full"
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
                    class="w-full"
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
                    class="w-full"
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

    <!-- Enhanced Floating Action Button with child-friendly design -->
    <div class="fixed z-50 bottom-4 right-4 lg:hidden">
        <div class="flex flex-col gap-3">
            <!-- Primary Action -->
            <button
                class="relative flex items-center justify-center text-white transition-all duration-300 transform rounded-full shadow-lg group w-14 h-14 bg-gradient-to-r from-blue-500 to-cyan-500 hover:shadow-xl hover:scale-110 animate-bounce-slow"
                title="Add Child"
            >
                <x-icon name="o-plus" class="w-6 h-6 transition-transform duration-300 group-hover:rotate-90" />

                <!-- Ripple effect -->
                <div class="absolute inset-0 transition-all duration-300 bg-white rounded-full opacity-0 group-hover:opacity-20 group-hover:scale-150"></div>
            </button>

            <!-- Secondary Actions -->
            <div class="flex flex-col gap-2">
                <!-- View Parents -->
                <button
                    onclick="window.location.href='{{ route('admin.parents.index') }}'"
                    class="relative flex items-center justify-center w-12 h-12 text-white transition-all duration-300 transform rounded-full shadow-lg group bg-gradient-to-r from-purple-500 to-pink-500 hover:shadow-xl hover:scale-110"
                    title="View Parents"
                >
                    <x-icon name="o-users" class="w-5 h-5 transition-transform duration-300 group-hover:pulse" />
                </button>

                <!-- Filters -->
                <button
                    @click="$wire.showFilters = true"
                    class="relative flex items-center justify-center w-12 h-12 text-white transition-all duration-300 transform rounded-full shadow-lg group bg-gradient-to-r from-gray-600 to-gray-700 hover:shadow-xl hover:scale-110"
                    title="Open Filters"
                >
                    <x-icon name="o-funnel" class="w-5 h-5 transition-transform duration-300 group-hover:rotate-12" />
                    @if(count(array_filter([$genderFilter, $ageFilter, $parentFilter])) > 0)
                        <div class="absolute flex items-center justify-center w-4 h-4 text-xs text-white bg-red-500 rounded-full -top-1 -right-1 animate-pulse">
                            {{ count(array_filter([$genderFilter, $ageFilter, $parentFilter])) }}
                        </div>
                    @endif
                </button>
            </div>
        </div>
    </div>

    <!-- Enhanced Mobile Navigation Helper -->
    <div class="block mt-6 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-blue-50 to-cyan-50">
            <div class="p-4">
                <h3 class="flex items-center mb-3 text-sm font-bold text-gray-800">
                    <x-icon name="o-sparkles" class="w-4 h-4 mr-2 text-blue-600" />
                    Quick Actions
                </h3>
                <div class="grid grid-cols-2 gap-3">
                    <button
                        class="flex items-center justify-center p-3 text-xs font-medium text-blue-800 transition-all duration-300 group bg-gradient-to-r from-blue-100 to-blue-200 rounded-xl hover:from-blue-200 hover:to-blue-300 hover:scale-105 hover:shadow-md"
                    >
                        <x-icon name="o-plus" class="w-4 h-4 mr-2 transition-transform duration-300 group-hover:rotate-90" />
                        Add Child
                    </button>
                    <button
                        onclick="window.location.href='{{ route('admin.parents.index') }}'"
                        class="flex items-center justify-center p-3 text-xs font-medium text-purple-800 transition-all duration-300 group bg-gradient-to-r from-purple-100 to-purple-200 rounded-xl hover:from-purple-200 hover:to-purple-300 hover:scale-105 hover:shadow-md"
                    >
                        <x-icon name="o-users" class="w-4 h-4 mr-2 transition-transform duration-300 group-hover:pulse" />
                        View Parents
                    </button>
                    <button
                        @click="$wire.showFilters = true"
                        class="flex items-center justify-center p-3 text-xs font-medium transition-all duration-300 group text-cyan-800 bg-gradient-to-r from-cyan-100 to-cyan-200 rounded-xl hover:from-cyan-200 hover:to-cyan-300 hover:scale-105 hover:shadow-md"
                    >
                        <x-icon name="o-funnel" class="w-4 h-4 mr-2 transition-transform duration-300 group-hover:rotate-12" />
                        Filters
                        @if(count(array_filter([$genderFilter, $ageFilter, $parentFilter])) > 0)
                            <span class="ml-1 px-1.5 py-0.5 bg-cyan-600 text-white rounded-full text-xs">
                                {{ count(array_filter([$genderFilter, $ageFilter, $parentFilter])) }}
                            </span>
                        @endif
                    </button>
                    <button
                        class="flex items-center justify-center p-3 text-xs font-medium transition-all duration-300 group text-emerald-800 bg-gradient-to-r from-emerald-100 to-emerald-200 rounded-xl hover:from-emerald-200 hover:to-emerald-300 hover:scale-105 hover:shadow-md"
                    >
                        <x-icon name="o-chart-bar" class="w-4 h-4 mr-2 transition-transform duration-300 group-hover:scale-110" />
                        Reports
                    </button>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Child Care Information Guide (mobile only) -->
    <div class="mt-4 lg:hidden">
        <x-card class="border-0 shadow-lg bg-gradient-to-r from-cyan-50 to-blue-50">
            <div class="p-4">
                <div class="flex items-start">
                    <div class="p-2 mr-3 rounded-full bg-cyan-100">
                        <x-icon name="o-heart" class="w-5 h-5 text-cyan-600" />
                    </div>
                    <div class="flex-1">
                        <h4 class="mb-2 font-semibold text-cyan-800">Child Information Guide</h4>
                        <div class="space-y-2">
                            <div class="flex items-center gap-3 p-2 bg-white rounded-lg">
                                <span class="text-lg">🎂</span>
                                <span class="text-sm text-gray-700">Age groups help organize activities and programs</span>
                            </div>
                            <div class="flex items-center gap-3 p-2 bg-white rounded-lg">
                                <div class="flex items-center gap-1">
                                    <x-icon name="o-heart" class="w-3 h-3 text-red-600" />
                                    <span class="text-xs font-medium text-red-800">Special Care</span>
                                </div>
                                <span class="text-sm text-gray-700">Indicates children with special needs or medical conditions</span>
                            </div>
                            <div class="flex items-center gap-3 p-2 bg-white rounded-lg">
                                <div class="flex items-center gap-1">
                                    <x-icon name="o-academic-cap" class="w-3 h-3 text-purple-600" />
                                    <span class="text-xs font-medium text-purple-800">Programs</span>
                                </div>
                                <span class="text-sm text-gray-700">Shows enrolled educational programs</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Advanced Filter Summary (Mobile) -->
    @if($search || $genderFilter || $ageFilter || $parentFilter)
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
                            @if($genderFilter)
                                <div class="text-sm text-amber-700">
                                    <strong>Gender:</strong> {{ collect($genderOptions)->firstWhere('id', $genderFilter)['name'] ?? $genderFilter }}
                                </div>
                            @endif
                            @if($ageFilter)
                                <div class="text-sm text-amber-700">
                                    <strong>Age:</strong> {{ collect($ageOptions)->firstWhere('id', $ageFilter)['name'] ?? $ageFilter }}
                                </div>
                            @endif
                            @if($parentFilter)
                                <div class="text-sm text-amber-700">
                                    <strong>Parent:</strong> {{ collect($parentOptions)->firstWhere('id', $parentFilter)['name'] ?? $parentFilter }}
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

    <!-- Child Statistics Overview (Hidden on mobile, collapsible on tablet) -->
    <div class="hidden mt-6 sm:block">
        <details class="group sm:hidden md:block">
            <summary class="flex items-center justify-between p-4 font-medium text-gray-900 border border-blue-200 rounded-lg cursor-pointer bg-gradient-to-r from-blue-50 to-cyan-50 hover:from-blue-100 hover:to-cyan-100 sm:hidden">
                <span class="flex items-center">
                    <x-icon name="o-chart-bar" class="w-5 h-5 mr-2 text-blue-600" />
                    Child Overview
                </span>
                <svg class="w-5 h-5 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </summary>
            <div class="mt-2 sm:mt-0">
                <x-card class="border-0 shadow-lg bg-gradient-to-r from-blue-50 to-cyan-50">
                    <div class="p-4 md:p-6">
                        <h3 class="flex items-center mb-4 text-lg font-bold text-gray-800">
                            <x-icon name="o-chart-bar" class="w-5 h-5 mr-2 text-blue-600" />
                            Child Statistics
                        </h3>
                        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                            <div class="p-3 text-center bg-white border border-blue-100 rounded-lg shadow-sm">
                                <div class="text-2xl font-bold text-blue-600">
                                    {{ number_format(($stats['active_children'] / max($stats['total_children'], 1)) * 100, 1) }}%
                                </div>
                                <div class="text-xs text-gray-600">With Parents</div>
                            </div>
                            <div class="p-3 text-center bg-white border border-purple-100 rounded-lg shadow-sm">
                                <div class="text-2xl font-bold text-purple-600">
                                    {{ number_format(($stats['enrolled_count'] / max($stats['total_children'], 1)) * 100, 1) }}%
                                </div>
                                <div class="text-xs text-gray-600">Enrollment Rate</div>
                            </div>
                            <div class="p-3 text-center bg-white border border-orange-100 rounded-lg shadow-sm">
                                <div class="text-2xl font-bold text-orange-600">
                                    {{ number_format($stats['recent_children']) }}
                                </div>
                                <div class="text-xs text-gray-600">New This Month</div>
                            </div>
                            <div class="p-3 text-center bg-white border border-red-100 rounded-lg shadow-sm">
                                <div class="text-2xl font-bold text-red-600">
                                    {{ number_format(($stats['special_needs_count'] / max($stats['total_children'], 1)) * 100, 1) }}%
                                </div>
                                <div class="text-xs text-gray-600">Special Care</div>
                            </div>
                        </div>
                    </div>
                </x-card>
            </div>
        </details>
    </div>


</div>

<style>
/* Child-friendly animations */
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

/* Child-friendly focus states */
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

/* Custom gradients for child theme */
.bg-child-gradient {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 50%, #e0f2fe 100%);
}

.text-child-primary {
    color: #2563eb;
}

.border-child-primary {
    border-color: #2563eb;
}

/* Playful emoji animations */
.emoji-bounce {
    display: inline-block;
    animation: bounce-emoji 2s ease-in-out infinite;
}

@keyframes bounce-emoji {
    0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
    40% { transform: translateY(-3px); }
    60% { transform: translateY(-2px); }
}
</style>
