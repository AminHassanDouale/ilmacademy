<?php

use App\Models\User;
use App\Models\ChildProfile;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Subject;
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

    // Current user
    public User $user;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $subjectFilter = '';

    #[Url]
    public string $typeFilter = '';

    #[Url]
    public string $academicYearFilter = '';

    #[Url]
    public string $dateFilter = '';

    #[Url]
    public int $perPage = 12;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'exam_date', 'direction' => 'desc'];

    // View mode
    #[Url]
    public string $viewMode = 'grid'; // 'grid' or 'list'

    // Stats
    public array $stats = [];

    // Filter options
    public array $statusOptions = [];
    public array $subjectOptions = [];
    public array $typeOptions = [];
    public array $academicYearOptions = [];
    public array $dateOptions = [];

    // Child profiles for students with multiple children (if user is parent)
    public array $childProfiles = [];

    public function mount(): void
    {
        $this->user = Auth::user();

        // Log activity
        ActivityLog::log(
            $this->user->id,
            'access',
            'Accessed student exams page',
            Exam::class,
            null,
            ['ip' => request()->ip()]
        );

        $this->loadChildProfiles();
        $this->loadFilterOptions();
        $this->loadStats();
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

    protected function loadFilterOptions(): void
    {
        // Status options (based on exam date and results)
        $this->statusOptions = [
            ['id' => '', 'name' => 'All Exams'],
            ['id' => 'upcoming', 'name' => 'Upcoming'],
            ['id' => 'completed', 'name' => 'Completed'],
            ['id' => 'graded', 'name' => 'Graded'],
            ['id' => 'pending_results', 'name' => 'Pending Results'],
        ];

        // Exam type options
        $this->typeOptions = [
            ['id' => '', 'name' => 'All Types'],
            ['id' => 'midterm', 'name' => 'Midterm'],
            ['id' => 'final', 'name' => 'Final'],
            ['id' => 'quiz', 'name' => 'Quiz'],
            ['id' => 'assignment', 'name' => 'Assignment'],
            ['id' => 'project', 'name' => 'Project'],
            ['id' => 'practical', 'name' => 'Practical'],
        ];

        // Date filter options
        $this->dateOptions = [
            ['id' => '', 'name' => 'All Dates'],
            ['id' => 'today', 'name' => 'Today'],
            ['id' => 'tomorrow', 'name' => 'Tomorrow'],
            ['id' => 'this_week', 'name' => 'This Week'],
            ['id' => 'next_week', 'name' => 'Next Week'],
            ['id' => 'this_month', 'name' => 'This Month'],
            ['id' => 'past', 'name' => 'Past Exams'],
        ];

        try {
            // Get subjects from user's enrollments
            $subjects = Subject::whereHas('subjectEnrollments.programEnrollment.childProfile', function ($query) {
                if ($this->user->hasRole('student')) {
                    $query->where('user_id', $this->user->id);
                } else {
                    $query->where('parent_id', $this->user->id);
                }
            })->orderBy('name')->get();

            $this->subjectOptions = [
                ['id' => '', 'name' => 'All Subjects'],
                ...$subjects->map(fn($subject) => [
                    'id' => $subject->id,
                    'name' => $subject->name . ($subject->code ? " ({$subject->code})" : '')
                ])->toArray()
            ];

            // Get academic years with exams for this user
            $academicYears = AcademicYear::whereHas('exams.examResults.childProfile', function ($query) {
                if ($this->user->hasRole('student')) {
                    $query->where('user_id', $this->user->id);
                } else {
                    $query->where('parent_id', $this->user->id);
                }
            })->orderBy('name')->get();

            $this->academicYearOptions = [
                ['id' => '', 'name' => 'All Academic Years'],
                ...$academicYears->map(fn($year) => [
                    'id' => $year->id,
                    'name' => $year->name
                ])->toArray()
            ];

        } catch (\Exception $e) {
            $this->subjectOptions = [['id' => '', 'name' => 'All Subjects']];
            $this->academicYearOptions = [['id' => '', 'name' => 'All Academic Years']];
        }
    }

    protected function loadStats(): void
    {
        try {
            $baseQuery = $this->getBaseExamsQuery();

            $totalExams = (clone $baseQuery)->count();
            $upcomingExams = (clone $baseQuery)->where('exam_date', '>', now())->count();
            $completedExams = (clone $baseQuery)->where('exam_date', '<=', now())->count();

            // Count exams with results
            $gradedExams = (clone $baseQuery)
                ->whereHas('examResults', function ($query) {
                    $query->whereHas('childProfile', function ($childQuery) {
                        if ($this->user->hasRole('student')) {
                            $childQuery->where('user_id', $this->user->id);
                        } else {
                            $childQuery->where('parent_id', $this->user->id);
                        }
                    });
                })
                ->count();

            $pendingResults = $completedExams - $gradedExams;

            // Calculate average score
            $averageScore = 0;
            if ($gradedExams > 0) {
                $results = ExamResult::whereHas('childProfile', function ($query) {
                    if ($this->user->hasRole('student')) {
                        $query->where('user_id', $this->user->id);
                    } else {
                        $query->where('parent_id', $this->user->id);
                    }
                })->whereNotNull('score')->avg('score');

                $averageScore = round($results ?? 0, 1);
            }

            $this->stats = [
                'total_exams' => $totalExams,
                'upcoming_exams' => $upcomingExams,
                'completed_exams' => $completedExams,
                'graded_exams' => $gradedExams,
                'pending_results' => $pendingResults,
                'average_score' => $averageScore,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_exams' => 0,
                'upcoming_exams' => 0,
                'completed_exams' => 0,
                'graded_exams' => 0,
                'pending_results' => 0,
                'average_score' => 0,
            ];
        }
    }

    protected function getBaseExamsQuery(): Builder
    {
        return Exam::query()
            ->with(['subject', 'teacherProfile', 'academicYear'])
            ->whereHas('subject.subjectEnrollments.programEnrollment.childProfile', function ($query) {
                if ($this->user->hasRole('student')) {
                    $query->where('user_id', $this->user->id);
                } else {
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

    // Toggle view mode
    public function setViewMode(string $mode): void
    {
        $this->viewMode = $mode;
    }

    // Redirect to exam show page
    public function redirectToShow(int $examId): void
    {
        $this->redirect(route('student.exams.show', $examId));
    }

    // Filter update methods
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
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
        $this->statusFilter = '';
        $this->subjectFilter = '';
        $this->typeFilter = '';
        $this->academicYearFilter = '';
        $this->dateFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated exams
    public function exams(): LengthAwarePaginator
    {
        return $this->getBaseExamsQuery()
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('title', 'like', "%{$this->search}%")
                      ->orWhereHas('subject', function ($subjectQuery) {
                          $subjectQuery->where('name', 'like', "%{$this->search}%")
                                       ->orWhere('code', 'like', "%{$this->search}%");
                      });
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
            ->when($this->statusFilter, function (Builder $query) {
                switch ($this->statusFilter) {
                    case 'upcoming':
                        $query->where('exam_date', '>', now());
                        break;
                    case 'completed':
                        $query->where('exam_date', '<=', now());
                        break;
                    case 'graded':
                        $query->whereHas('examResults', function ($resultQuery) {
                            $resultQuery->whereHas('childProfile', function ($childQuery) {
                                if ($this->user->hasRole('student')) {
                                    $childQuery->where('user_id', $this->user->id);
                                } else {
                                    $childQuery->where('parent_id', $this->user->id);
                                }
                            });
                        });
                        break;
                    case 'pending_results':
                        $query->where('exam_date', '<=', now())
                              ->whereDoesntHave('examResults', function ($resultQuery) {
                                  $resultQuery->whereHas('childProfile', function ($childQuery) {
                                      if ($this->user->hasRole('student')) {
                                          $childQuery->where('user_id', $this->user->id);
                                      } else {
                                          $childQuery->where('parent_id', $this->user->id);
                                      }
                                  });
                              });
                        break;
                }
            })
            ->when($this->dateFilter, function (Builder $query) {
                switch ($this->dateFilter) {
                    case 'today':
                        $query->whereDate('exam_date', today());
                        break;
                    case 'tomorrow':
                        $query->whereDate('exam_date', today()->addDay());
                        break;
                    case 'this_week':
                        $query->whereBetween('exam_date', [now()->startOfWeek(), now()->endOfWeek()]);
                        break;
                    case 'next_week':
                        $query->whereBetween('exam_date', [now()->addWeek()->startOfWeek(), now()->addWeek()->endOfWeek()]);
                        break;
                    case 'this_month':
                        $query->whereMonth('exam_date', now()->month)
                              ->whereYear('exam_date', now()->year);
                        break;
                    case 'past':
                        $query->where('exam_date', '<', now());
                        break;
                }
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Helper functions
    public function getExamStatusColor($exam): string
    {
        $examDate = \Carbon\Carbon::parse($exam->exam_date);
        $hasResult = $this->hasExamResult($exam);

        if ($examDate->isFuture()) {
            return 'bg-blue-100 text-blue-800'; // Upcoming
        } elseif ($hasResult) {
            return 'bg-green-100 text-green-800'; // Graded
        } else {
            return 'bg-yellow-100 text-yellow-800'; // Pending Results
        }
    }

    public function getExamStatus($exam): string
    {
        $examDate = \Carbon\Carbon::parse($exam->exam_date);
        $hasResult = $this->hasExamResult($exam);

        if ($examDate->isFuture()) {
            return 'Upcoming';
        } elseif ($hasResult) {
            return 'Graded';
        } else {
            return 'Pending Results';
        }
    }

    public function hasExamResult($exam): bool
    {
        // Check if student has a result for this exam
        return ExamResult::where('exam_id', $exam->id)
            ->whereHas('childProfile', function ($query) {
                if ($this->user->hasRole('student')) {
                    $query->where('user_id', $this->user->id);
                } else {
                    $query->where('parent_id', $this->user->id);
                }
            })
            ->exists();
    }

    public function getExamResult($exam)
    {
        return ExamResult::where('exam_id', $exam->id)
            ->whereHas('childProfile', function ($query) {
                if ($this->user->hasRole('student')) {
                    $query->where('user_id', $this->user->id);
                } else {
                    $query->where('parent_id', $this->user->id);
                }
            })
            ->first();
    }

    public function getTypeColor(string $type): string
    {
        return match($type) {
            'midterm' => 'bg-orange-100 text-orange-800',
            'final' => 'bg-red-100 text-red-800',
            'quiz' => 'bg-blue-100 text-blue-800',
            'assignment' => 'bg-green-100 text-green-800',
            'project' => 'bg-purple-100 text-purple-800',
            'practical' => 'bg-indigo-100 text-indigo-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function formatExamDate($date): string
    {
        try {
            $examDate = \Carbon\Carbon::parse($date);
            return $examDate->format('M d, Y');
        } catch (\Exception $e) {
            return 'Date not available';
        }
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
    <x-header title="My Exams" subtitle="View your exam schedule and results" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search exams..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$statusFilter, $subjectFilter, $typeFilter, $academicYearFilter, $dateFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="{{ $viewMode === 'grid' ? 'List View' : 'Grid View' }}"
                icon="{{ $viewMode === 'grid' ? 'o-list-bullet' : 'o-squares-2x2' }}"
                wire:click="setViewMode('{{ $viewMode === 'grid' ? 'list' : 'grid' }}')"
                class="btn-ghost"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-6">
        <x-card>
            <div class="p-6 text-center">
                <div class="p-3 mx-auto mb-3 bg-blue-100 rounded-full w-fit">
                    <x-icon name="o-document-text" class="w-6 h-6 text-blue-600" />
                </div>
                <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_exams']) }}</div>
                <div class="text-sm text-gray-500">Total Exams</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="p-3 mx-auto mb-3 bg-orange-100 rounded-full w-fit">
                    <x-icon name="o-calendar" class="w-6 h-6 text-orange-600" />
                </div>
                <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['upcoming_exams']) }}</div>
                <div class="text-sm text-gray-500">Upcoming</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="p-3 mx-auto mb-3 bg-gray-100 rounded-full w-fit">
                    <x-icon name="o-check-circle" class="w-6 h-6 text-gray-600" />
                </div>
                <div class="text-2xl font-bold text-gray-600">{{ number_format($stats['completed_exams']) }}</div>
                <div class="text-sm text-gray-500">Completed</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="p-3 mx-auto mb-3 bg-green-100 rounded-full w-fit">
                    <x-icon name="o-academic-cap" class="w-6 h-6 text-green-600" />
                </div>
                <div class="text-2xl font-bold text-green-600">{{ number_format($stats['graded_exams']) }}</div>
                <div class="text-sm text-gray-500">Graded</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="p-3 mx-auto mb-3 bg-yellow-100 rounded-full w-fit">
                    <x-icon name="o-clock" class="w-6 h-6 text-yellow-600" />
                </div>
                <div class="text-2xl font-bold text-yellow-600">{{ number_format($stats['pending_results']) }}</div>
                <div class="text-sm text-gray-500">Pending Results</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="p-3 mx-auto mb-3 bg-purple-100 rounded-full w-fit">
                    <x-icon name="o-chart-bar" class="w-6 h-6 text-purple-600" />
                </div>
                <div class="text-2xl font-bold text-purple-600">{{ $stats['average_score'] }}%</div>
                <div class="text-sm text-gray-500">Average Score</div>
            </div>
        </x-card>
    </div>

    <!-- Upcoming Exams Alert -->
    @if($stats['upcoming_exams'] > 0)
        <div class="p-4 mb-6 border border-blue-200 rounded-lg bg-blue-50">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <x-icon name="o-calendar" class="w-6 h-6 mr-3 text-blue-600" />
                    <div>
                        <h3 class="font-medium text-blue-800">Upcoming Exams</h3>
                        <p class="text-sm text-blue-700">
                            You have {{ $stats['upcoming_exams'] }} upcoming exam{{ $stats['upcoming_exams'] > 1 ? 's' : '' }}.
                            Stay prepared!
                        </p>
                    </div>
                </div>
                <x-button
                    label="View Upcoming"
                    wire:click="$set('statusFilter', 'upcoming')"
                    class="btn-sm btn-primary"
                />
            </div>
        </div>
    @endif

    <!-- Pending Results Alert -->
    @if($stats['pending_results'] > 0)
        <div class="p-4 mb-6 border border-yellow-200 rounded-lg bg-yellow-50">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <x-icon name="o-clock" class="w-6 h-6 mr-3 text-yellow-600" />
                    <div>
                        <h3 class="font-medium text-yellow-800">Results Pending</h3>
                        <p class="text-sm text-yellow-700">
                            {{ $stats['pending_results'] }} exam{{ $stats['pending_results'] > 1 ? 's' : '' }}
                            {{ $stats['pending_results'] > 1 ? 'are' : 'is' }} waiting for results.
                        </p>
                    </div>
                </div>
                <x-button
                    label="View Pending"
                    wire:click="$set('statusFilter', 'pending_results')"
                    class="btn-sm btn-warning"
                />
            </div>
        </div>
    @endif

    <!-- Exams List -->
    @if($exams->count() > 0)
        @if($viewMode === 'grid')
            <!-- Grid View -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                @foreach($exams as $exam)
                    @php
                        $examStatus = $this->getExamStatus($exam);
                        $hasResult = $this->hasExamResult($exam);
                        $examResult = $hasResult ? $this->getExamResult($exam) : null;
                        $isUpcoming = \Carbon\Carbon::parse($exam->exam_date)->isFuture();
                        $isToday = \Carbon\Carbon::parse($exam->exam_date)->isToday();
                    @endphp
                    <x-card class="hover:shadow-lg transition-shadow duration-200 {{ $isToday ? 'border-orange-300 bg-orange-50' : '' }}">
                        <div class="p-6">
                            <!-- Header with status -->
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex-1">
                                    <button
                                        wire:click="redirectToShow({{ $exam->id }})"
                                        class="text-lg font-semibold text-left text-blue-600 underline hover:text-blue-800"
                                    >
                                        {{ $exam->title }}
                                    </button>
                                    <p class="text-sm text-gray-500">
                                        {{ $exam->subject->name ?? 'Unknown Subject' }}
                                        @if($exam->subject && $exam->subject->code)
                                            ({{ $exam->subject->code }})
                                        @endif
                                    </p>
                                </div>
                                <div class="flex flex-col items-end gap-2">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getExamStatusColor($exam) }}">
                                        {{ $examStatus }}
                                    </span>
                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium {{ $this->getTypeColor($exam->type) }}">
                                        {{ ucfirst($exam->type) }}
                                    </span>
                                </div>
                            </div>

                            <!-- Student Info (for parents with multiple children) -->
                            @if(count($childProfiles) > 1)
                                <div class="flex items-center p-3 mb-4 rounded-lg bg-gray-50">
                                    <div class="mr-3 avatar">
                                        <div class="w-10 h-10 rounded-full">
                                            <img src="https://ui-avatars.com/api/?name={{ urlencode($childProfiles[0]['full_name'] ?? 'Student') }}&color=7F9CF5&background=EBF4FF" alt="Student" />
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium">{{ $childProfiles[0]['full_name'] ?? 'Student' }}</div>
                                        @if($exam->academicYear)
                                            <div class="text-xs text-gray-500">{{ $exam->academicYear->name }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <!-- Exam Results -->
                            @if($hasResult && $examResult)
                                <div class="p-3 mb-4 border border-green-200 rounded-lg bg-green-50">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <div class="text-lg font-bold text-green-800">{{ $examResult->score ?? 'N/A' }}/100</div>
                                            <div class="text-sm text-green-600">Your Score</div>
                                        </div>
                                        <div class="text-right">
                                            @php
                                                $grade = match(true) {
                                                    $examResult->score >= 90 => 'A',
                                                    $examResult->score >= 80 => 'B',
                                                    $examResult->score >= 70 => 'C',
                                                    $examResult->score >= 60 => 'D',
                                                    default => 'F'
                                                };
                                                $gradeColor = match($grade) {
                                                    'A' => 'bg-green-100 text-green-800',
                                                    'B' => 'bg-blue-100 text-blue-800',
                                                    'C' => 'bg-yellow-100 text-yellow-800',
                                                    'D' => 'bg-orange-100 text-orange-800',
                                                    'F' => 'bg-red-100 text-red-800',
                                                    default => 'bg-gray-100 text-gray-600'
                                                };
                                            @endphp
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $gradeColor }}">
                                                {{ $grade }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            @elseif(!$isUpcoming)
                                <div class="p-3 mb-4 border border-yellow-200 rounded-lg bg-yellow-50">
                                    <div class="flex items-center">
                                        <x-icon name="o-clock" class="w-5 h-5 mr-2 text-yellow-600" />
                                        <div class="text-sm text-yellow-800">Results pending</div>
                                    </div>
                                </div>
                            @endif

                            <!-- Exam Details -->
                            <div class="mb-4 space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Date:</span>
                                    <span class="{{ $isToday ? 'text-orange-600 font-medium' : '' }}">
                                        {{ $this->formatExamDate($exam->exam_date) }}
                                        @if($isToday)
                                            (Today!)
                                        @endif
                                    </span>
                                </div>
                                @if($exam->academicYear)
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Academic Year:</span>
                                        <span>{{ $exam->academicYear->name }}</span>
                                    </div>
                                @endif
                                @if($exam->teacherProfile)
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Instructor:</span>
                                        <span>{{ $exam->teacherProfile->user->name ?? 'Unknown' }}</span>
                                    </div>
                                @endif
                            </div>

                            <!-- Today's Exam Alert -->
                            @if($isToday)
                                <div class="flex items-center p-2 mb-4 text-sm text-orange-800 bg-orange-100 rounded">
                                    <x-icon name="o-exclamation-triangle" class="w-4 h-4 mr-2" />
                                    Exam is today! Good luck!
                                </div>
                            @elseif($isUpcoming)
                                @php
                                    $daysUntil = \Carbon\Carbon::parse($exam->exam_date)->diffInDays(now());
                                @endphp
                                @if($daysUntil <= 7)
                                    <div class="flex items-center p-2 mb-4 text-sm text-blue-800 bg-blue-100 rounded">
                                        <x-icon name="o-calendar" class="w-4 h-4 mr-2" />
                                        Exam in {{ $daysUntil }} day{{ $daysUntil !== 1 ? 's' : '' }}
                                    </div>
                                @endif
                            @endif

                            <!-- Actions -->
                            <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                                <div class="flex items-center space-x-2">
                                    @if($hasResult)
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-green-700 bg-green-100 rounded">
                                            <x-icon name="o-check" class="w-3 h-3 mr-1" />
                                            Graded
                                        </span>
                                    @elseif($isUpcoming)
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-700 bg-blue-100 rounded">
                                            <x-icon name="o-clock" class="w-3 h-3 mr-1" />
                                            Upcoming
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-yellow-700 bg-yellow-100 rounded">
                                            <x-icon name="o-exclamation-triangle" class="w-3 h-3 mr-1" />
                                            Pending
                                        </span>
                                    @endif
                                </div>

                                <x-button
                                    label="View Details"
                                    icon="o-arrow-right"
                                    wire:click="redirectToShow({{ $exam->id }})"
                                    class="btn-sm btn-primary"
                                />
                            </div>
                        </div>
                    </x-card>
                @endforeach
            </div>
        @else
            <!-- List View -->
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
                                <th>Subject</th>
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
                                        Date
                                        @if ($sortBy['column'] === 'exam_date')
                                            <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                        @endif
                                    </div>
                                </th>
                                <th>Status</th>
                                <th>Score</th>
                                <th>Instructor</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($exams as $exam)
                                @php
                                    $examStatus = $this->getExamStatus($exam);
                                    $hasResult = $this->hasExamResult($exam);
                                    $examResult = $hasResult ? $this->getExamResult($exam) : null;
                                    $isToday = \Carbon\Carbon::parse($exam->exam_date)->isToday();
                                @endphp
                                <tr class="hover {{ $isToday ? 'bg-orange-50' : '' }}">
                                    <td>
                                        <button
                                            wire:click="redirectToShow({{ $exam->id }})"
                                            class="font-semibold text-left text-blue-600 underline hover:text-blue-800"
                                        >
                                            {{ $exam->title }}
                                        </button>
                                        @if($isToday)
                                            <div class="text-xs font-medium text-orange-600">Today!</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="font-medium">{{ $exam->subject->name ?? 'Unknown' }}</div>
                                        @if($exam->subject && $exam->subject->code)
                                            <div class="text-xs text-gray-500">{{ $exam->subject->code }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium {{ $this->getTypeColor($exam->type) }}">
                                            {{ ucfirst($exam->type) }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="text-sm">{{ $this->formatExamDate($exam->exam_date) }}</div>
                                        @if($exam->academicYear)
                                            <div class="text-xs text-gray-500">{{ $exam->academicYear->name }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getExamStatusColor($exam) }}">
                                            {{ $examStatus }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($hasResult && $examResult)
                                            <div class="font-medium text-green-600">{{ $examResult->score ?? 'N/A' }}/100</div>
                                            @php
                                                $grade = match(true) {
                                                    $examResult->score >= 90 => 'A',
                                                    $examResult->score >= 80 => 'B',
                                                    $examResult->score >= 70 => 'C',
                                                    $examResult->score >= 60 => 'D',
                                                    default => 'F'
                                                };
                                            @endphp
                                            <div class="text-xs text-gray-500">Grade: {{ $grade }}</div>
                                        @else
                                            <div class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($exam->exam_date)->isFuture() ? 'Not taken' : 'Pending' }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="text-sm">{{ $exam->teacherProfile->user->name ?? 'Unknown' }}</div>
                                        @if($exam->teacherProfile && $exam->teacherProfile->user->email)
                                            <div class="text-xs text-gray-500">{{ $exam->teacherProfile->user->email }}</div>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <div class="flex justify-end gap-2">
                                            <button
                                                wire:click="redirectToShow({{ $exam->id }})"
                                                class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                                title="View Details"
                                            >
                                                👁️
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        @endif

        <!-- Pagination -->
        <div class="mt-8">
            {{ $exams->links() }}
        </div>

        <!-- Results summary -->
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $exams->firstItem() ?? 0 }} to {{ $exams->lastItem() ?? 0 }}
            of {{ $exams->total() }} exams
            @if($search || $statusFilter || $subjectFilter || $typeFilter || $academicYearFilter || $dateFilter)
                (filtered from total)
            @endif
        </div>

    @else
        <!-- Empty State -->
        <x-card>
            <div class="py-12 text-center">
                <div class="flex flex-col items-center justify-center gap-4">
                    <x-icon name="o-document-text" class="w-20 h-20 text-gray-300" />
                    <div>
                        <h3 class="text-lg font-semibold text-gray-600">No exams found</h3>
                        <p class="mt-1 text-gray-500">
                            @if($search || $statusFilter || $subjectFilter || $typeFilter || $academicYearFilter || $dateFilter)
                                No exams match your current filters.
                            @else
                                You don't have any exams scheduled yet.
                            @endif
                        </p>
                    </div>
                    @if($search || $statusFilter || $subjectFilter || $typeFilter || $academicYearFilter || $dateFilter)
                        <x-button
                            label="Clear Filters"
                            wire:click="clearFilters"
                            color="secondary"
                            size="sm"
                        />
                    @endif
                </div>
            </div>
        </x-card>
    @endif

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Filter Exams" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search exams"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by title, subject..."
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
                    :options="$dateOptions"
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
                        ['id' => 6, 'name' => '6 per page'],
                        ['id' => 12, 'name' => '12 per page'],
                        ['id' => 18, 'name' => '18 per page'],
                        ['id' => 24, 'name' => '24 per page']
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

    <!-- Quick Actions Sidebar (if multiple children) -->
    @if(count($childProfiles) > 1)
        <div class="fixed z-50 bottom-4 right-4">
            <x-dropdown>
                <x-slot:trigger>
                    <x-button icon="o-user-group" class="shadow-lg btn-circle btn-primary">
                        {{ count($childProfiles) }}
                    </x-button>
                </x-slot:trigger>

                <x-menu-item title="Quick Filter" />
                <x-menu-separator />

                @foreach($childProfiles as $child)
                    <x-menu-item
                        title="{{ $child['full_name'] ?? 'Unknown Student' }}"
                        subtitle="View exams"
                        link="{{ route('student.exams.index') }}?child={{ $child['id'] ?? '' }}"
                    />
                @endforeach
            </x-dropdown>
        </div>
    @endif

    <!-- Floating Today's Exams Button -->
    @php
        $todayExams = collect($exams->items())->filter(function($exam) {
            return \Carbon\Carbon::parse($exam->exam_date)->isToday();
        })->count();
    @endphp
    @if($todayExams > 0)
        <div class="fixed z-50 bottom-6 left-6">
            <x-button
                icon="o-exclamation-triangle"
                wire:click="$set('dateFilter', 'today')"
                class="shadow-lg btn-circle btn-warning animate-pulse"
                title="{{ $todayExams }} Exam(s) Today"
            />
        </div>
    @endif
</div>
