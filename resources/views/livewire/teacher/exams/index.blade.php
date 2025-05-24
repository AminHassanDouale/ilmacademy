<?php

use App\Models\Exam;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Exams')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $subject = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $period = 'upcoming';

    #[Url]
    public string $startDate = '';

    #[Url]
    public string $endDate = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'date', 'direction' => 'asc'];

    public function mount(): void
    {
        // Default dates if not set
        if (empty($this->startDate)) {
            $this->startDate = Carbon::now()->format('Y-m-d');
        }

        if (empty($this->endDate)) {
            $this->endDate = Carbon::now()->addMonths(3)->format('Y-m-d');
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher accessed exams page',
            Exam::class,
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

    // Set time period
    public function setPeriod(string $period): void
    {
        $this->period = $period;

        switch ($period) {
            case 'today':
                $this->startDate = Carbon::now()->format('Y-m-d');
                $this->endDate = Carbon::now()->format('Y-m-d');
                break;
            case 'tomorrow':
                $this->startDate = Carbon::tomorrow()->format('Y-m-d');
                $this->endDate = Carbon::tomorrow()->format('Y-m-d');
                break;
            case 'this_week':
                $this->startDate = Carbon::now()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfWeek()->format('Y-m-d');
                break;
            case 'next_week':
                $this->startDate = Carbon::now()->next('monday')->format('Y-m-d');
                $this->endDate = Carbon::now()->next('monday')->addDays(6)->format('Y-m-d');
                break;
            case 'upcoming':
                $this->startDate = Carbon::now()->format('Y-m-d');
                $this->endDate = Carbon::now()->addMonths(3)->format('Y-m-d');
                break;
            case 'past':
                $this->startDate = Carbon::now()->subMonths(3)->format('Y-m-d');
                $this->endDate = Carbon::yesterday()->format('Y-m-d');
                break;
            case 'all':
                $this->startDate = Carbon::now()->subYear()->format('Y-m-d');
                $this->endDate = Carbon::now()->addYear()->format('Y-m-d');
                break;
            case 'custom':
                // Keep existing dates
                break;
        }
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->subject = '';
        $this->type = '';
        $this->status = '';
        $this->period = 'upcoming';
        $this->startDate = Carbon::now()->format('Y-m-d');
        $this->endDate = Carbon::now()->addMonths(3)->format('Y-m-d');
        $this->resetPage();
    }

    // Get subjects for this teacher
    public function subjects()
    {
        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            return collect();
        }

        try {
            // If there's a subjects relationship
            if (method_exists($teacherProfile, 'subjects')) {
                return $teacherProfile->subjects()
                    ->orderBy('name')
                    ->get()
                    ->map(function ($subject) {
                        return [
                            'id' => $subject->id,
                            'name' => $subject->name . ' (' . $subject->code . ')'
                        ];
                    });
            }

            // Fallback: Get subjects from exams
            return Subject::whereHas('exams', function ($query) use ($teacherProfile) {
                $query->where('teacher_profile_id', $teacherProfile->id);
            })
            ->orderBy('name')
            ->get()
            ->map(function ($subject) {
                return [
                    'id' => $subject->id,
                    'name' => $subject->name . ' (' . $subject->code . ')'
                ];
            });
        } catch (\Exception $e) {
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading subjects: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip()]
            );

            return collect();
        }
    }

    // View exam details
    public function viewExam($examId)
    {
        return redirect()->route('teacher.exams.show', $examId);
    }

    // Edit exam
    public function editExam($examId)
    {
        return redirect()->route('teacher.exams.edit', $examId);
    }

    // Create new exam
    public function createExam()
    {
        return redirect()->route('teacher.exams.create');
    }

    // Grade an exam
    public function gradeExam($examId)
    {
        return redirect()->route('teacher.exams.grade', $examId);
    }

    // Get filtered exams
    public function exams()
    {
        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            return [];
        }

        try {
            $query = Exam::query()
                ->where('teacher_profile_id', $teacherProfile->id)
                ->with(['subject', 'subject.curriculum'])
                ->whereBetween('date', [$this->startDate, $this->endDate])
                ->when($this->search, function (Builder $query) {
                    $query->where(function($q) {
                        $q->where('title', 'like', '%' . $this->search . '%')
                          ->orWhere('description', 'like', '%' . $this->search . '%')
                          ->orWhereHas('subject', function ($subquery) {
                              $subquery->where('name', 'like', '%' . $this->search . '%')
                                      ->orWhere('code', 'like', '%' . $this->search . '%');
                          });
                    });
                })
                ->when($this->subject, function (Builder $query) {
                    $query->where('subject_id', $this->subject);
                })
                ->when($this->type, function (Builder $query) {
                    $query->where('type', $this->type);
                })
                ->when($this->status, function (Builder $query) {
                    // Filter by exam completion status
                    $now = Carbon::now();

                    if ($this->status === 'completed') {
                        $query->where('date', '<', $now->format('Y-m-d'));
                    } elseif ($this->status === 'upcoming') {
                        $query->where('date', '>', $now->format('Y-m-d'));
                    } elseif ($this->status === 'today') {
                        $query->where('date', '=', $now->format('Y-m-d'));
                    } elseif ($this->status === 'graded') {
                        $query->where('is_graded', true);
                    } elseif ($this->status === 'ungraded') {
                        $query->where(function($q) use ($now) {
                            $q->where('date', '<', $now->format('Y-m-d'))
                              ->where('is_graded', false);
                        });
                    }
                });

            // Get exams
            $exams = $query->orderBy($this->sortBy['column'], $this->sortBy['direction'])
                ->paginate($this->perPage);

            // Add status data to exams
            foreach ($exams as $exam) {
                $now = Carbon::now();
                $examDate = Carbon::parse($exam->date);

                $exam->status_data = [
                    'is_upcoming' => $examDate->isAfter($now),
                    'is_today' => $examDate->isToday(),
                    'is_completed' => $examDate->isBefore($now),
                    'needs_grading' => $examDate->isBefore($now) && !$exam->is_graded,
                    'is_graded' => $exam->is_graded,
                ];

                // Get student enrollment count
                if (method_exists($exam->subject, 'enrolledStudents')) {
                    $exam->student_count = $exam->subject->enrolledStudents()->count();
                } else {
                    $exam->student_count = 0;
                }
            }

            return $exams;
        } catch (\Exception $e) {
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading exams: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip()]
            );

            return [];
        }
    }

    // Get exam statistics
    public function examStats()
    {
        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            return [
                'total' => 0,
                'upcoming' => 0,
                'today' => 0,
                'completed' => 0,
                'graded' => 0,
                'needs_grading' => 0,
            ];
        }

        try {
            $now = Carbon::now();
            $today = Carbon::today();

            // Total exams
            $total = Exam::where('teacher_profile_id', $teacherProfile->id)->count();

            // Upcoming exams
            $upcoming = Exam::where('teacher_profile_id', $teacherProfile->id)
                ->where('date', '>', $now->format('Y-m-d'))
                ->count();

            // Today's exams
            $todayExams = Exam::where('teacher_profile_id', $teacherProfile->id)
                ->where('date', '=', $today->format('Y-m-d'))
                ->count();

            // Completed exams
            $completed = Exam::where('teacher_profile_id', $teacherProfile->id)
                ->where('date', '<', $now->format('Y-m-d'))
                ->count();

            // Graded exams
            $graded = Exam::where('teacher_profile_id', $teacherProfile->id)
                ->where('is_graded', true)
                ->count();

            // Exams needing grading
            $needsGrading = Exam::where('teacher_profile_id', $teacherProfile->id)
                ->where('date', '<', $now->format('Y-m-d'))
                ->where('is_graded', false)
                ->count();

            return [
                'total' => $total,
                'upcoming' => $upcoming,
                'today' => $todayExams,
                'completed' => $completed,
                'graded' => $graded,
                'needs_grading' => $needsGrading,
            ];
        } catch (\Exception $e) {
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error calculating exam stats: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip()]
            );

            return [
                'total' => 0,
                'upcoming' => 0,
                'today' => 0,
                'completed' => 0,
                'graded' => 0,
                'needs_grading' => 0,
            ];
        }
    }

    public function with(): array
    {
        return [
            'exams' => $this->exams(),
            'subjects' => $this->subjects(),
            'examStats' => $this->examStats(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Exams & Assessments" separator progress-indicator>
        <x-slot:subtitle>
            View and manage exams and assessments for your subjects
        </x-slot:subtitle>

        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search exams..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$subject, $type, $status, $period !== 'upcoming']))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive
            />
            <x-button
                label="New Exam"
                icon="o-plus"
                @click="$wire.createExam()"
                class="btn-primary"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- QUICK STATS PANEL -->
    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-6">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-clipboard-document-list" class="w-8 h-8" />
            </div>
            <div class="stat-title">Total</div>
            <div class="stat-value">{{ $examStats['total'] }}</div>
            <div class="stat-desc">All exams</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-info">
                <x-icon name="o-arrow-trending-up" class="w-8 h-8" />
            </div>
            <div class="stat-title">Upcoming</div>
            <div class="stat-value text-info">{{ $examStats['upcoming'] }}</div>
            <div class="stat-desc">Future exams</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-warning">
                <x-icon name="o-calendar-days" class="w-8 h-8" />
            </div>
            <div class="stat-title">Today</div>
            <div class="stat-value text-warning">{{ $examStats['today'] }}</div>
            <div class="stat-desc">Today's exams</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-success">
                <x-icon name="o-check-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Completed</div>
            <div class="stat-value text-success">{{ $examStats['completed'] }}</div>
            <div class="stat-desc">Past exams</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-secondary">
                <x-icon name="o-academic-cap" class="w-8 h-8" />
            </div>
            <div class="stat-title">Graded</div>
            <div class="stat-value text-secondary">{{ $examStats['graded'] }}</div>
            <div class="stat-desc">Graded exams</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-error">
                <x-icon name="o-exclamation-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">To Grade</div>
            <div class="stat-value text-error">{{ $examStats['needs_grading'] }}</div>
            <div class="stat-desc">Needs grading</div>
        </div>
    </div>

    <!-- DATE RANGE SELECTOR -->
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div class="flex flex-wrap gap-2">
            <x-button
                label="Today"
                @click="$wire.setPeriod('today')"
                class="{{ $period === 'today' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="This Week"
                @click="$wire.setPeriod('this_week')"
                class="{{ $period === 'this_week' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="Next Week"
                @click="$wire.setPeriod('next_week')"
                class="{{ $period === 'next_week' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="Upcoming"
                @click="$wire.setPeriod('upcoming')"
                class="{{ $period === 'upcoming' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="Past"
                @click="$wire.setPeriod('past')"
                class="{{ $period === 'past' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="All"
                @click="$wire.setPeriod('all')"
                class="{{ $period === 'all' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
        </div>

        <div class="flex items-center gap-2">
            <x-input type="date" wire:model.live="startDate" />
            <span>to</span>
            <x-input type="date" wire:model.live="endDate" />
            <x-button
                label="Apply"
                icon="o-check"
                @click="$wire.setPeriod('custom')"
                class="btn-primary"
                size="sm"
            />
        </div>
    </div>

    <!-- EXAMS TABLE -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('date')">
                            <div class="flex items-center">
                                Date
                                @if ($sortBy['column'] === 'date')
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
                        <th class="cursor-pointer" wire:click="sortBy('title')">
                            <div class="flex items-center">
                                Title
                                @if ($sortBy['column'] === 'title')
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
                        <th>Duration</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($exams as $exam)
                        <tr class="hover">
                            <td>
                                <div class="font-medium">{{ $exam->date->format('d/m/Y') }}</div>
                                @if ($exam->time)
                                    <div class="text-sm text-gray-600">{{ $exam->time }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="flex flex-col">
                                    <span>{{ $exam->subject->name ?? 'Unknown Subject' }}</span>
                                    <span class="text-sm text-gray-600">{{ $exam->subject->code ?? '' }}</span>
                                </div>
                            </td>
                            <td>{{ $exam->title }}</td>
                            <td>
                                <x-badge
                                    label="{{ ucfirst($exam->type) }}"
                                    color="{{ match($exam->type) {
                                        'quiz' => 'info',
                                        'midterm' => 'warning',
                                        'final' => 'error',
                                        'assignment' => 'success',
                                        'project' => 'secondary',
                                        default => 'ghost'
                                    } }}"
                                />
                            </td>
                            <td>{{ $exam->duration }} min</td>
                            <td>
                                @if ($exam->status_data['needs_grading'])
                                    <x-badge label="Needs Grading" color="error" />
                                @elseif ($exam->status_data['is_graded'])
                                    <x-badge label="Graded" color="success" />
                                @elseif ($exam->status_data['is_today'])
                                    <x-badge label="Today" color="warning" />
                                @elseif ($exam->status_data['is_upcoming'])
                                    <x-badge label="Upcoming" color="info" />
                                @elseif ($exam->status_data['is_completed'])
                                    <x-badge label="Completed" color="secondary" />
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button
                                        icon="o-eye"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View Exam Details"
                                        wire:click="viewExam({{ $exam->id }})"
                                    />

                                    @if ($exam->status_data['is_upcoming'])
                                        <x-button
                                            icon="o-pencil-square"
                                            color="primary"
                                            size="sm"
                                            tooltip="Edit Exam"
                                            wire:click="editExam({{ $exam->id }})"
                                        />
                                    @endif

                                    @if ($exam->status_data['is_completed'] && !$exam->status_data['is_graded'])
                                        <x-button
                                            icon="o-academic-cap"
                                            color="error"
                                            size="sm"
                                            tooltip="Grade Exam"
                                            wire:click="gradeExam({{ $exam->id }})"
                                        />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-clipboard-document-list" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No exams found</h3>
                                    <p class="text-gray-500">No exams match your current filters for the selected time period</p>
                                    <x-button
                                        label="Create New Exam"
                                        icon="o-plus"
                                        @click="$wire.createExam()"
                                        class="mt-2 btn-primary"
                                    />
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if ($exams instanceof \Illuminate\Pagination\LengthAwarePaginator)
            <div class="mt-4">
                {{ $exams->links() }}
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
                    placeholder="Title or subject..."
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
                    label="Filter by type"
                    placeholder="All types"
                    :options="[
                        ['label' => 'Quiz', 'value' => 'quiz'],
                        ['label' => 'Midterm', 'value' => 'midterm'],
                        ['label' => 'Final', 'value' => 'final'],
                        ['label' => 'Assignment', 'value' => 'assignment'],
                        ['label' => 'Project', 'value' => 'project']
                    ]"
                    wire:model.live="type"
                    option-label="label"
                    option-value="value"
                />
            </div>

            <div>
                <x-select
                    label="Filter by status"
                    placeholder="All statuses"
                    :options="[
                        ['label' => 'Upcoming', 'value' => 'upcoming'],
                        ['label' => 'Today', 'value' => 'today'],
                        ['label' => 'Completed', 'value' => 'completed'],
                        ['label' => 'Graded', 'value' => 'graded'],
                        ['label' => 'Needs Grading', 'value' => 'ungraded']
                    ]"
                    wire:model.live="status"
                    option-label="label"
                    option-value="value"
                />
            </div>

            <div>
                <x-select
                    label="Time period"
                    :options="[
                        ['label' => 'Today', 'value' => 'today'],
                        ['label' => 'Tomorrow', 'value' => 'tomorrow'],
                        ['label' => 'This Week', 'value' => 'this_week'],
                        ['label' => 'Next Week', 'value' => 'next_week'],
                        ['label' => 'Upcoming', 'value' => 'upcoming'],
                        ['label' => 'Past', 'value' => 'past'],
                        ['label' => 'All', 'value' => 'all'],
                        ['label' => 'Custom', 'value' => 'custom']
                    ]"
                    wire:model.live="period"
                    option-label="label"
                    option-value="value"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[10, 25, 50, 100]"
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
