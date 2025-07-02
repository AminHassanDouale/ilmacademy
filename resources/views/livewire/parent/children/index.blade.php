<?php

use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('My Children')] class extends Component {
    use WithPagination;
    use Toast;

    // Search and filters
    #[Url]
    public string $search = '';

    #[Url]
    public string $ageFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public array $sortBy = ['column' => 'first_name', 'direction' => 'asc'];

    // Stats
    public array $stats = [];

    // Filter options
    public array $ageOptions = [];

    public function mount(): void
    {
        // Log activity
        ActivityLog::logActivity(
            Auth::id(),
            'access',
            'Accessed my children page'
        );

        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        $this->ageOptions = [
            ['id' => '', 'name' => 'All Ages'],
            ['id' => '0-3', 'name' => '0-3 years'],
            ['id' => '4-6', 'name' => '4-6 years'],
            ['id' => '7-12', 'name' => '7-12 years'],
            ['id' => '13-18', 'name' => '13-18 years'],
        ];
    }

    protected function loadStats(): void
    {
        try {
            $childrenQuery = ChildProfile::where('parent_id', Auth::id());

            $totalChildren = $childrenQuery->count();
            $activeEnrollments = $childrenQuery->whereHas('programEnrollments', function ($query) {
                $query->where('status', 'Active');
            })->count();

            // Get age distribution
            $ageGroups = [
                '0-3' => $childrenQuery->byAge(0, 3)->count(),
                '4-6' => $childrenQuery->byAge(4, 6)->count(),
                '7-12' => $childrenQuery->byAge(7, 12)->count(),
                '13-18' => $childrenQuery->byAge(13, 18)->count(),
            ];

            $this->stats = [
                'total_children' => $totalChildren,
                'active_enrollments' => $activeEnrollments,
                'age_groups' => $ageGroups,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_children' => 0,
                'active_enrollments' => 0,
                'age_groups' => ['0-3' => 0, '4-6' => 0, '7-12' => 0, '13-18' => 0],
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

    // Navigation methods
    public function redirectToCreate(): void
    {
        $this->redirect(route('parent.children.create'));
    }

    public function redirectToShow(int $childId): void
    {
        $this->redirect(route('parent.children.show', $childId));
    }

    public function redirectToEdit(int $childId): void
    {
        $this->redirect(route('parent.children.edit', $childId));
    }

    // Update methods
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAgeFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->ageFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated children
    public function children(): LengthAwarePaginator
    {
        return ChildProfile::query()
            ->where('parent_id', Auth::id())
            ->with(['programEnrollments.curriculum', 'programEnrollments.academicYear'])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('first_name', 'like', "%{$this->search}%")
                      ->orWhere('last_name', 'like', "%{$this->search}%")
                      ->orWhere('email', 'like', "%{$this->search}%");
                });
            })
            ->when($this->ageFilter, function (Builder $query) {
                [$minAge, $maxAge] = explode('-', $this->ageFilter);
                $query->byAge((int)$minAge, (int)$maxAge);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Helper function to get age color
    private function getAgeColor(int $age): string
    {
        return match(true) {
            $age <= 3 => 'bg-pink-100 text-pink-800',
            $age <= 6 => 'bg-purple-100 text-purple-800',
            $age <= 12 => 'bg-blue-100 text-blue-800',
            $age <= 18 => 'bg-green-100 text-green-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to format age
    private function formatAge(?int $age): string
    {
        if (!$age) return 'Unknown';
        return $age . ' year' . ($age !== 1 ? 's' : '') . ' old';
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
    <x-header title="My Children" subtitle="Manage your children's profiles and enrollments" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search children..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Add Child"
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
                        <x-icon name="o-academic-cap" class="w-8 h-8 text-green-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['active_enrollments']) }}</div>
                        <div class="text-sm text-gray-500">Active Enrollments</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-cake" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['age_groups']['4-6']) }}</div>
                        <div class="text-sm text-gray-500">Preschool Age</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-orange-100 rounded-full">
                        <x-icon name="o-book-open" class="w-8 h-8 text-orange-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['age_groups']['7-12']) }}</div>
                        <div class="text-sm text-gray-500">School Age</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Children Grid/List -->
    @if($children->count() > 0)
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach($children as $child)
                <x-card class="transition-shadow duration-200 hover:shadow-lg">
                    <div class="p-6">
                        <!-- Child Header -->
                        <div class="flex items-center mb-4">
                            <div class="mr-4 avatar placeholder">
                                <div class="w-16 h-16 text-white rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                                    <span class="text-lg font-bold">{{ $child->initials }}</span>
                                </div>
                            </div>
                            <div class="flex-1">
                                <button
                                    wire:click="redirectToShow({{ $child->id }})"
                                    class="text-lg font-semibold text-left text-blue-600 underline hover:text-blue-800"
                                >
                                    {{ $child->full_name }}
                                </button>
                                @if($child->age)
                                    <div class="text-sm text-gray-500">{{ $this->formatAge($child->age) }}</div>
                                @endif
                            </div>
                        </div>

                        <!-- Child Details -->
                        <div class="mb-4 space-y-3">
                            @if($child->date_of_birth)
                                <div class="flex items-center text-sm">
                                    <x-icon name="o-calendar" class="w-4 h-4 mr-2 text-gray-400" />
                                    <span>Born {{ $child->date_of_birth->format('M d, Y') }}</span>
                                    @if($child->age)
                                        <span class="ml-2 px-2 py-1 rounded-full text-xs font-medium {{ $this->getAgeColor($child->age) }}">
                                            {{ $child->age }}y
                                        </span>
                                    @endif
                                </div>
                            @endif

                            @if($child->email)
                                <div class="flex items-center text-sm">
                                    <x-icon name="o-envelope" class="w-4 h-4 mr-2 text-gray-400" />
                                    <span class="font-mono text-xs">{{ $child->email }}</span>
                                </div>
                            @endif

                            @if($child->phone)
                                <div class="flex items-center text-sm">
                                    <x-icon name="o-phone" class="w-4 h-4 mr-2 text-gray-400" />
                                    <span class="font-mono text-xs">{{ $child->phone }}</span>
                                </div>
                            @endif
                        </div>

                        <!-- Enrollments -->
                        @if($child->programEnrollments->count() > 0)
                            <div class="mb-4">
                                <div class="mb-2 text-sm font-medium text-gray-700">Current Enrollments</div>
                                <div class="space-y-2">
                                    @foreach($child->programEnrollments->take(2) as $enrollment)
                                        <div class="p-2 rounded-md bg-gray-50">
                                            <div class="text-sm font-medium">{{ $enrollment->curriculum->name ?? 'Unknown Curriculum' }}</div>
                                            <div class="text-xs text-gray-500">{{ $enrollment->academicYear->name ?? 'Unknown Year' }}</div>
                                        </div>
                                    @endforeach
                                    @if($child->programEnrollments->count() > 2)
                                        <div class="text-xs text-gray-500">+{{ $child->programEnrollments->count() - 2 }} more</div>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div class="p-3 mb-4 border border-yellow-200 rounded-md bg-yellow-50">
                                <div class="text-sm text-yellow-800">No active enrollments</div>
                                <div class="text-xs text-yellow-600">Consider enrolling in a program</div>
                            </div>
                        @endif

                        <!-- Action Buttons -->
                        <div class="flex gap-2">
                            <x-button
                                label="View"
                                icon="o-eye"
                                wire:click="redirectToShow({{ $child->id }})"
                                class="flex-1 btn-sm btn-outline"
                            />
                            <x-button
                                label="Edit"
                                icon="o-pencil"
                                wire:click="redirectToEdit({{ $child->id }})"
                                class="flex-1 btn-sm btn-primary"
                            />
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="mt-6">
            {{ $children->links() }}
        </div>
    @else
        <!-- Empty State -->
        <x-card>
            <div class="py-12 text-center">
                <div class="flex flex-col items-center justify-center gap-4">
                    <x-icon name="o-users" class="w-20 h-20 text-gray-300" />
                    <div>
                        <h3 class="text-lg font-semibold text-gray-600">No children found</h3>
                        <p class="mt-1 text-gray-500">
                            @if($search || $ageFilter)
                                No children match your current filters.
                            @else
                                Add your first child to get started with enrollments and tracking.
                            @endif
                        </p>
                    </div>
                    @if($search || $ageFilter)
                        <x-button
                            label="Clear Filters"
                            wire:click="clearFilters"
                            class="btn-secondary"
                        />
                    @else
                        <x-button
                            label="Add First Child"
                            icon="o-plus"
                            wire:click="redirectToCreate"
                            class="btn-primary"
                        />
                    @endif
                </div>
            </div>
        </x-card>
    @endif

    <!-- Quick Actions Floating Button (Mobile) -->
    <div class="fixed bottom-6 right-6 md:hidden">
        <x-button
            icon="o-plus"
            wire:click="redirectToCreate"
            class="shadow-lg btn-primary btn-circle btn-lg"
        />
    </div>
</div>
