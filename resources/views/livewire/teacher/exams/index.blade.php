<?php

use App\Models\Exam;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('My Exams')] class extends Component {
    use WithPagination;
    use Toast;

    // Current teacher profile
    public ?TeacherProfile $teacherProfile = null;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $subjectFilter = '';

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $academicYearFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'exam_date', 'direction' => 'desc'];

    // Stats
    public array $stats = [];

    // Filter options
    public array $subjectOptions = [];
    public array $typeOptions = [];
    public array $statusOptions = [];
    public array $academicYearOptions = [];

    public function mount(): void
    {
        $this->teacherProfile = Auth::user()->teacherProfile;

        if (!$this->teacherProfile) {
            $this->error('Teacher profile not found. Please complete your profile first.');
            $this->redirect(route('teacher.profile.edit'));
            return;
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed teacher exams page',
            Exam::class,
            null,
            ['ip' => request()->ip()]
        );

        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        try {
            // Subject options from teacher's subjects
            $subjects = $this->teacherProfile->subjects()->orderBy('name')->get();
            $this->subjectOptions = [
                ['id' => '', 'name' => 'All Subjects'],
                ...$subjects->map(fn($subject) => [
                    'id' => $subject->id,
                    'name' => "{$subject->name} ({$subject->code})"
                ])->toArray()
            ];

            // Exam type options
            $this->typeOptions = [
                ['id' => '', 'name' => 'All Types'],
                ['id' => 'quiz', 'name' => 'Quiz'],
                ['id' => 'midterm', 'name' => 'Midterm'],
                ['id' => 'final', 'name' => 'Final'],
                ['id' => 'assignment', 'name' => 'Assignment'],
                ['id' => 'project', 'name' => 'Project'],
                ['id' => 'practical', 'name' => 'Practical'],
            ];

            // Status options based on exam date
            $this->statusOptions = [
                ['id' => '', 'name' => 'All Exams'],
                ['id' => 'upcoming', 'name' => 'Upcoming'],
                ['id' => 'completed', 'name' => 'Completed'],
                ['id' => 'graded', 'name' => 'Graded'],
                ['id' => 'pending_results', 'name' => 'Pending Results'],
            ];

            // Academic year options
            $academicYears = AcademicYear::orderBy('start_date', 'desc')->get();
            $this->academicYearOptions = [
                ['id' => '', 'name' => 'All Academic Years'],
                ...$academicYears->map(fn($year) => [
                    'id' => $year->id,
                    'name' => $year->name
                ])->toArray()
            ];

        } catch (\Exception $e) {
            $this->subjectOptions = [['id' => '', 'name' => 'All Subjects']];
            $this->typeOptions = [['id' => '', 'name' => 'All Types']];
            $this->statusOptions = [['id' => '', 'name' => 'All Exams']];
            $this->academicYearOptions = [['id' => '', 'name' => 'All Academic Years']];
        }
    }

    protected function loadStats(): void
    {
        try {
            if (!$this->teacherProfile) {
                $this->stats = [
                    'total_exams' => 0,
                    'upcoming_exams' => 0,
                    'completed_exams' => 0,
                    'graded_exams' => 0,
                    'pending_results' => 0,
                    'this_month_exams' => 0,
                ];
                return;
            }

            $now = now();
            $monthStart = $now->copy()->startOfMonth();
            $monthEnd = $now->copy()->endOfMonth();

            $totalExams = Exam::where('teacher_profile_id', $this->teacherProfile->id)->count();

            $upcomingExams = Exam::where('teacher_profile_id', $this->teacherProfile->id)
                ->where('exam_date', '>', $now)
                ->count();

            $completedExams = Exam::where('teacher_profile_id', $this->teacherProfile->id)
                ->where('exam_date', '<=', $now)
                ->count();

            $gradedExams = Exam::where('teacher_profile_id', $this->teacherProfile->id)
                ->whereHas('examResults')
                ->count();

            $pendingResults = Exam::where('teacher_profile_id', $this->teacherProfile->id)
                ->where('exam_date', '<=', $now)
                ->whereDoesntHave('examResults')
                ->count();

            $thisMonthExams = Exam::where('teacher_profile_id', $this->teacherProfile->id)
                ->whereBetween('exam_date', [$monthStart, $monthEnd])
                ->count();

            $this->stats = [
                'total_exams' => $totalExams,
                'upcoming_exams' => $upcomingExams,
                'completed_exams' => $completedExams,
                'graded_exams' => $gradedExams,
                'pending_results' => $pendingResults,
                'this_month_exams' => $thisMonthExams,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_exams' => 0,
                'upcoming_exams' => 0,
                'completed_exams' => 0,
                'graded_exams' => 0,
                'pending_results' => 0,
                'this_month_exams' => 0,
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
        $this->redirect(route('teacher.exams.create'));
    }

    public function redirectToShow(int $examId): void
    {
        $this->redirect(route('teacher.exams.show', $examId));
    }

    public function redirectToEdit(int $examId): void
    {
        $this->redirect(route('teacher.exams.edit', $examId));
    }

    public function redirectToResults(int $examId): void
    {
        $this->redirect(route('teacher.exams.results', $examId));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSubjectFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
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

    public function clearFilters(): void
    {
        $this->search = '';
        $this->subjectFilter = '';
        $this->typeFilter = '';
        $this->statusFilter = '';
        $this->academicYearFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated exams
    public function exams(): LengthAwarePaginator
    {
        if (!$this->teacherProfile) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        $query = Exam::query()
            ->with(['subject', 'academicYear', 'examResults'])
            ->where('teacher_profile_id', $this->teacherProfile->id);

        // Apply filters
        if ($this->search) {
            $query->where(function (Builder $q) {
                $q->where('title', 'like', "%{$this->search}%")
                  ->orWhereHas('subject', function ($subQuery) {
                      $subQuery->where('name', 'like', "%{$this->search}%")
                               ->orWhere('code', 'like', "%{$this->search}%");
                  });
            });
        }

        if ($this->subjectFilter) {
            $query->where('subject_id', $this->subjectFilter);
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        if ($this->academicYearFilter) {
            $query->where('academic_year_id', $this->academicYearFilter);
        }

        if ($this->statusFilter) {
            $now = now();
            switch ($this->statusFilter) {
                case 'upcoming':
                    $query->where('exam_date', '>', $now);
                    break;
                case 'completed':
                    $query->where('exam_date', '<=', $now);
                    break;
                case 'graded':
                    $query->whereHas('examResults');
                    break;
                case 'pending_results':
                    $query->where('exam_date', '<=', $now)
                          ->whereDoesntHave('examResults');
                    break;
            }
        }

        return $query->orderBy($this->sortBy['column'], $this->sortBy['direction'])
                     ->paginate($this->perPage);
    }

    // Get exam status
    public function getExamStatus(Exam $exam): array
    {
        $now = now();

        if ($exam->exam_date > $now) {
            return ['status' => 'upcoming', 'color' => 'bg-blue-100 text-blue-800', 'text' => 'Upcoming'];
        } elseif ($exam->examResults->count() > 0) {
            return ['status' => 'graded', 'color' => 'bg-green-100 text-green-800', 'text' => 'Graded'];
        } else {
            return ['status' => 'pending', 'color' => 'bg-yellow-100 text-yellow-800', 'text' => 'Pending Results'];
        }
    }

    // Get exam type color
    public function getExamTypeColor(string $type): string
    {
        return match(strtolower($type)) {
            'quiz' => 'bg-green-100 text-green-800',
            'midterm' => 'bg-yellow-100 text-yellow-800',
            'final' => 'bg-red-100 text-red-800',
            'assignment' => 'bg-blue-100 text-blue-800',
            'project' => 'bg-purple-100 text-purple-800',
            'practical' => 'bg-orange-100 text-orange-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function with(): array
    {
        return [
            'exams' => $this->exams(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="My Exams" subtitle="Manage exams and assessments" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search exams..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$subjectFilter, $typeFilter, $statusFilter, $academicYearFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="Create Exam"
                icon="o-plus"
                wire:click="redirectToCreate"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-6">
        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-blue-100 rounded-full">
                        <x-icon name="o-document-text" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_exams']) }}</div>
                        <div class="text-sm text-gray-500">Total Exams</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-orange-100 rounded-full">
                        <x-icon name="o-calendar-days" class="w-8 h-8 text-orange-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['upcoming_exams']) }}</div>
                        <div class="text-sm text-gray-500">Upcoming</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-gray-100 rounded-full">
                        <x-icon name="o-check-circle" class="w-8 h-8 text-gray-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-gray-600">{{ number_format($stats['completed_exams']) }}</div>
                        <div class="text-sm text-gray-500">Completed</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-green-100 rounded-full">
                        <x-icon name="o-chart-bar" class="w-8 h-8 text-green-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['graded_exams']) }}</div>
                        <div class="text-sm text-gray-500">Graded</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-yellow-100 rounded-full">
                        <x-icon name="o-clock" class="w-8 h-8 text-yellow-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-yellow-600">{{ number_format($stats['pending_results']) }}</div>
                        <div class="text-sm text-gray-500">Pending</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-calendar" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['this_month_exams']) }}</div>
                        <div class="text-sm text-gray-500">This Month</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Exams Table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('title')">
                            <div class="flex items-center">
                                Exam Title
                                @if ($sortBy['column'] === 'title')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('subject_id')">
                            <div class="flex items-center">
                                Subject
                                @if ($sortBy['column'] === 'subject_id')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Type</th>
                        <th class="cursor-pointer" wire:click="sortBy('exam_date')">
                            <div class="flex items-center">
                                Exam Date
                                @if ($sortBy['column'] === 'exam_date')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Status</th>
                        <th>Results</th>
                        <th>Academic Year</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($exams as $exam)
                        @php
                            $examStatus = $this->getExamStatus($exam);
                        @endphp
                        <tr class="hover">
                            <td>
                                <div>
                                    <button
                                        wire:click="redirectToShow({{ $exam->id }})"
                                        class="font-semibold text-blue-600 hover:text-blue-800 hover:underline"
                                    >
                                        {{ $exam->title }}
                                    </button>
                                    <div class="text-xs text-gray-500">ID: #{{ $exam->id }}</div>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <div class="font-medium">{{ $exam->subject->name }}</div>
                                    <div class="text-sm text-gray-500">{{ $exam->subject->code }}</div>
                                </div>
                            </td>
                            <td>
                                @if($exam->type)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getExamTypeColor($exam->type) }}">
                                        {{ ucfirst($exam->type) }}
                                    </span>
                                @else
                                    <span class="text-gray-500">-</span>
                                @endif
                            </td>
                            <td>
                                <div>
                                    <div class="font-medium">{{ $exam->exam_date->format('M d, Y') }}</div>
                                    <div class="text-sm text-gray-500">{{ $exam->exam_date->diffForHumans() }}</div>
                                </div>
                            </td>
                            <td>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $examStatus['color'] }}">
                                    {{ $examStatus['text'] }}
                                </span>
                            </td>
                            <td>
                                @if($exam->examResults->count() > 0)
                                    <div class="text-sm">
                                        <div class="font-medium text-green-600">{{ $exam->examResults->count() }} results</div>
                                        <div class="text-xs text-gray-500">
                                            Avg: {{ $exam->examResults->avg('score') ? round($exam->examResults->avg('score'), 1) : 'N/A' }}
                                        </div>
                                    </div>
                                @else
                                    <span class="text-sm text-gray-500">No results</span>
                                @endif
                            </td>
                            <td>
                                <div class="text-sm">{{ $exam->academicYear ? $exam->academicYear->name : 'N/A' }}</div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <button
                                        wire:click="redirectToShow({{ $exam->id }})"
                                        class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                        title="View"
                                    >
                                        👁️
                                    </button>
                                    <button
                                        wire:click="redirectToEdit({{ $exam->id }})"
                                        class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                        title="Edit"
                                    >
                                        ✏️
                                    </button>
                                    @if($examStatus['status'] === 'pending' || $examStatus['status'] === 'graded')
                                        <button
                                            wire:click="redirectToResults({{ $exam->id }})"
                                            class="p-2 text-green-600 bg-green-100 rounded-md hover:text-green-900 hover:bg-green-200"
                                            title="Manage Results"
                                        >
                                            📊
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-document-text" class="w-20 h-20 text-gray-300" />
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-600">No exams found</h3>
                                        <p class="mt-1 text-gray-500">
                                            @if($search || $subjectFilter || $typeFilter || $statusFilter || $academicYearFilter)
                                                No exams match your current filters.
                                            @else
                                                You haven't created any exams yet.
                                            @endif
                                        </p>
                                    </div>
                                    @if($search || $subjectFilter || $typeFilter || $statusFilter || $academicYearFilter)
                                        <x-button
                                            label="Clear Filters"
                                            wire:click="clearFilters"
                                            color="secondary"
                                            size="sm"
                                        />
                                    @else
                                        <x-button
                                            label="Create First Exam"
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
            {{ $exams->links() }}
        </div>

        <!-- Results summary -->
        @if($exams->count() > 0)
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $exams->firstItem() ?? 0 }} to {{ $exams->lastItem() ?? 0 }}
            of {{ $exams->total() }} exams
            @if($search || $subjectFilter || $typeFilter || $statusFilter || $academicYearFilter)
                (filtered from total)
            @endif
        </div>
        @endif
    </x-card>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Filter Exams" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search exams"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by title or subject..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by subject"
                    :options="$subjectOptions"
                    wire:model.live="subjectFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All subjects"
                />
            </div>

            <div>
                <x-select
                    label="Filter by type"
                    :options="$typeOptions"
                    wire:model.live="typeFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All types"
                />
            </div>

            <div>
                <x-select
                    label="Filter by status"
                    :options="$statusOptions"
                    wire:model.live="statusFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All exams"
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
            <x-button label="Reset" icon="o-x-mark" wire:click="clearFilters" />
            <x-button label="Apply" icon="o-check" wire:click="$set('showFilters', false)" color="primary" />
        </x-slot:actions>
    </x-drawer>
</div>
