<?php

use App\Models\User;
use App\Models\ChildProfile;
use App\Models\ProgramEnrollment;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('My Enrollments')] class extends Component {
    use WithPagination;
    use Toast;

    // Current user
    public User $user;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $academicYearFilter = '';

    #[Url]
    public string $curriculumFilter = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Stats
    public array $stats = [];

    // Filter options
    public array $statusOptions = [];
    public array $academicYearOptions = [];
    public array $curriculumOptions = [];

    // Child profiles for students with multiple children (if user is parent)
    public array $childProfiles = [];

    public function mount(): void
    {
        $this->user = Auth::user();

        // Log activity
        ActivityLog::log(
            $this->user->id,
            'access',
            'Accessed student enrollments page',
            ProgramEnrollment::class,
            null,
            ['ip' => request()->ip()]
        );

        $this->loadFilterOptions();
        $this->loadStats();
        $this->loadChildProfiles();
    }

    protected function loadFilterOptions(): void
    {
        // Status options
        $this->statusOptions = [
            ['id' => '', 'name' => 'All Statuses'],
            ['id' => 'Active', 'name' => 'Active'],
            ['id' => 'Inactive', 'name' => 'Inactive'],
            ['id' => 'Completed', 'name' => 'Completed'],
            ['id' => 'Suspended', 'name' => 'Suspended'],
        ];

        try {
            // Get academic years with enrollments for this user
            $academicYears = AcademicYear::whereHas('programEnrollments', function ($query) {
                $query->whereHas('childProfile', function ($childQuery) {
                    if ($this->user->hasRole('student')) {
                        // If user is a student, get their own enrollments
                        $childQuery->where('user_id', $this->user->id);
                    } else {
                        // If user is a parent, get their children's enrollments
                        $childQuery->where('parent_id', $this->user->id);
                    }
                });
            })->orderBy('name')->get();

            $this->academicYearOptions = [
                ['id' => '', 'name' => 'All Academic Years'],
                ...$academicYears->map(fn($year) => [
                    'id' => $year->id,
                    'name' => $year->name
                ])->toArray()
            ];

            // Get curricula with enrollments for this user
            $curricula = Curriculum::whereHas('programEnrollments', function ($query) {
                $query->whereHas('childProfile', function ($childQuery) {
                    if ($this->user->hasRole('student')) {
                        $childQuery->where('user_id', $this->user->id);
                    } else {
                        $childQuery->where('parent_id', $this->user->id);
                    }
                });
            })->orderBy('name')->get();

            $this->curriculumOptions = [
                ['id' => '', 'name' => 'All Programs'],
                ...$curricula->map(fn($curriculum) => [
                    'id' => $curriculum->id,
                    'name' => $curriculum->name
                ])->toArray()
            ];

        } catch (\Exception $e) {
            $this->academicYearOptions = [['id' => '', 'name' => 'All Academic Years']];
            $this->curriculumOptions = [['id' => '', 'name' => 'All Programs']];
        }
    }

    protected function loadStats(): void
    {
        try {
            $baseQuery = $this->getBaseEnrollmentsQuery();

            $totalEnrollments = (clone $baseQuery)->count();
            $activeEnrollments = (clone $baseQuery)->where('status', 'Active')->count();
            $completedEnrollments = (clone $baseQuery)->where('status', 'Completed')->count();
            $suspendedEnrollments = (clone $baseQuery)->where('status', 'Suspended')->count();

            // Current academic year enrollments
            $currentAcademicYear = AcademicYear::where('is_current', true)->first();
            $currentYearEnrollments = $currentAcademicYear
                ? (clone $baseQuery)->where('academic_year_id', $currentAcademicYear->id)->count()
                : 0;

            $this->stats = [
                'total_enrollments' => $totalEnrollments,
                'active_enrollments' => $activeEnrollments,
                'completed_enrollments' => $completedEnrollments,
                'suspended_enrollments' => $suspendedEnrollments,
                'current_year_enrollments' => $currentYearEnrollments,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_enrollments' => 0,
                'active_enrollments' => 0,
                'completed_enrollments' => 0,
                'suspended_enrollments' => 0,
                'current_year_enrollments' => 0,
            ];
        }
    }

    protected function loadChildProfiles(): void
    {
        try {
            if ($this->user->hasRole('student')) {
                // If user is a student, get their own child profile
                $childProfile = ChildProfile::where('user_id', $this->user->id)->first();
                $this->childProfiles = $childProfile ? [$childProfile] : [];
            } else {
                // If user is a parent, get all their children
                $this->childProfiles = ChildProfile::where('parent_id', $this->user->id)
                    ->orderBy('first_name')
                    ->get()
                    ->toArray();
            }
        } catch (\Exception $e) {
            $this->childProfiles = [];
        }
    }

    protected function getBaseEnrollmentsQuery(): Builder
    {
        return ProgramEnrollment::query()
            ->with(['childProfile', 'curriculum', 'academicYear', 'paymentPlan'])
            ->whereHas('childProfile', function ($query) {
                if ($this->user->hasRole('student')) {
                    // If user is a student, get their own enrollments
                    $query->where('user_id', $this->user->id);
                } else {
                    // If user is a parent, get their children's enrollments
                    $query->where('parent_id', $this->user->id);
                }
            });
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

    // Redirect to enrollment show page
    public function redirectToShow(int $enrollmentId): void
    {
        $this->redirect(route('student.enrollments.show', $enrollmentId));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAcademicYearFilter(): void
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
        $this->academicYearFilter = '';
        $this->curriculumFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated enrollments
    public function enrollments(): LengthAwarePaginator
    {
        return $this->getBaseEnrollmentsQuery()
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->whereHas('childProfile', function ($childQuery) {
                        $childQuery->where('first_name', 'like', "%{$this->search}%")
                                  ->orWhere('last_name', 'like', "%{$this->search}%")
                                  ->orWhere('email', 'like', "%{$this->search}%");
                    })
                    ->orWhereHas('curriculum', function ($curriculumQuery) {
                        $curriculumQuery->where('name', 'like', "%{$this->search}%")
                                       ->orWhere('code', 'like', "%{$this->search}%");
                    })
                    ->orWhereHas('academicYear', function ($yearQuery) {
                        $yearQuery->where('name', 'like', "%{$this->search}%");
                    });
                });
            })
            ->when($this->statusFilter, function (Builder $query) {
                $query->where('status', $this->statusFilter);
            })
            ->when($this->academicYearFilter, function (Builder $query) {
                $query->where('academic_year_id', $this->academicYearFilter);
            })
            ->when($this->curriculumFilter, function (Builder $query) {
                $query->where('curriculum_id', $this->curriculumFilter);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->academicYearFilter = '';
        $this->curriculumFilter = '';
        $this->resetPage();
    }

    // Helper function to get status color
    private function getStatusColor(string $status): string
    {
        return match($status) {
            'Active' => 'bg-green-100 text-green-800',
            'Inactive' => 'bg-gray-100 text-gray-600',
            'Completed' => 'bg-blue-100 text-blue-800',
            'Suspended' => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to get enrollment progress
    private function getEnrollmentProgress($enrollment): array
    {
        try {
            // Calculate progress based on subject enrollments or other criteria
            $totalSubjects = $enrollment->subjectEnrollments ? $enrollment->subjectEnrollments->count() : 0;

            // For demo purposes, we'll calculate a simple progress
            $progress = $totalSubjects > 0 ? min(100, ($totalSubjects * 20)) : 0;

            return [
                'percentage' => $progress,
                'total_subjects' => $totalSubjects,
                'status' => $enrollment->status
            ];
        } catch (\Exception $e) {
            return [
                'percentage' => 0,
                'total_subjects' => 0,
                'status' => $enrollment->status ?? 'Unknown'
            ];
        }
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
    <x-header title="My Enrollments" subtitle="View and manage your program enrollments" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search enrollments..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$statusFilter, $academicYearFilter, $curriculumFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="Profile"
                icon="o-user"
                link="{{ route('student.profile.edit') }}"
                class="btn-ghost"
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
                        <x-icon name="o-trophy" class="w-8 h-8 text-blue-600" />
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
                    <div class="p-3 mr-4 bg-orange-100 rounded-full">
                        <x-icon name="o-calendar" class="w-8 h-8 text-orange-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['current_year_enrollments']) }}</div>
                        <div class="text-sm text-gray-500">This Year</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-red-100 rounded-full">
                        <x-icon name="o-pause-circle" class="w-8 h-8 text-red-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-red-600">{{ number_format($stats['suspended_enrollments']) }}</div>
                        <div class="text-sm text-gray-500">Suspended</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Enrollments List -->
    @if($enrollments->count() > 0)
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach($enrollments as $enrollment)
                @php
                    $progress = $this->getEnrollmentProgress($enrollment);
                @endphp
                <x-card class="transition-shadow duration-200 hover:shadow-lg">
                    <div class="p-6">
                        <!-- Header with status -->
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex-1">
                                <h3 class="mb-1 text-lg font-semibold text-gray-900">
                                    {{ $enrollment->curriculum->name ?? 'Unknown Program' }}
                                </h3>
                                <p class="text-sm text-gray-500">
                                    {{ $enrollment->academicYear->name ?? 'Unknown Year' }}
                                </p>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($enrollment->status) }}">
                                {{ $enrollment->status }}
                            </span>
                        </div>

                        <!-- Student Info (for parents with multiple children) -->
                        @if(count($childProfiles) > 1)
                            <div class="flex items-center p-3 mb-4 rounded-lg bg-gray-50">
                                <div class="mr-3 avatar">
                                    <div class="w-10 h-10 rounded-full">
                                        <img src="https://ui-avatars.com/api/?name={{ urlencode($enrollment->childProfile->full_name ?? 'Student') }}&color=7F9CF5&background=EBF4FF" alt="{{ $enrollment->childProfile->full_name ?? 'Student' }}" />
                                    </div>
                                </div>
                                <div>
                                    <div class="text-sm font-medium">{{ $enrollment->childProfile->full_name ?? 'Unknown Student' }}</div>
                                    @if($enrollment->childProfile && $enrollment->childProfile->age)
                                        <div class="text-xs text-gray-500">Age: {{ $enrollment->childProfile->age }}</div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <!-- Progress Bar -->
                        <div class="mb-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-700">Progress</span>
                                <span class="text-sm text-gray-500">{{ $progress['percentage'] }}%</span>
                            </div>
                            <div class="w-full h-2 bg-gray-200 rounded-full">
                                <div class="h-2 transition-all duration-300 bg-blue-600 rounded-full" style="width: {{ $progress['percentage'] }}%"></div>
                            </div>
                        </div>

                        <!-- Quick Info -->
                        <div class="mb-4 space-y-3">
                            @if($enrollment->curriculum)
                                <div class="flex items-center text-sm text-gray-600">
                                    <x-icon name="o-book-open" class="w-4 h-4 mr-2" />
                                    <span>{{ $enrollment->curriculum->code ?? 'No Code' }}</span>
                                </div>
                            @endif

                            @if($enrollment->paymentPlan)
                                <div class="flex items-center text-sm text-gray-600">
                                    <x-icon name="o-credit-card" class="w-4 h-4 mr-2" />
                                    <span>{{ $enrollment->paymentPlan->name ?? 'Payment Plan' }}</span>
                                </div>
                            @endif

                            <div class="flex items-center text-sm text-gray-600">
                                <x-icon name="o-calendar" class="w-4 h-4 mr-2" />
                                <span>Enrolled {{ $enrollment->created_at->format('M d, Y') }}</span>
                            </div>

                            @if($progress['total_subjects'] > 0)
                                <div class="flex items-center text-sm text-gray-600">
                                    <x-icon name="o-document-text" class="w-4 h-4 mr-2" />
                                    <span>{{ $progress['total_subjects'] }} Subject{{ $progress['total_subjects'] !== 1 ? 's' : '' }}</span>
                                </div>
                            @endif
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                            <div class="flex space-x-2">
                                @if($enrollment->status === 'Active')
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-green-700 bg-green-100 rounded">
                                        <x-icon name="o-play" class="w-3 h-3 mr-1" />
                                        In Progress
                                    </span>
                                @elseif($enrollment->status === 'Completed')
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-700 bg-blue-100 rounded">
                                        <x-icon name="o-check" class="w-3 h-3 mr-1" />
                                        Completed
                                    </span>
                                @endif
                            </div>

                            <x-button
                                label="View Details"
                                icon="o-arrow-right"
                                wire:click="redirectToShow({{ $enrollment->id }})"
                                class="btn-sm btn-primary"
                            />
                        </div>

                        <!-- Quick Access Buttons -->
                        <div class="grid grid-cols-2 gap-2 mt-3">
                            <x-button
                                label="Sessions"
                                icon="o-calendar"
                                link="{{ route('student.sessions.index') }}?enrollment={{ $enrollment->id }}"
                                class="btn-xs btn-outline"
                            />
                            <x-button
                                label="Invoices"
                                icon="o-currency-dollar"
                                link="{{ route('student.invoices.index') }}?enrollment={{ $enrollment->id }}"
                                class="btn-xs btn-outline"
                            />
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="mt-8">
            {{ $enrollments->links() }}
        </div>

        <!-- Results summary -->
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $enrollments->firstItem() ?? 0 }} to {{ $enrollments->lastItem() ?? 0 }}
            of {{ $enrollments->total() }} enrollments
            @if($search || $statusFilter || $academicYearFilter || $curriculumFilter)
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
                            @if($search || $statusFilter || $academicYearFilter || $curriculumFilter)
                                No enrollments match your current filters.
                            @else
                                You don't have any program enrollments yet.
                            @endif
                        </p>
                    </div>
                    @if($search || $statusFilter || $academicYearFilter || $curriculumFilter)
                        <x-button
                            label="Clear Filters"
                            wire:click="resetFilters"
                            color="secondary"
                            size="sm"
                        />
                    @else
                        <div class="text-sm text-gray-500">
                            Contact your administrator to enroll in programs.
                        </div>
                    @endif
                </div>
            </div>
        </x-card>
    @endif

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Filter Enrollments" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search enrollments"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by program, student, or year..."
                    clearable
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
                    label="Filter by academic year"
                    :options="$academicYearOptions"
                    wire:model.live="academicYearFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All academic years"
                />
            </div>

            <div>
                <x-select
                    label="Filter by program"
                    :options="$curriculumOptions"
                    wire:model.live="curriculumFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All programs"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[
                        ['id' => 6, 'name' => '6 per page'],
                        ['id' => 10, 'name' => '10 per page'],
                        ['id' => 15, 'name' => '15 per page'],
                        ['id' => 20, 'name' => '20 per page']
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

    <!-- Quick Actions Sidebar (if multiple children) -->
    @if(count($childProfiles) > 1)
        <div class="fixed z-50 bottom-4 right-4">
            <x-dropdown>
                <x-slot:trigger>
                    <x-button icon="o-user-group" class="shadow-lg btn-circle btn-primary">
                        {{ count($childProfiles) }}
                    </x-button>
                </x-slot:trigger>

                <x-menu-item title="Quick Access" />
                <x-menu-separator />

                @foreach($childProfiles as $child)
                    <x-menu-item
                        title="{{ $child['full_name'] ?? 'Unknown Student' }}"
                        subtitle="View enrollments"
                        link="{{ route('student.enrollments.index') }}?child={{ $child['id'] ?? '' }}"
                    />
                @endforeach
            </x-dropdown>
        </div>
    @endif
</div>
