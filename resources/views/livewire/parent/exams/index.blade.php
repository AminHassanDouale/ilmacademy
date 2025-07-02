<?php

use App\Models\Exam;
use App\Models\ExamResult;
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

new #[Title('Exams & Results')] class extends Component {
    use WithPagination;
    use Toast;

    // Search and filters
    #[Url]
    public string $search = '';

    #[Url]
    public string $examTypeFilter = '';

    #[Url]
    public string $childFilter = '';

    #[Url]
    public string $subjectFilter = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public array $sortBy = ['column' => 'exam_date', 'direction' => 'desc'];

    // Stats
    public array $stats = [];

    // Filter options
    public array $examTypeOptions = [];
    public array $childOptions = [];
    public array $subjectOptions = [];

    public function mount(): void
    {
        // Set default date range (last 6 months)
        if (!$this->dateFrom) {
            $this->dateFrom = now()->subMonths(6)->format('Y-m-d');
        }
        if (!$this->dateTo) {
            $this->dateTo = now()->addMonths(3)->format('Y-m-d'); // Include future exams
        }

        // Pre-select child if provided in query
        if (request()->has('child')) {
            $childId = request()->get('child');
            $child = ChildProfile::where('id', $childId)
                ->where('parent_id', Auth::id())
                ->first();

            if ($child) {
                $this->childFilter = (string) $child->id;
            }
        }

        // Log activity
        ActivityLog::logActivity(
            Auth::id(),
            'access',
            'Accessed exams and results page'
        );

        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        // Exam type options
        $this->examTypeOptions = [
            ['id' => '', 'name' => 'All Types'],
            ['id' => 'quiz', 'name' => 'Quiz'],
            ['id' => 'test', 'name' => 'Test'],
            ['id' => 'midterm', 'name' => 'Midterm'],
            ['id' => 'final', 'name' => 'Final Exam'],
            ['id' => 'assignment', 'name' => 'Assignment'],
            ['id' => 'project', 'name' => 'Project'],
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

        // Subject options - from exams where children are enrolled
        try {
            $subjects = Exam::query()
                ->whereHas('examResults.childProfile', function ($query) {
                    $query->where('parent_id', Auth::id());
                })
                ->with('subject')
                ->get()
                ->pluck('subject')
                ->filter()
                ->unique('id')
                ->sortBy('name');

            $this->subjectOptions = [
                ['id' => '', 'name' => 'All Subjects'],
                ...$subjects->map(fn($subject) => [
                    'id' => $subject->id,
                    'name' => $subject->name
                ])->toArray()
            ];
        } catch (\Exception $e) {
            $this->subjectOptions = [['id' => '', 'name' => 'All Subjects']];
        }
    }

    protected function loadStats(): void
    {
        try {
            // Get exams that have results for the parent's children
            $examsQuery = Exam::query()
                ->whereHas('examResults.childProfile', function ($query) {
                    $query->where('parent_id', Auth::id());
                })
                ->when($this->dateFrom, function ($query) {
                    $query->whereDate('exam_date', '>=', $this->dateFrom);
                })
                ->when($this->dateTo, function ($query) {
                    $query->whereDate('exam_date', '<=', $this->dateTo);
                });

            // Get exam results for the parent's children
            $resultsQuery = ExamResult::query()
                ->whereHas('childProfile', function ($query) {
                    $query->where('parent_id', Auth::id());
                })
                ->whereHas('exam', function ($query) {
                    $query->when($this->dateFrom, function ($q) {
                        $q->whereDate('exam_date', '>=', $this->dateFrom);
                    })
                    ->when($this->dateTo, function ($q) {
                        $q->whereDate('exam_date', '<=', $this->dateTo);
                    });
                });

            $totalExams = $examsQuery->count();
            $totalResults = $resultsQuery->count();

            // Calculate average score
            $averageScore = $resultsQuery->avg('score') ?? 0;

            // Get upcoming exams (future dated)
            $upcomingExams = Exam::query()
                ->whereHas('examResults.childProfile', function ($query) {
                    $query->where('parent_id', Auth::id());
                })
                ->where('exam_date', '>', now())
                ->count();

            // Get children with exam results
            $childrenWithResults = $resultsQuery->distinct('child_profile_id')->count('child_profile_id');

            // Get performance distribution
            $excellentResults = $resultsQuery->where('score', '>=', 90)->count();
            $goodResults = $resultsQuery->whereBetween('score', [80, 89])->count();
            $satisfactoryResults = $resultsQuery->whereBetween('score', [70, 79])->count();
            $needsImprovementResults = $resultsQuery->where('score', '<', 70)->count();

            $this->stats = [
                'total_exams' => $totalExams,
                'total_results' => $totalResults,
                'average_score' => round($averageScore, 1),
                'upcoming_exams' => $upcomingExams,
                'children_with_results' => $childrenWithResults,
                'excellent_results' => $excellentResults,
                'good_results' => $goodResults,
                'satisfactory_results' => $satisfactoryResults,
                'needs_improvement_results' => $needsImprovementResults,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_exams' => 0,
                'total_results' => 0,
                'average_score' => 0,
                'upcoming_exams' => 0,
                'children_with_results' => 0,
                'excellent_results' => 0,
                'good_results' => 0,
                'satisfactory_results' => 0,
                'needs_improvement_results' => 0,
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
    public function redirectToShow(int $examId): void
    {
        $this->redirect(route('parent.exams.show', $examId));
    }

    // Update methods
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedExamTypeFilter(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedChildFilter(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedSubjectFilter(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->examTypeFilter = '';
        $this->childFilter = '';
        $this->subjectFilter = '';
        $this->dateFrom = now()->subMonths(6)->format('Y-m-d');
        $this->dateTo = now()->addMonths(3)->format('Y-m-d');
        $this->resetPage();
        $this->loadStats();
    }

    // Get filtered and paginated exams with results
    public function exams(): LengthAwarePaginator
    {
        return Exam::query()
            ->whereHas('examResults.childProfile', function ($query) {
                $query->where('parent_id', Auth::id());
            })
            ->with(['subject', 'teacherProfile.user', 'academicYear', 'examResults' => function ($query) {
                $query->whereHas('childProfile', function ($q) {
                    $q->where('parent_id', Auth::id());
                })->with('childProfile');
            }])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('title', 'like', "%{$this->search}%")
                      ->orWhereHas('subject', function ($subjectQuery) {
                          $subjectQuery->where('name', 'like', "%{$this->search}%");
                      })
                      ->orWhereHas('teacherProfile.user', function ($teacherQuery) {
                          $teacherQuery->where('name', 'like', "%{$this->search}%");
                      });
                });
            })
            ->when($this->examTypeFilter, function (Builder $query) {
                $query->where('type', $this->examTypeFilter);
            })
            ->when($this->childFilter, function (Builder $query) {
                $query->whereHas('examResults', function ($q) {
                    $q->where('child_profile_id', $this->childFilter);
                });
            })
            ->when($this->subjectFilter, function (Builder $query) {
                $query->where('subject_id', $this->subjectFilter);
            })
            ->when($this->dateFrom, function (Builder $query) {
                $query->whereDate('exam_date', '>=', $this->dateFrom);
            })
            ->when($this->dateTo, function (Builder $query) {
                $query->whereDate('exam_date', '<=', $this->dateTo);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Helper function to get exam type color
    private function getExamTypeColor(string $type): string
    {
        return match($type) {
            'quiz' => 'bg-blue-100 text-blue-800',
            'test' => 'bg-green-100 text-green-800',
            'midterm' => 'bg-yellow-100 text-yellow-800',
            'final' => 'bg-red-100 text-red-800',
            'assignment' => 'bg-purple-100 text-purple-800',
            'project' => 'bg-indigo-100 text-indigo-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to get score color
    private function getScoreColor(float $score): string
    {
        return match(true) {
            $score >= 90 => 'text-green-600',
            $score >= 80 => 'text-blue-600',
            $score >= 70 => 'text-yellow-600',
            default => 'text-red-600'
        };
    }

    // Helper function to get score grade
    private function getScoreGrade(float $score): string
    {
        return match(true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F'
        };
    }

    // Format date for display
    public function formatDate($date): string
    {
        if (!$date) {
            return 'Not set';
        }

        if (is_string($date)) {
            $date = \Carbon\Carbon::parse($date);
        }

        return $date->format('M d, Y');
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
    <x-header title="Exams & Results" subtitle="Track your children's exam performance and upcoming tests" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search exams..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-4">
        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-blue-100 rounded-full">
                        <x-icon name="o-academic-cap" class="w-8 h-8 text-blue-600" />
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
                    <div class="p-3 mr-4 bg-green-100 rounded-full">
                        <x-icon name="o-chart-bar" class="w-8 h-8 text-green-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-600">{{ $stats['average_score'] }}%</div>
                        <div class="text-sm text-gray-500">Average Score</div>
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
                        <div class="text-sm text-gray-500">Upcoming Exams</div>
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
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['children_with_results']) }}</div>
                        <div class="text-sm text-gray-500">Children Tracked</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Performance Distribution -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-4">
        <x-card class="border-green-200 bg-green-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-green-600">{{ number_format($stats['excellent_results']) }}</div>
                <div class="text-sm text-green-600">Excellent (90-100%)</div>
            </div>
        </x-card>

        <x-card class="border-blue-200 bg-blue-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['good_results']) }}</div>
                <div class="text-sm text-blue-600">Good (80-89%)</div>
            </div>
        </x-card>

        <x-card class="border-yellow-200 bg-yellow-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-yellow-600">{{ number_format($stats['satisfactory_results']) }}</div>
                <div class="text-sm text-yellow-600">Satisfactory (70-79%)</div>
            </div>
        </x-card>

        <x-card class="border-red-200 bg-red-50">
            <div class="p-4 text-center">
                <div class="text-2xl font-bold text-red-600">{{ number_format($stats['needs_improvement_results']) }}</div>
                <div class="text-sm text-red-600">Needs Improvement (<70%)</div>
            </div>
        </x-card>
    </div>

    <!-- Filters Row -->
    <div class="mb-6">
        <x-card>
            <div class="p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
                    <div>
                        <x-select
                            label="Exam Type"
                            :options="$examTypeOptions"
                            wire:model.live="examTypeFilter"
                            option-value="id"
                            option-label="name"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Child"
                            :options="$childOptions"
                            wire:model.live="childFilter"
                            option-value="id"
                            option-label="name"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Subject"
                            :options="$subjectOptions"
                            wire:model.live="subjectFilter"
                            option-value="id"
                            option-label="name"
                        />
                    </div>

                    <div>
                        <x-input
                            label="From Date"
                            wire:model.live="dateFrom"
                            type="date"
                        />
                    </div>

                    <div>
                        <x-input
                            label="To Date"
                            wire:model.live="dateTo"
                            type="date"
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

    <!-- Exams List -->
    @if($exams->count() > 0)
        <div class="space-y-4">
            @foreach($exams as $exam)
                <x-card class="transition-shadow duration-200 hover:shadow-lg">
                    <div class="p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex-1">
                                <div class="flex items-center gap-3 mb-2">
                                    <button
                                        wire:click="redirectToShow({{ $exam->id }})"
                                        class="text-lg font-semibold text-blue-600 underline hover:text-blue-800"
                                    >
                                        {{ $exam->title }}
                                    </button>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getExamTypeColor($exam->type) }}">
                                        {{ ucfirst($exam->type) }}
                                    </span>
                                    @if($exam->exam_date > now())
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                                            Upcoming
                                        </span>
                                    @endif
                                </div>

                                <div class="grid grid-cols-1 gap-2 mb-4 md:grid-cols-3">
                                    <div class="flex items-center text-sm text-gray-600">
                                        <x-icon name="o-book-open" class="w-4 h-4 mr-2" />
                                        {{ $exam->subject->name ?? 'Unknown Subject' }}
                                    </div>

                                    <div class="flex items-center text-sm text-gray-600">
                                        <x-icon name="o-calendar" class="w-4 h-4 mr-2" />
                                        {{ $this->formatDate($exam->exam_date) }}
                                    </div>

                                    @if($exam->teacherProfile && $exam->teacherProfile->user)
                                        <div class="flex items-center text-sm text-gray-600">
                                            <x-icon name="o-user" class="w-4 h-4 mr-2" />
                                            {{ $exam->teacherProfile->user->name }}
                                        </div>
                                    @endif
                                </div>

                                <!-- Children Results -->
                                @if($exam->examResults->count() > 0)
                                    <div>
                                        <div class="mb-2 text-sm font-medium text-gray-700">Results:</div>
                                        <div class="grid grid-cols-1 gap-2 md:grid-cols-2 lg:grid-cols-3">
                                            @foreach($exam->examResults as $result)
                                                <div class="flex items-center justify-between p-3 border rounded-md bg-gray-50">
                                                    <div class="flex items-center">
                                                        <div class="mr-3 avatar placeholder">
                                                            <div class="w-8 h-8 text-white rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                                                                <span class="text-xs font-bold">{{ $result->childProfile->initials ?? '??' }}</span>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <div class="text-sm font-medium">{{ $result->childProfile->full_name ?? 'Unknown Child' }}</div>
                                                            @if($result->remarks)
                                                                <div class="text-xs text-gray-500">{{ $result->remarks }}</div>
                                                            @endif
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <div class="font-bold {{ $this->getScoreColor($result->score) }}">
                                                            {{ number_format($result->score, 1) }}%
                                                        </div>
                                                        <div class="text-xs {{ $this->getScoreColor($result->score) }}">
                                                            Grade {{ $this->getScoreGrade($result->score) }}
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @else
                                    <div class="p-3 border border-yellow-200 rounded-md bg-yellow-50">
                                        <div class="text-sm text-yellow-800">No results available yet</div>
                                    </div>
                                @endif
                            </div>

                            <!-- Actions -->
                            <div class="flex gap-2 ml-4">
                                <x-button
                                    label="View Details"
                                    icon="o-eye"
                                    wire:click="redirectToShow({{ $exam->id }})"
                                    class="btn-sm btn-outline"
                                />
                            </div>
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="mt-6">
            {{ $exams->links() }}
        </div>

        <!-- Results summary -->
        <div class="mt-4 text-sm text-gray-600">
            Showing {{ $exams->firstItem() ?? 0 }} to {{ $exams->lastItem() ?? 0 }}
            of {{ $exams->total() }} exams
            @if($search || $examTypeFilter || $childFilter || $subjectFilter || $dateFrom || $dateTo)
                (filtered)
            @endif
        </div>
    @else
        <!-- Empty State -->
        <x-card>
            <div class="py-12 text-center">
                <div class="flex flex-col items-center justify-center gap-4">
                    <x-icon name="o-academic-cap" class="w-20 h-20 text-gray-300" />
                    <div>
                        <h3 class="text-lg font-semibold text-gray-600">No exams found</h3>
                        <p class="mt-1 text-gray-500">
                            @if($search || $examTypeFilter || $childFilter || $subjectFilter || $dateFrom || $dateTo)
                                No exams match your current filters.
                            @else
                                Exam results will appear here once your children take tests and receive grades.
                            @endif
                        </p>
                    </div>
                    @if($search || $examTypeFilter || $childFilter || $subjectFilter)
                        <x-button
                            label="Clear Filters"
                            wire:click="clearFilters"
                            class="btn-secondary"
                        />
                    @endif
                </div>
            </div>
        </x-card>
    @endif

    <!-- Performance Insights -->
    @if($stats['total_results'] > 0)
        <div class="mt-8">
            <x-card title="Performance Insights">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <!-- Average Performance -->
                    <div>
                        <h4 class="mb-3 font-medium text-gray-900">Overall Performance</h4>
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Average Score</span>
                                <span class="font-semibold {{ $this->getScoreColor($stats['average_score']) }}">
                                    {{ $stats['average_score'] }}%
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Grade Level</span>
                                <span class="font-semibold {{ $this->getScoreColor($stats['average_score']) }}">
                                    {{ $this->getScoreGrade($stats['average_score']) }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Total Assessments</span>
                                <span class="font-semibold">{{ $stats['total_results'] }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Performance Distribution -->
                    <div>
                        <h4 class="mb-3 font-medium text-gray-900">Score Distribution</h4>
                        <div class="space-y-2">
                            @php
                                $total = $stats['total_results'];
                            @endphp
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-green-600">Excellent (90%+)</span>
                                <span class="font-semibold">
                                    {{ $stats['excellent_results'] }} ({{ $total > 0 ? round(($stats['excellent_results'] / $total) * 100, 1) : 0 }}%)
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-blue-600">Good (80-89%)</span>
                                <span class="font-semibold">
                                    {{ $stats['good_results'] }} ({{ $total > 0 ? round(($stats['good_results'] / $total) * 100, 1) : 0 }}%)
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-yellow-600">Satisfactory (70-79%)</span>
                                <span class="font-semibold">
                                    {{ $stats['satisfactory_results'] }} ({{ $total > 0 ? round(($stats['satisfactory_results'] / $total) * 100, 1) : 0 }}%)
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-red-600">Needs Improvement (<70%)</span>
                                <span class="font-semibold">
                                    {{ $stats['needs_improvement_results'] }} ({{ $total > 0 ? round(($stats['needs_improvement_results'] / $total) * 100, 1) : 0 }}%)
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    @endif
</div>
