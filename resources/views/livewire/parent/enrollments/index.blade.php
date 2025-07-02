<?php

use App\Models\ProgramEnrollment;
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

new #[Title('My Enrollments')] class extends Component {
    use WithPagination;
    use Toast;

    // Search and filters
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $childFilter = '';

    #[Url]
    public string $curriculumFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Stats
    public array $stats = [];

    // Filter options
    public array $statusOptions = [];
    public array $childOptions = [];
    public array $curriculumOptions = [];

    public function mount(): void
    {
        // Log activity
        ActivityLog::logActivity(
            Auth::id(),
            'access',
            'Accessed enrollments page'
        );

        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        // Status options
        $this->statusOptions = [
            ['id' => '', 'name' => 'All Statuses'],
            ['id' => 'Active', 'name' => 'Active'],
            ['id' => 'Inactive', 'name' => 'Inactive'],
            ['id' => 'Suspended', 'name' => 'Suspended'],
            ['id' => 'Completed', 'name' => 'Completed'],
        ];

        // Child options - only children of the authenticated parent
        try {
            $children = ChildProfile::where('parent_id', Auth::id())
                ->orderBy('first_name')
                ->get();

            $this->childOptions = [
                ['id' => '', 'name' => 'All Children'],
                ...$children->map(fn($child) => [
                    'id' => $child->id,
                    'name' => $child->full_name
                ])->toArray()
            ];
        } catch (\Exception $e) {
            $this->childOptions = [['id' => '', 'name' => 'All Children']];
        }

        // Curriculum options - from existing enrollments
        try {
            $curricula = ProgramEnrollment::query()
                ->whereHas('childProfile', function ($query) {
                    $query->where('parent_id', Auth::id());
                })
                ->with('curriculum')
                ->get()
                ->pluck('curriculum')
                ->filter()
                ->unique('id')
                ->sortBy('name');

            $this->curriculumOptions = [
                ['id' => '', 'name' => 'All Programs'],
                ...$curricula->map(fn($curriculum) => [
                    'id' => $curriculum->id,
                    'name' => $curriculum->name
                ])->toArray()
            ];
        } catch (\Exception $e) {
            $this->curriculumOptions = [['id' => '', 'name' => 'All Programs']];
        }
    }

    protected function loadStats(): void
    {
        try {
            $enrollmentsQuery = ProgramEnrollment::query()
                ->whereHas('childProfile', function ($query) {
                    $query->where('parent_id', Auth::id());
                });

            $totalEnrollments = $enrollmentsQuery->count();
            $activeEnrollments = $enrollmentsQuery->where('status', 'Active')->count();
            $completedEnrollments = $enrollmentsQuery->where('status', 'Completed')->count();
            $suspendedEnrollments = $enrollmentsQuery->where('status', 'Suspended')->count();

            // Get unique children count with enrollments
            $childrenWithEnrollments = $enrollmentsQuery->distinct('child_profile_id')->count('child_profile_id');

            $this->stats = [
                'total_enrollments' => $totalEnrollments,
                'active_enrollments' => $activeEnrollments,
                'completed_enrollments' => $completedEnrollments,
                'suspended_enrollments' => $suspendedEnrollments,
                'children_enrolled' => $childrenWithEnrollments,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_enrollments' => 0,
                'active_enrollments' => 0,
                'completed_enrollments' => 0,
                'suspended_enrollments' => 0,
                'children_enrolled' => 0,
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
        $this->redirect(route('parent.enrollments.create'));
    }

    public function redirectToShow(int $enrollmentId): void
    {
        $this->redirect(route('parent.enrollments.show', $enrollmentId));
    }

    // Update methods
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedChildFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCurriculumFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->childFilter = '';
        $this->curriculumFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated enrollments
    public function enrollments(): LengthAwarePaginator
    {
        return ProgramEnrollment::query()
            ->whereHas('childProfile', function ($query) {
                $query->where('parent_id', Auth::id());
            })
            ->with(['childProfile', 'curriculum', 'academicYear', 'paymentPlan'])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->whereHas('childProfile', function ($childQuery) {
                        $childQuery->where('first_name', 'like', "%{$this->search}%")
                                  ->orWhere('last_name', 'like', "%{$this->search}%");
                    })
                    ->orWhereHas('curriculum', function ($curriculumQuery) {
                        $curriculumQuery->where('name', 'like', "%{$this->search}%");
                    })
                    ->orWhereHas('academicYear', function ($yearQuery) {
                        $yearQuery->where('name', 'like', "%{$this->search}%");
                    });
                });
            })
            ->when($this->statusFilter, function (Builder $query) {
                $query->where('status', $this->statusFilter);
            })
            ->when($this->childFilter, function (Builder $query) {
                $query->where('child_profile_id', $this->childFilter);
            })
            ->when($this->curriculumFilter, function (Builder $query) {
                $query->where('curriculum_id', $this->curriculumFilter);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Helper function to get status color
    private function getStatusColor(string $status): string
    {
        return match($status) {
            'Active' => 'bg-green-100 text-green-800',
            'Inactive' => 'bg-gray-100 text-gray-600',
            'Suspended' => 'bg-red-100 text-red-800',
            'Completed' => 'bg-blue-100 text-blue-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function with(): array
    {
        return [
            'enrollments' => $this->enrollments(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="My Enrollments" subtitle="View and manage your children's program enrollments" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search enrollments..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="New Enrollment"
                icon="o-plus"
                wire:click="redirectToCreate"
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
                        <x-icon name="o-academic-cap" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_enrollments']) }}</div>
                        <div class="text-sm text-gray-500">Total Enrollments</div>
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
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['active_enrollments']) }}</div>
                        <div class="text-sm text-gray-500">Active</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-blue-100 rounded-full">
                        <x-icon name="o-archive-box" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['completed_enrollments']) }}</div>
                        <div class="text-sm text-gray-500">Completed</div>
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
                        <div class="text-2xl font-bold text-red-600">{{ number_format($stats['suspended_enrollments']) }}</div>
                        <div class="text-sm text-gray-500">Suspended</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-users" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['children_enrolled']) }}</div>
                        <div class="text-sm text-gray-500">Children Enrolled</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Filters Row -->
    <div class="mb-6">
        <x-card>
            <div class="p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <x-select
                            label="Filter by Status"
                            :options="$statusOptions"
                            wire:model.live="statusFilter"
                            option-value="id"
                            option-label="name"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Filter by Child"
                            :options="$childOptions"
                            wire:model.live="childFilter"
                            option-value="id"
                            option-label="name"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Filter by Program"
                            :options="$curriculumOptions"
                            wire:model.live="curriculumFilter"
                            option-value="id"
                            option-label="name"
                        />
                    </div>

                    <div class="flex items-end">
                        <x-button
                            label="Clear Filters"
                            icon="o-x-mark"
                            wire:click="clearFilters"
                            class="w-full btn-outline"
                        />
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Enrollments List -->
    @if($enrollments->count() > 0)
        <div class="space-y-4">
            @foreach($enrollments as $enrollment)
                <x-card class="transition-shadow duration-200 hover:shadow-lg">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-start space-x-4">
                                <!-- Child Avatar -->
                                <div class="flex-shrink-0">
                                    <div class="avatar placeholder">
                                        <div class="w-12 h-12 text-white rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                                            <span class="text-sm font-bold">{{ $enrollment->childProfile->initials ?? '??' }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Enrollment Details -->
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <button
                                            wire:click="redirectToShow({{ $enrollment->id }})"
                                            class="text-lg font-semibold text-blue-600 underline hover:text-blue-800"
                                        >
                                            {{ $enrollment->curriculum->name ?? 'Unknown Program' }}
                                        </button>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($enrollment->status) }}">
                                            {{ $enrollment->status }}
                                        </span>
                                    </div>

                                    <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                                        <div class="flex items-center text-sm text-gray-600">
                                            <x-icon name="o-user" class="w-4 h-4 mr-2" />
                                            {{ $enrollment->childProfile->full_name ?? 'Unknown Child' }}
                                        </div>

                                        <div class="flex items-center text-sm text-gray-600">
                                            <x-icon name="o-calendar" class="w-4 h-4 mr-2" />
                                            {{ $enrollment->academicYear->name ?? 'Unknown Year' }}
                                        </div>

                                        @if($enrollment->paymentPlan)
                                            <div class="flex items-center text-sm text-gray-600">
                                                <x-icon name="o-credit-card" class="w-4 h-4 mr-2" />
                                                {{ $enrollment->paymentPlan->name }}
                                            </div>
                                        @endif
                                    </div>

                                    <div class="mt-2 text-sm text-gray-500">
                                        Enrolled: {{ $enrollment->created_at->format('M d, Y') }}
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex gap-2">
                                <x-button
                                    label="View"
                                    icon="o-eye"
                                    wire:click="redirectToShow({{ $enrollment->id }})"
                                    class="btn-sm btn-outline"
                                />

                                @if($enrollment->hasInvoices())
                                    <x-button
                                        label="Invoices"
                                        icon="o-document-text"
                                        link="{{ route('parent.invoices.index', ['enrollment' => $enrollment->id]) }}"
                                        class="btn-sm btn-ghost"
                                    />
                                @endif
                            </div>
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="mt-6">
            {{ $enrollments->links() }}
        </div>

        <!-- Results summary -->
        <div class="mt-4 text-sm text-gray-600">
            Showing {{ $enrollments->firstItem() ?? 0 }} to {{ $enrollments->lastItem() ?? 0 }}
            of {{ $enrollments->total() }} enrollments
            @if($search || $statusFilter || $childFilter || $curriculumFilter)
                (filtered from total)
            @endif
        </div>
    @else
        <!-- Empty State -->
        <x-card>
            <div class="py-12 text-center">
                <div class="flex flex-col items-center justify-center gap-4">
                    <x-icon name="o-academic-cap" class="w-20 h-20 text-gray-300" />
                    <div>
                        <h3 class="text-lg font-semibold text-gray-600">No enrollments found</h3>
                        <p class="mt-1 text-gray-500">
                            @if($search || $statusFilter || $childFilter || $curriculumFilter)
                                No enrollments match your current filters.
                            @else
                                Get started by enrolling your first child in a program.
                            @endif
                        </p>
                    </div>
                    @if($search || $statusFilter || $childFilter || $curriculumFilter)
                        <x-button
                            label="Clear Filters"
                            wire:click="clearFilters"
                            class="btn-secondary"
                        />
                    @else
                        <x-button
                            label="Create First Enrollment"
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
