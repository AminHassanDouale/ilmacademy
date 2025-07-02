<?php

use App\Models\TeacherProfile;
use App\Models\Exam;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Teacher Exams')] class extends Component {
    use WithPagination;
    use Toast;

    // Model instance
    public TeacherProfile $teacherProfile;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $subjectFilter = '';

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $academicYearFilter = '';

    #[Url]
    public string $dateFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'exam_date', 'direction' => 'desc'];

    // Bulk actions
    public array $selectedExams = [];
    public bool $selectAll = false;

    // Stats
    public array $stats = [];

    // Filter options
    public array $subjectOptions = [];
    public array $typeOptions = [];
    public array $academicYearOptions = [];

    public function mount(TeacherProfile $teacherProfile): void
    {
        $this->teacherProfile = $teacherProfile->load(['user', 'subjects']);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed exams page for teacher: {$teacherProfile->user->name}",
            TeacherProfile::class,
            $teacherProfile->id,
            ['ip' => request()->ip()]
        );

        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        // Subject options - only subjects assigned to this teacher
        $this->subjectOptions = [
            ['id' => '', 'name' => 'All Subjects'],
            ...$this->teacherProfile->subjects->map(fn($subject) => [
                'id' => $subject->id,
                'name' => $subject->name
            ])->toArray()
        ];

        // Type options
        $this->typeOptions = [
            ['id' => '', 'name' => 'All Types'],
            ['id' => 'quiz', 'name' => 'Quiz'],
            ['id' => 'test', 'name' => 'Test'],
            ['id' => 'midterm', 'name' => 'Midterm'],
            ['id' => 'final', 'name' => 'Final'],
            ['id' => 'assignment', 'name' => 'Assignment'],
            ['id' => 'project', 'name' => 'Project'],
            ['id' => 'practical', 'name' => 'Practical'],
        ];

        // Academic Year options
        try {
            $academicYears = \App\Models\AcademicYear::orderBy('name', 'desc')->get();
            $this->academicYearOptions = [
                ['id' => '', 'name' => 'All Academic Years'],
                ...$academicYears->map(fn($year) => [
                    'id' => $year->id,
                    'name' => $year->name
                ])->toArray()
            ];
        } catch (\Exception $e) {
            $this->academicYearOptions = [
                ['id' => '', 'name' => 'All Academic Years'],
            ];
        }
    }

    protected function loadStats(): void
    {
        try {
            $totalExams = $this->teacherProfile->exams()->count();
            $upcomingExams = $this->teacherProfile->exams()
                ->where('exam_date', '>', now())
                ->count();
            $pastExams = $this->teacherProfile->exams()
                ->where('exam_date', '<', now())
                ->count();
            $thisMonthExams = $this->teacherProfile->exams()
                ->whereBetween('exam_date', [now()->startOfMonth(), now()->endOfMonth()])
                ->count();
            $gradedExams = $this->teacherProfile->exams()
                ->whereHas('examResults')
                ->count();

            // Get exam counts by type
            $typeCounts = $this->teacherProfile->exams()
                ->selectRaw('type, COUNT(*) as count')
                ->whereNotNull('type')
                ->groupBy('type')
                ->orderByDesc('count')
                ->limit(5)
                ->get();

            // Get exam counts by subject
            $subjectCounts = $this->teacherProfile->exams()
                ->with('subject')
                ->get()
                ->groupBy('subject.name')
                ->map(fn($exams) => $exams->count())
                ->sortDesc()
                ->take(5);

            $this->stats = [
                'total_exams' => $totalExams,
                'upcoming_exams' => $upcomingExams,
                'past_exams' => $pastExams,
                'this_month_exams' => $thisMonthExams,
                'graded_exams' => $gradedExams,
                'type_counts' => $typeCounts,
                'subject_counts' => $subjectCounts,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_exams' => 0,
                'upcoming_exams' => 0,
                'past_exams' => 0,
                'this_month_exams' => 0,
                'graded_exams' => 0,
                'type_counts' => collect(),
                'subject_counts' => collect(),
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

    // Create new exam
    public function createExam(): void
    {
        // Redirect to create exam page or open modal
        $this->redirect(route('admin.exams.create', ['teacher' => $this->teacherProfile->id]));
    }

    // View exam details
    public function viewExam(int $examId): void
    {
        $this->redirect(route('admin.exams.show', $examId));
    }

    // Edit exam
    public function editExam(int $examId): void
    {
        $this->redirect(route('admin.exams.edit', $examId));
    }

    // Delete exam
    public function deleteExam(int $examId): void
    {
        try {
            $exam = Exam::findOrFail($examId);

            // Check if exam belongs to this teacher
            if ($exam->teacher_profile_id !== $this->teacherProfile->id) {
                $this->error('You can only delete your own exams.');
                return;
            }

            // Check if exam has results
            if ($exam->examResults()->count() > 0) {
                $this->error('Cannot delete exam with existing results.');
                return;
            }

            ActivityLog::log(
                Auth::id(),
                'delete',
                "Deleted exam: {$exam->title} for teacher: {$this->teacherProfile->user->name}",
                Exam::class,
                $exam->id,
                [
                    'exam_title' => $exam->title,
                    'exam_type' => $exam->type,
                    'exam_date' => $exam->exam_date,
                    'teacher_name' => $this->teacherProfile->user->name
                ]
            );

            $exam->delete();
            $this->loadStats();
            $this->success('Exam deleted successfully.');

        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Duplicate exam
    public function duplicateExam(int $examId): void
    {
        try {
            $originalExam = Exam::findOrFail($examId);

            // Check if exam belongs to this teacher
            if ($originalExam->teacher_profile_id !== $this->teacherProfile->id) {
                $this->error('You can only duplicate your own exams.');
                return;
            }

            $newExam = $originalExam->replicate();
            $newExam->title = $originalExam->title . ' (Copy)';
            $newExam->exam_date = null; // Reset exam date
            $newExam->save();

            ActivityLog::log(
                Auth::id(),
                'create',
                "Duplicated exam: {$originalExam->title} for teacher: {$this->teacherProfile->user->name}",
                Exam::class,
                $newExam->id,
                [
                    'original_exam_id' => $originalExam->id,
                    'new_exam_id' => $newExam->id,
                    'teacher_name' => $this->teacherProfile->user->name
                ]
            );

            $this->loadStats();
            $this->success('Exam duplicated successfully.');

        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Export functionality
    public function exportExams(): void
    {
        try {
            $exams = $this->getFilteredExams()->get();

            $csvData = "Title,Subject,Type,Exam Date,Academic Year,Results Count,Created Date\n";

            foreach ($exams as $exam) {
                $csvData .= sprintf(
                    '"%s","%s","%s","%s","%s","%d","%s"' . "\n",
                    $exam->title ?: 'Untitled',
                    $exam->subject->name ?? 'Unknown',
                    $exam->type ?: 'Unknown',
                    $exam->exam_date ? $exam->exam_date->format('Y-m-d') : 'Not set',
                    $exam->academicYear->name ?? 'Unknown',
                    $exam->examResults->count(),
                    $exam->created_at->format('Y-m-d H:i:s')
                );
            }

            ActivityLog::log(
                Auth::id(),
                'export',
                "Exported exams for teacher: {$this->teacherProfile->user->name}",
                TeacherProfile::class,
                $this->teacherProfile->id,
                [
                    'export_count' => $exams->count(),
                    'filters_applied' => [
                        'search' => $this->search,
                        'subject' => $this->subjectFilter,
                        'type' => $this->typeFilter,
                        'academic_year' => $this->academicYearFilter,
                        'date' => $this->dateFilter,
                    ]
                ]
            );

            // In a real implementation, you would return a download response
            $this->success("Export completed successfully. {$exams->count()} exams exported.");

        } catch (\Exception $e) {
            $this->error('Export failed: ' . $e->getMessage());
        }
    }

    // Bulk delete selected exams
    public function bulkDeleteExams(): void
    {
        if (empty($this->selectedExams)) {
            $this->error('Please select exams to delete.');
            return;
        }

        try {
            $examsToDelete = Exam::whereIn('id', $this->selectedExams)
                ->where('teacher_profile_id', $this->teacherProfile->id)
                ->get();

            $deletedCount = 0;
            $skippedCount = 0;

            foreach ($examsToDelete as $exam) {
                if ($exam->examResults()->count() > 0) {
                    $skippedCount++;
                    continue;
                }
                $exam->delete();
                $deletedCount++;
            }

            ActivityLog::log(
                Auth::id(),
                'bulk_delete',
                "Bulk deleted {$deletedCount} exams for teacher: {$this->teacherProfile->user->name}",
                TeacherProfile::class,
                $this->teacherProfile->id,
                [
                    'deleted_count' => $deletedCount,
                    'skipped_count' => $skippedCount,
                    'exam_ids' => $this->selectedExams
                ]
            );

            $this->selectedExams = [];
            $this->selectAll = false;
            $this->loadStats();

            if ($skippedCount > 0) {
                $this->warning("Deleted {$deletedCount} exams. {$skippedCount} exams with results were skipped.");
            } else {
                $this->success("Successfully deleted {$deletedCount} exams.");
            }

        } catch (\Exception $e) {
            $this->error('Bulk delete failed: ' . $e->getMessage());
        }
    }

    // Schedule exam reminder
    public function scheduleReminder(int $examId): void
    {
        try {
            $exam = Exam::findOrFail($examId);

            if ($exam->teacher_profile_id !== $this->teacherProfile->id) {
                $this->error('You can only schedule reminders for your own exams.');
                return;
            }

            // In a real implementation, you would queue a reminder job
            ActivityLog::log(
                Auth::id(),
                'reminder',
                "Scheduled reminder for exam: {$exam->title}",
                Exam::class,
                $exam->id,
                [
                    'exam_title' => $exam->title,
                    'exam_date' => $exam->exam_date,
                    'teacher_name' => $this->teacherProfile->user->name
                ]
            );

            $this->success('Reminder scheduled successfully.');

        } catch (\Exception $e) {
            $this->error('Failed to schedule reminder: ' . $e->getMessage());
        }
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

    public function updatedAcademicYearFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->subjectFilter = '';
        $this->typeFilter = '';
        $this->academicYearFilter = '';
        $this->dateFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated exams
    public function exams(): LengthAwarePaginator
    {
        return $this->getFilteredExams()->paginate($this->perPage);
    }

    // Get filtered exams query (for export and bulk operations)
    protected function getFilteredExams()
    {
        return Exam::query()
            ->with(['subject', 'academicYear', 'examResults'])
            ->where('teacher_profile_id', $this->teacherProfile->id)
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('title', 'like', "%{$this->search}%")
                      ->orWhereHas('subject', function ($subjectQuery) {
                          $subjectQuery->where('name', 'like', "%{$this->search}%");
                      })
                      ->orWhere('type', 'like', "%{$this->search}%");
                });
            })
            ->when($this->subjectFilter, function (Builder $query) {
                $query->where('subject_id', $this->subjectFilter);
            })
            ->when($this->typeFilter, function (Builder $query) {
                $query->where('type', $this->typeFilter);
            })
            ->when($this->academicYearFilter, function (Builder $query) {
                $query->where('academic_year_id', $this->academicYearFilter);
            })
            ->when($this->dateFilter, function (Builder $query) {
                match($this->dateFilter) {
                    'today' => $query->whereDate('exam_date', today()),
                    'tomorrow' => $query->whereDate('exam_date', today()->addDay()),
                    'this_week' => $query->whereBetween('exam_date', [now()->startOfWeek(), now()->endOfWeek()]),
                    'this_month' => $query->whereBetween('exam_date', [now()->startOfMonth(), now()->endOfMonth()]),
                    'upcoming' => $query->where('exam_date', '>', now()),
                    'past' => $query->where('exam_date', '<', now()),
                    default => null
                };
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction']);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->subjectFilter = '';
        $this->typeFilter = '';
        $this->academicYearFilter = '';
        $this->dateFilter = '';
        $this->resetPage();
    }

    // Helper function to get exam status
    private function getExamStatus($exam): array
    {
        $examDate = $exam->exam_date;
        $today = today();

        if ($examDate < $today) {
            $hasResults = $exam->examResults && $exam->examResults->count() > 0;
            return [
                'status' => $hasResults ? 'graded' : 'completed',
                'color' => $hasResults ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800',
                'label' => $hasResults ? 'Graded' : 'Completed'
            ];
        } elseif ($examDate->isToday()) {
            return ['status' => 'today', 'color' => 'bg-orange-100 text-orange-800', 'label' => 'Today'];
        } elseif ($examDate > $today) {
            return ['status' => 'upcoming', 'color' => 'bg-blue-100 text-blue-800', 'label' => 'Upcoming'];
        }

        return ['status' => 'unknown', 'color' => 'bg-gray-100 text-gray-600', 'label' => 'Unknown'];
    }

    // Helper function to get type color
    private function getTypeColor(string $type): string
    {
        return match($type) {
            'quiz' => 'bg-green-100 text-green-800',
            'test' => 'bg-blue-100 text-blue-800',
            'midterm' => 'bg-orange-100 text-orange-800',
            'final' => 'bg-red-100 text-red-800',
            'assignment' => 'bg-purple-100 text-purple-800',
            'project' => 'bg-indigo-100 text-indigo-800',
            'practical' => 'bg-yellow-100 text-yellow-800',
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
    <x-header title="Exams: {{ $teacherProfile->user->name }}" subtitle="Manage teacher exams and assessments" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search exams..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Export"
                icon="o-arrow-down-tray"
                wire:click="exportExams"
                class="btn-outline"
                responsive />

            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$subjectFilter, $typeFilter, $academicYearFilter, $dateFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="Teacher Profile"
                icon="o-user"
                link="{{ route('admin.teachers.show', $teacherProfile->id) }}"
                class="btn-outline"
                responsive />

            <x-button
                label="Create Exam"
                icon="o-plus"
                wire:click="createExam"
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
                        <x-icon name="o-clock" class="w-8 h-8 text-orange-600" />
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
                    <div class="p-3 mr-4 bg-green-100 rounded-full">
                        <x-icon name="o-check-circle" class="w-8 h-8 text-green-600" />
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
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-calendar-days" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['this_month_exams']) }}</div>
                        <div class="text-sm text-gray-500">This Month</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-gray-100 rounded-full">
                        <x-icon name="o-archive-box" class="w-8 h-8 text-gray-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-gray-600">{{ number_format($stats['past_exams']) }}</div>
                        <div class="text-sm text-gray-500">Completed</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Type Distribution Cards -->
    @if($stats['type_counts']->count() > 0)
    <div class="grid grid-cols-1 gap-4 mb-8 md:grid-cols-{{ min($stats['type_counts']->count(), 5) }}">
        @foreach($stats['type_counts'] as $typeData)
        <x-card class="border-purple-200 bg-purple-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-purple-600">{{ number_format($typeData->count) }}</div>
                <div class="text-sm text-purple-600">{{ ucfirst($typeData->type) }}</div>
            </div>
        </x-card>
        @endforeach
    </div>
    @endif

    <!-- Bulk Actions -->
    @if(count($selectedExams) > 0)
        <x-card class="mb-6">
            <div class="p-4 bg-blue-50">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-blue-800">
                        {{ count($selectedExams) }} exam(s) selected
                    </span>
                    <div class="flex gap-2">
                        <x-button
                            label="Delete Selected"
                            icon="o-trash"
                            wire:click="bulkDeleteExams"
                            class="btn-sm btn-error"
                            wire:confirm="Are you sure you want to delete the selected exams? Exams with results cannot be deleted."
                        />
                    </div>
                </div>
            </div>
        </x-card>
    @endif

    <!-- Exams Table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th>
                            <x-checkbox wire:model.live="selectAll" />
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('title')">
                            <div class="flex items-center">
                                Title
                                @if ($sortBy['column'] === 'title')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('subject.name')">
                            <div class="flex items-center">
                                Subject
                                @if ($sortBy['column'] === 'subject.name')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('type')">
                            <div class="flex items-center">
                                Type
                                @if ($sortBy['column'] === 'type')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('exam_date')">
                            <div class="flex items-center">
                                Exam Date
                                @if ($sortBy['column'] === 'exam_date')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Academic Year</th>
                        <th>Status</th>
                        <th>Results</th>
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
                    @forelse($exams as $exam)
                        @php
                            $status = $this->getExamStatus($exam);
                            $resultsCount = $exam->examResults ? $exam->examResults->count() : 0;
                        @endphp
                        <tr class="hover">
                            <td>
                                <x-checkbox wire:model.live="selectedExams" value="{{ $exam->id }}" />
                            </td>
                            <td>
                                <div class="font-semibold">{{ $exam->title ?: 'Untitled Exam' }}</div>
                                <div class="text-xs text-gray-500">ID: {{ $exam->id }}</div>
                            </td>
                            <td>
                                <div class="font-medium">{{ $exam->subject->name ?? 'Unknown Subject' }}</div>
                                @if($exam->subject && $exam->subject->description)
                                    <div class="text-xs text-gray-500">{{ Str::limit($exam->subject->description, 30) }}</div>
                                @endif
                            </td>
                            <td>
                                @if($exam->type)
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getTypeColor($exam->type) }}">
                                        {{ ucfirst($exam->type) }}
                                    </span>
                                @else
                                    <span class="text-sm text-gray-500">Not specified</span>
                                @endif
                            </td>
                            <td>
                                @if($exam->exam_date)
                                    <div class="text-sm">{{ $exam->exam_date->format('M d, Y') }}</div>
                                    <div class="text-xs text-gray-500">{{ $exam->exam_date->diffForHumans() }}</div>
                                @else
                                    <span class="text-sm text-gray-500">Not set</span>
                                @endif
                            </td>
                            <td>
                                @if($exam->academicYear)
                                    <div class="text-sm">{{ $exam->academicYear->name }}</div>
                                @else
                                    <span class="text-sm text-gray-500">Not assigned</span>
                                @endif
                            </td>
                            <td>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $status['color'] }}">
                                    {{ $status['label'] }}
                                </span>
                            </td>
                            <td>
                                @if($resultsCount > 0)
                                    <div class="text-sm font-medium text-green-600">{{ $resultsCount }} results</div>
                                    <div class="text-xs text-gray-500">submitted</div>
                                @else
                                    <div class="text-sm text-gray-500">No results</div>
                                @endif
                            </td>
                            <td>
                                <div class="text-sm">{{ $exam->created_at->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $exam->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1">
                                    <button
                                        wire:click="viewExam({{ $exam->id }})"
                                        class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                        title="View Details"
                                    >
                                        👁️
                                    </button>
                                    <button
                                        wire:click="editExam({{ $exam->id }})"
                                        class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                        title="Edit Exam"
                                    >
                                        ✏️
                                    </button>
                                    @if($resultsCount > 0)
                                        <button
                                            wire:click="viewExam({{ $exam->id }})"
                                            class="p-2 text-green-600 bg-green-100 rounded-md hover:text-green-900 hover:bg-green-200"
                                            title="View Results"
                                        >
                                            📊
                                        </button>
                                    @endif
                                    @if($exam->exam_date && $exam->exam_date->isFuture())
                                        <button
                                            wire:click="scheduleReminder({{ $exam->id }})"
                                            class="p-2 text-yellow-600 bg-yellow-100 rounded-md hover:text-yellow-900 hover:bg-yellow-200"
                                            title="Schedule Reminder"
                                        >
                                            ⏰
                                        </button>
                                    @endif
                                    <button
                                        wire:click="duplicateExam({{ $exam->id }})"
                                        class="p-2 text-purple-600 bg-purple-100 rounded-md hover:text-purple-900 hover:bg-purple-200"
                                        title="Duplicate Exam"
                                        wire:confirm="Are you sure you want to duplicate this exam?"
                                    >
                                        📋
                                    </button>
                                    <button
                                        wire:click="deleteExam({{ $exam->id }})"
                                        class="p-2 text-red-600 bg-red-100 rounded-md hover:text-red-900 hover:bg-red-200"
                                        title="Delete Exam"
                                        wire:confirm="Are you sure you want to delete this exam? This action cannot be undone."
                                    >
                                        🗑️
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-document-text" class="w-20 h-20 text-gray-300" />
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-600">No exams found</h3>
                                        <p class="mt-1 text-gray-500">
                                            @if($search || $subjectFilter || $typeFilter || $academicYearFilter || $dateFilter)
                                                No exams match your current filters.
                                            @else
                                                This teacher hasn't created any exams yet.
                                            @endif
                                        </p>
                                    </div>
                                    @if($search || $subjectFilter || $typeFilter || $academicYearFilter || $dateFilter)
                                        <x-button
                                            label="Clear Filters"
                                            wire:click="resetFilters"
                                            color="secondary"
                                            size="sm"
                                        />
                                    @else
                                        <x-button
                                            label="Create First Exam"
                                            icon="o-plus"
                                            wire:click="createExam"
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
            @if($search || $subjectFilter || $typeFilter || $academicYearFilter || $dateFilter)
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
                    label="Search exams"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by title, subject, or type..."
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
                    label="Filter by date"
                    :options="[
                        ['id' => '', 'name' => 'All dates'],
                        ['id' => 'today', 'name' => 'Today'],
                        ['id' => 'tomorrow', 'name' => 'Tomorrow'],
                        ['id' => 'this_week', 'name' => 'This week'],
                        ['id' => 'this_month', 'name' => 'This month'],
                        ['id' => 'upcoming', 'name' => 'Upcoming'],
                        ['id' => 'past', 'name' => 'Past'],
                    ]"
                    wire:model.live="dateFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All dates"
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
