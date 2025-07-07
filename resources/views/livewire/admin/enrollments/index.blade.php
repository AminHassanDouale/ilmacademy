<?php

use App\Models\SubjectEnrollment;
use App\Models\Subject;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Subject Enrollments Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $subject = '';

    #[Url]
    public string $academicYear = '';

    #[Url]
    public string $status = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'id', 'direction' => 'desc'];

    // Modal state
    public bool $showDeleteModal = false;
    public ?int $enrollmentToDelete = null;

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed subject enrollments management page',
            SubjectEnrollment::class,
            null,
            ['ip' => request()->ip()]
        );
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
    }

    // Redirect to create page
    public function redirectToCreate(): void
    {
        $this->redirect(route('admin.subject-enrollments.create'));
    }

    // Redirect to show page
    public function redirectToShow(int $enrollmentId): void
    {
        $this->redirect(route('admin.subject-enrollments.show', $enrollmentId));
    }

    // Redirect to edit page
    public function redirectToEdit(int $enrollmentId): void
    {
        $this->redirect(route('admin.subject-enrollments.edit', $enrollmentId));
    }

    // Confirm deletion
    public function confirmDelete(int $enrollmentId): void
    {
        $this->enrollmentToDelete = $enrollmentId;
        $this->showDeleteModal = true;
    }

    // Cancel deletion
    public function cancelDelete(): void
    {
        $this->enrollmentToDelete = null;
        $this->showDeleteModal = false;
    }

    // Delete an enrollment
    public function deleteEnrollment(): void
    {
        if ($this->enrollmentToDelete) {
            $enrollment = SubjectEnrollment::find($this->enrollmentToDelete);

            if ($enrollment) {
                // Get data for logging before deletion
                $enrollmentDetails = [
                    'id' => $enrollment->id,
                    'student_name' => $enrollment->programEnrollment->childProfile->full_name ?? 'Unknown',
                    'subject_name' => $enrollment->subject->name ?? 'Unknown',
                    'subject_code' => $enrollment->subject->code ?? 'Unknown',
                    'academic_year' => $enrollment->programEnrollment->academicYear->name ?? 'Unknown'
                ];

                try {
                    DB::beginTransaction();

                    // Delete enrollment
                    $enrollment->delete();

                    // Log the action
                    ActivityLog::log(
                        Auth::id(),
                        'delete',
                        "Deleted enrollment: {$enrollmentDetails['student_name']} from {$enrollmentDetails['subject_name']}",
                        SubjectEnrollment::class,
                        $this->enrollmentToDelete,
                        [
                            'student_name' => $enrollmentDetails['student_name'],
                            'subject_name' => $enrollmentDetails['subject_name'],
                            'subject_code' => $enrollmentDetails['subject_code'],
                            'academic_year' => $enrollmentDetails['academic_year']
                        ]
                    );

                    DB::commit();

                    // Show toast notification
                    $this->success("Enrollment for {$enrollmentDetails['student_name']} has been successfully deleted.");

                    // Refresh the page data after successful deletion
                    $this->resetPage();
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("An error occurred during deletion: {$e->getMessage()}");
                }
            } else {
                $this->error("Enrollment not found!");
            }
        }

        $this->showDeleteModal = false;
        $this->enrollmentToDelete = null;
    }

    // Get filtered and paginated enrollments
    public function enrollments(): LengthAwarePaginator
    {
        return SubjectEnrollment::query()
            ->with(['programEnrollment.childProfile', 'subject.curriculum', 'programEnrollment.academicYear'])
            ->when($this->search, function (Builder $query) {
                $query->whereHas('programEnrollment.childProfile', function (Builder $q) {
                    $q->where('first_name', 'like', '%' . $this->search . '%')
                      ->orWhere('last_name', 'like', '%' . $this->search . '%')
                      ->orWhere('email', 'like', '%' . $this->search . '%');
                })
                ->orWhereHas('subject', function (Builder $q) {
                    $q->where('name', 'like', '%' . $this->search . '%')
                      ->orWhere('code', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->subject, function (Builder $query) {
                $query->where('subject_id', $this->subject);
            })
            ->when($this->academicYear, function (Builder $query) {
                $query->whereHas('programEnrollment.academicYear', function (Builder $q) {
                    $q->where('id', $this->academicYear);
                });
            })
            ->when($this->sortBy['column'] === 'student', function (Builder $query) {
                $query->join('program_enrollments', 'subject_enrollments.program_enrollment_id', '=', 'program_enrollments.id')
                    ->join('child_profiles', 'program_enrollments.child_profile_id', '=', 'child_profiles.id')
                    ->orderBy('child_profiles.first_name', $this->sortBy['direction'])
                    ->select('subject_enrollments.*');
            })
            ->when($this->sortBy['column'] === 'subject', function (Builder $query) {
                $query->join('subjects', 'subject_enrollments.subject_id', '=', 'subjects.id')
                    ->orderBy('subjects.name', $this->sortBy['direction'])
                    ->select('subject_enrollments.*');
            }, function (Builder $query) {
                if (!in_array($this->sortBy['column'], ['student', 'subject'])) {
                    $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);
                }
            })
            ->paginate($this->perPage);
    }

    // Get subjects for filter
    public function subjects(): Collection
    {
        return Subject::query()
            ->with('curriculum')
            ->orderBy('name')
            ->get();
    }

    // Get academic years for filter
    public function academicYears(): Collection
    {
        return AcademicYear::query()
            ->orderBy('start_date', 'desc')
            ->get();
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->subject = '';
        $this->academicYear = '';
        $this->status = '';
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'enrollments' => $this->enrollments(),
            'subjects' => $this->subjects(),
            'academicYears' => $this->academicYears(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Subject Enrollments Management" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search by student or subject..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$subject, $academicYear, $status]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="New Enrollment"
                icon="o-plus"
                wire:click="redirectToCreate"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Enrollments table -->
    <x-card>
        <!-- Mobile/Tablet Card View (hidden on desktop) -->
        <div class="block lg:hidden">
            <div class="space-y-4">
                @forelse ($enrollments as $enrollment)
                    <div class="p-4 transition-colors border rounded-lg bg-base-50 hover:bg-base-100">
                        <!-- Student Header -->
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center flex-1 min-w-0 space-x-3">
                                <div class="flex-shrink-0 avatar">
                                    <div class="flex items-center justify-center w-12 h-12 bg-blue-100 rounded-full">
                                        <span class="text-sm font-medium text-blue-600">
                                            {{ $enrollment->programEnrollment->childProfile->initials ?? 'U' }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-lg font-semibold truncate">{{ $enrollment->programEnrollment->childProfile->full_name ?? 'Unknown Student' }}</h3>
                                    <p class="text-sm text-gray-500 truncate">{{ $enrollment->programEnrollment->childProfile->email ?? 'No email' }}</p>
                                    <div class="mt-1 font-mono text-xs text-gray-400">#{{ $enrollment->id }}</div>
                                </div>
                            </div>
                            <!-- Actions -->
                            <div class="flex gap-1 ml-2">
                                <button
                                    wire:click="redirectToShow({{ $enrollment->id }})"
                                    class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                    title="View"
                                >
                                    👁️
                                </button>
                                <button
                                    wire:click="redirectToEdit({{ $enrollment->id }})"
                                    class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                    title="Edit"
                                >
                                    ✏️
                                </button>
                                <button
                                    wire:click="confirmDelete({{ $enrollment->id }})"
                                    class="p-2 text-red-600 bg-red-100 rounded-md hover:text-red-900 hover:bg-red-200"
                                    title="Delete"
                                >
                                    🗑️
                                </button>
                            </div>
                        </div>

                        <!-- Subject Information -->
                        <div class="p-3 mb-4 border border-blue-200 rounded-lg bg-blue-50">
                            <div class="flex items-start justify-between mb-2">
                                <div class="flex-1 min-w-0">
                                    <h4 class="font-medium text-blue-900 truncate">{{ $enrollment->subject->name ?? 'Unknown Subject' }}</h4>
                                    <div class="flex items-center gap-2 mt-1">
                                        @if($enrollment->subject?->code)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-800 text-white font-mono">
                                                {{ $enrollment->subject->code }}
                                            </span>
                                        @endif
                                        @if($enrollment->subject?->level)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                Level {{ $enrollment->subject->level }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <!-- Curriculum -->
                            @if($enrollment->subject?->curriculum)
                                <div class="text-sm">
                                    <span class="text-gray-600">Curriculum: </span>
                                    <a href="{{ route('admin.curricula.show', $enrollment->subject->curriculum->id) }}" class="text-blue-600 underline hover:text-blue-800">
                                        {{ $enrollment->subject->curriculum->name }}
                                    </a>
                                    @if($enrollment->subject->curriculum->code)
                                        <span class="ml-1 text-xs text-gray-500">({{ $enrollment->subject->curriculum->code }})</span>
                                    @endif
                                </div>
                            @else
                                <div class="text-sm italic text-gray-400">No curriculum assigned</div>
                            @endif
                        </div>

                        <!-- Academic Year & Enrollment Date -->
                        <div class="grid grid-cols-1 gap-3 mb-3 sm:grid-cols-2">
                            <div>
                                <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Academic Year</label>
                                @if($enrollment->programEnrollment?->academicYear)
                                    <div class="text-sm font-medium">{{ $enrollment->programEnrollment->academicYear->name }}</div>
                                    <div class="text-xs text-gray-500">
                                        {{ $enrollment->programEnrollment->academicYear->start_date->format('Y') }} -
                                        {{ $enrollment->programEnrollment->academicYear->end_date->format('Y') }}
                                    </div>
                                    @if($enrollment->programEnrollment->academicYear->is_current)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 mt-1">
                                            ✅ Current
                                        </span>
                                    @endif
                                @else
                                    <span class="text-sm italic text-gray-400">No academic year</span>
                                @endif
                            </div>
                            <div>
                                <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Enrolled Date</label>
                                <div class="text-sm font-medium">{{ $enrollment->created_at->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $enrollment->created_at->format('h:i A') }}</div>
                                <div class="mt-1 text-xs text-gray-400">{{ $enrollment->created_at->diffForHumans() }}</div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="py-12 text-center">
                        <div class="flex flex-col items-center justify-center gap-4">
                            <x-icon name="o-user-group" class="w-16 h-16 text-gray-300" />
                            <div>
                                <h3 class="text-lg font-semibold text-gray-600">No enrollments found</h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    @if($search || $subject || $academicYear || $status)
                                        No enrollments match your current filters.
                                    @else
                                        Get started by creating your first enrollment.
                                    @endif
                                </p>
                            </div>
                            @if($search || $subject || $academicYear || $status)
                                <x-button
                                    label="Clear Filters"
                                    wire:click="resetFilters"
                                    color="secondary"
                                    size="sm"
                                />
                            @else
                                <x-button
                                    label="Create First Enrollment"
                                    icon="o-plus"
                                    wire:click="redirectToCreate"
                                    color="primary"
                                />
                            @endif
                        </div>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Desktop Table View (hidden on mobile/tablet) -->
        <div class="hidden lg:block">
            <div class="overflow-x-auto">
                <table class="table w-full table-zebra">
                    <thead>
                        <tr>
                            <th class="cursor-pointer" wire:click="sortBy('id')">
                                <div class="flex items-center">
                                    ID
                                    @if ($sortBy['column'] === 'id')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="cursor-pointer" wire:click="sortBy('student')">
                                <div class="flex items-center">
                                    Student
                                    @if ($sortBy['column'] === 'student')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="cursor-pointer" wire:click="sortBy('subject')">
                                <div class="flex items-center">
                                    Subject
                                    @if ($sortBy['column'] === 'subject')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th>Curriculum</th>
                            <th>Academic Year</th>
                            <th class="cursor-pointer" wire:click="sortBy('created_at')">
                                <div class="flex items-center">
                                    Enrolled Date
                                    @if ($sortBy['column'] === 'created_at')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($enrollments as $enrollment)
                            <tr class="hover">
                                <td class="font-mono text-sm">#{{ $enrollment->id }}</td>
                                <td>
                                    <div class="flex items-center space-x-3">
                                        <div class="avatar">
                                            <div class="flex items-center justify-center w-10 h-10 bg-blue-100 rounded-full">
                                                <span class="text-sm font-medium text-blue-600">
                                                    {{ $enrollment->programEnrollment->childProfile->initials ?? 'U' }}
                                                </span>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="font-medium">{{ $enrollment->programEnrollment->childProfile->full_name ?? 'Unknown Student' }}</div>
                                            <div class="text-sm text-gray-500">{{ $enrollment->programEnrollment->childProfile->email ?? 'No email' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="font-medium">{{ $enrollment->subject->name ?? 'Unknown Subject' }}</div>
                                    @if($enrollment->subject?->code)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 font-mono">
                                            {{ $enrollment->subject->code }}
                                        </span>
                                    @endif
                                    @if($enrollment->subject?->level)
                                        <div class="mt-1 text-xs text-gray-500">Level: {{ $enrollment->subject->level }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if($enrollment->subject?->curriculum)
                                        <a href="{{ route('admin.curricula.show', $enrollment->subject->curriculum->id) }}" class="text-sm text-blue-600 underline hover:text-blue-800">
                                            {{ $enrollment->subject->curriculum->name }}
                                        </a>
                                        @if($enrollment->subject->curriculum->code)
                                            <div class="text-xs text-gray-500">{{ $enrollment->subject->curriculum->code }}</div>
                                        @endif
                                    @else
                                        <span class="text-sm italic text-gray-400">No curriculum</span>
                                    @endif
                                </td>
                                <td>
                                    @if($enrollment->programEnrollment?->academicYear)
                                        <div class="font-medium">{{ $enrollment->programEnrollment->academicYear->name }}</div>
                                        <div class="text-xs text-gray-500">
                                            {{ $enrollment->programEnrollment->academicYear->start_date->format('Y') }} -
                                            {{ $enrollment->programEnrollment->academicYear->end_date->format('Y') }}
                                        </div>
                                        @if($enrollment->programEnrollment->academicYear->is_current)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 mt-1">
                                                Current
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-sm italic text-gray-400">No academic year</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="font-medium">{{ $enrollment->created_at->format('M d, Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $enrollment->created_at->format('h:i A') }}</div>
                                </td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            wire:click="redirectToShow({{ $enrollment->id }})"
                                            class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                            title="View"
                                        >
                                            👁️
                                        </button>
                                        <button
                                            wire:click="redirectToEdit({{ $enrollment->id }})"
                                            class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                            title="Edit"
                                        >
                                            ✏️
                                        </button>
                                        <button
                                            wire:click="confirmDelete({{ $enrollment->id }})"
                                            class="p-2 text-red-600 bg-red-100 rounded-md hover:text-red-900 hover:bg-red-200"
                                            title="Delete"
                                        >
                                            🗑️
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-12 text-center">
                                    <div class="flex flex-col items-center justify-center gap-4">
                                        <x-icon name="o-user-group" class="w-20 h-20 text-gray-300" />
                                        <div>
                                            <h3 class="text-lg font-semibold text-gray-600">No enrollments found</h3>
                                            <p class="mt-1 text-gray-500">
                                                @if($search || $subject || $academicYear || $status)
                                                    No enrollments match your current filters.
                                                @else
                                                    Get started by creating your first enrollment.
                                                @endif
                                            </p>
                                        </div>
                                        @if($search || $subject || $academicYear || $status)
                                            <x-button
                                                label="Clear Filters"
                                                wire:click="resetFilters"
                                                color="secondary"
                                                size="sm"
                                            />
                                        @else
                                            <x-button
                                                label="Create First Enrollment"
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
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $enrollments->links() }}
        </div>

        <!-- Results summary -->
        @if($enrollments->count() > 0)
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-between sm:items-center">
                <span>
                    Showing {{ $enrollments->firstItem() ?? 0 }} to {{ $enrollments->lastItem() ?? 0 }}
                    of {{ $enrollments->total() }} enrollments
                    @if($search || $subject || $academicYear || $status)
                        (filtered from total)
                    @endif
                </span>
                <!-- Mobile view indicator -->
                <div class="flex items-center gap-2 lg:hidden">
                    <span class="text-xs text-gray-500">Card view active</span>
                    <x-icon name="o-user-group" class="w-4 h-4 text-gray-400" />
                </div>
            </div>
        </div>
        @endif
    </x-card>

    <!-- Delete confirmation modal -->
    <x-modal wire:model="showDeleteModal" title="Delete Confirmation">
        <div class="p-4">
            <div class="flex items-center gap-4">
                <div class="p-3 rounded-full bg-error/20">
                    <x-icon name="o-exclamation-triangle" class="w-8 h-8 text-error" />
                </div>
                <div>
                    <h3 class="text-lg font-semibold">Are you sure you want to delete this enrollment?</h3>
                    <p class="text-gray-600">This action is irreversible. The student will be unenrolled from the subject.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancelDelete" />
            <x-button label="Delete" icon="o-trash" wire:click="deleteEnrollment" color="error" />
        </x-slot:actions>
    </x-modal>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search by student or subject"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by subject"
                    placeholder="All subjects"
                    :options="$subjects"
                    wire:model.live="subject"
                    option-label="name"
                    option-value="id"
                    empty-message="No subjects found"
                />
            </div>

            <div>
                <x-select
                    label="Filter by academic year"
                    placeholder="All academic years"
                    :options="$academicYears"
                    wire:model.live="academicYear"
                    option-label="name"
                    option-value="id"
                    empty-message="No academic years found"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[
                        ['id' => 10, 'name' => '10 per page'],
                        ['id' => 25, 'name' => '25 per page'],
                        ['id' => 50, 'name' => '50 per page'],
                        ['id' => 100, 'name' => '100 per page']
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
