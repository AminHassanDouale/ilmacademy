<?php

use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\Session;
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

new #[Title('My Subjects')] class extends Component {
    use WithPagination;
    use Toast;

    // Current teacher profile
    public ?TeacherProfile $teacherProfile = null;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $curriculumFilter = '';

    #[Url]
    public string $levelFilter = '';

    #[Url]
    public int $perPage = 12;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'name', 'direction' => 'asc'];

    // Stats
    public array $stats = [];

    // Filter options
    public array $curriculumOptions = [];
    public array $levelOptions = [];

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
            'Accessed teacher subjects page',
            Subject::class,
            null,
            ['ip' => request()->ip()]
        );

        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        try {
            // Get curricula from teacher's subjects
            $curricula = $this->teacherProfile->subjects()
                ->with('curriculum')
                ->get()
                ->pluck('curriculum')
                ->filter()
                ->unique('id')
                ->values();

            $this->curriculumOptions = [
                ['id' => '', 'name' => 'All Curricula'],
                ...$curricula->map(fn($curriculum) => [
                    'id' => $curriculum->id,
                    'name' => $curriculum->name
                ])->toArray()
            ];

            // Get levels from teacher's subjects
            $levels = $this->teacherProfile->subjects()
                ->whereNotNull('level')
                ->distinct('level')
                ->pluck('level')
                ->filter()
                ->sort()
                ->values();

            $this->levelOptions = [
                ['id' => '', 'name' => 'All Levels'],
                ...$levels->map(fn($level) => [
                    'id' => $level,
                    'name' => "Level {$level}"
                ])->toArray()
            ];

        } catch (\Exception $e) {
            $this->curriculumOptions = [['id' => '', 'name' => 'All Curricula']];
            $this->levelOptions = [['id' => '', 'name' => 'All Levels']];
        }
    }

    protected function loadStats(): void
    {
        try {
            if (!$this->teacherProfile) {
                $this->stats = [
                    'total_subjects' => 0,
                    'total_sessions' => 0,
                    'total_exams' => 0,
                    'upcoming_sessions' => 0,
                    'recent_sessions' => 0,
                    'pending_exams' => 0,
                ];
                return;
            }

            $totalSubjects = $this->teacherProfile->subjects()->count();
            $totalSessions = Session::where('teacher_profile_id', $this->teacherProfile->id)->count();
            $totalExams = Exam::where('teacher_profile_id', $this->teacherProfile->id)->count();

            $upcomingSessions = Session::where('teacher_profile_id', $this->teacherProfile->id)
                ->where('start_time', '>', now())
                ->count();

            $recentSessions = Session::where('teacher_profile_id', $this->teacherProfile->id)
                ->where('start_time', '>=', now()->subDays(7))
                ->where('start_time', '<=', now())
                ->count();

            $pendingExams = Exam::where('teacher_profile_id', $this->teacherProfile->id)
                ->where('exam_date', '>', now())
                ->count();

            $this->stats = [
                'total_subjects' => $totalSubjects,
                'total_sessions' => $totalSessions,
                'total_exams' => $totalExams,
                'upcoming_sessions' => $upcomingSessions,
                'recent_sessions' => $recentSessions,
                'pending_exams' => $pendingExams,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_subjects' => 0,
                'total_sessions' => 0,
                'total_exams' => 0,
                'upcoming_sessions' => 0,
                'recent_sessions' => 0,
                'pending_exams' => 0,
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

    // Redirect to subject show page
    public function redirectToShow(int $subjectId): void
    {
        $this->redirect(route('teacher.subjects.show', $subjectId));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCurriculumFilter(): void
    {
        $this->resetPage();
    }

    public function updatedLevelFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->curriculumFilter = '';
        $this->levelFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated subjects
    public function subjects(): LengthAwarePaginator
    {
        if (!$this->teacherProfile) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        return $this->teacherProfile->subjects()
            ->with(['curriculum', 'sessions', 'exams'])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                      ->orWhere('code', 'like', "%{$this->search}%");
                });
            })
            ->when($this->curriculumFilter, function (Builder $query) {
                $query->where('curriculum_id', $this->curriculumFilter);
            })
            ->when($this->levelFilter, function (Builder $query) {
                $query->where('level', $this->levelFilter);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Get upcoming sessions for a subject
    public function getUpcomingSessions(Subject $subject): int
    {
        return $subject->sessions()
            ->where('teacher_profile_id', $this->teacherProfile->id)
            ->where('start_time', '>', now())
            ->count();
    }

    // Get recent sessions for a subject
    public function getRecentSessions(Subject $subject): int
    {
        return $subject->sessions()
            ->where('teacher_profile_id', $this->teacherProfile->id)
            ->where('start_time', '>=', now()->subDays(7))
            ->where('start_time', '<=', now())
            ->count();
    }

    // Get upcoming exams for a subject
    public function getUpcomingExams(Subject $subject): int
    {
        return $subject->exams()
            ->where('teacher_profile_id', $this->teacherProfile->id)
            ->where('exam_date', '>', now())
            ->count();
    }

    public function with(): array
    {
        return [
            'subjects' => $this->subjects(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="My Subjects" subtitle="Manage subjects you teach" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search subjects..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$curriculumFilter, $levelFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-6">
        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-blue-100 rounded-full">
                        <x-icon name="o-academic-cap" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_subjects']) }}</div>
                        <div class="text-sm text-gray-500">My Subjects</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-green-100 rounded-full">
                        <x-icon name="o-presentation-chart-line" class="w-8 h-8 text-green-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['total_sessions']) }}</div>
                        <div class="text-sm text-gray-500">Total Sessions</div>
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
                        <div class="text-2xl font-bold text-orange-600">{{ number_format($stats['upcoming_sessions']) }}</div>
                        <div class="text-sm text-gray-500">Upcoming Sessions</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-document-text" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['total_exams']) }}</div>
                        <div class="text-sm text-gray-500">Total Exams</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-red-100 rounded-full">
                        <x-icon name="o-calendar-days" class="w-8 h-8 text-red-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-red-600">{{ number_format($stats['pending_exams']) }}</div>
                        <div class="text-sm text-gray-500">Upcoming Exams</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-indigo-100 rounded-full">
                        <x-icon name="o-chart-bar" class="w-8 h-8 text-indigo-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-indigo-600">{{ number_format($stats['recent_sessions']) }}</div>
                        <div class="text-sm text-gray-500">Recent Sessions</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Subjects Grid -->
    @if($subjects->count() > 0)
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach($subjects as $subject)
                <x-card class="hover:shadow-lg transition-shadow duration-200">
                    <div class="p-6">
                        <!-- Subject Header -->
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex-1">
                                <button
                                    wire:click="redirectToShow({{ $subject->id }})"
                                    class="text-lg font-semibold text-blue-600 hover:text-blue-800 hover:underline text-left"
                                >
                                    {{ $subject->name }}
                                </button>
                                <div class="text-sm text-gray-500">Code: {{ $subject->code }}</div>
                                @if($subject->level)
                                    <div class="text-xs text-gray-400">Level {{ $subject->level }}</div>
                                @endif
                            </div>
                            <div class="flex-shrink-0">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    {{ $subject->curriculum ? $subject->curriculum->name : 'No Curriculum' }}
                                </span>
                            </div>
                        </div>

                        <!-- Subject Stats -->
                        <div class="grid grid-cols-3 gap-4 mb-4">
                            <div class="text-center">
                                <div class="text-lg font-semibold text-green-600">{{ $this->getUpcomingSessions($subject) }}</div>
                                <div class="text-xs text-gray-500">Upcoming Sessions</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-semibold text-orange-600">{{ $this->getRecentSessions($subject) }}</div>
                                <div class="text-xs text-gray-500">Recent Sessions</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-semibold text-purple-600">{{ $this->getUpcomingExams($subject) }}</div>
                                <div class="text-xs text-gray-500">Upcoming Exams</div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="flex gap-2">
                            <x-button
                                label="View Details"
                                icon="o-eye"
                                wire:click="redirectToShow({{ $subject->id }})"
                                class="flex-1 btn-sm btn-outline"
                            />
                            <x-button
                                label="Sessions"
                                icon="o-presentation-chart-line"
                                link="{{ route('teacher.sessions.index', ['subject' => $subject->id]) }}"
                                class="flex-1 btn-sm btn-primary"
                            />
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="mt-8">
            {{ $subjects->links() }}
        </div>

        <!-- Results summary -->
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $subjects->firstItem() ?? 0 }} to {{ $subjects->lastItem() ?? 0 }}
            of {{ $subjects->total() }} subjects
            @if($search || $curriculumFilter || $levelFilter)
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
                        <h3 class="text-lg font-semibold text-gray-600">No subjects found</h3>
                        <p class="mt-1 text-gray-500">
                            @if($search || $curriculumFilter || $levelFilter)
                                No subjects match your current filters.
                            @else
                                You haven't been assigned any subjects yet.
                            @endif
                        </p>
                    </div>
                    @if($search || $curriculumFilter || $levelFilter)
                        <x-button
                            label="Clear Filters"
                            wire:click="clearFilters"
                            color="secondary"
                            size="sm"
                        />
                    @else
                        <div class="text-center">
                            <p class="mb-4 text-sm text-gray-600">Contact your administrator to get assigned to subjects, or update your profile to select subjects you can teach.</p>
                            <x-button
                                label="Update Profile"
                                icon="o-user"
                                link="{{ route('teacher.profile.edit') }}"
                                color="primary"
                            />
                        </div>
                    @endif
                </div>
            </div>
        </x-card>
    @endif

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Filter Subjects" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search subjects"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by name or code..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by curriculum"
                    :options="$curriculumOptions"
                    wire:model.live="curriculumFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All curricula"
                />
            </div>

            <div>
                <x-select
                    label="Filter by level"
                    :options="$levelOptions"
                    wire:model.live="levelFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All levels"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[
                        ['id' => 6, 'name' => '6 per page'],
                        ['id' => 12, 'name' => '12 per page'],
                        ['id' => 24, 'name' => '24 per page'],
                        ['id' => 48, 'name' => '48 per page']
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
