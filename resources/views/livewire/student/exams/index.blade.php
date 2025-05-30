<?php

use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ChildProfile;
use App\Models\ActivityLog;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Carbon\Carbon;

new #[Title('Exams')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $subject = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $childId = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public string $view = 'upcoming'; // 'upcoming', 'past', 'all'

    // Load data
    public function mount(): void
    {
        // Set default view
        if (empty($this->view)) {
            $this->view = 'upcoming';
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Student accessed exams page',
            Exam::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->subject = '';
        $this->status = '';
        $this->childId = '';
        $this->resetPage();
    }

    // Get the user's child profiles
    private function getChildProfiles()
    {
        // Get the client profile associated with this user
        $clientProfile = Auth::user()->clientProfile;

        if (!$clientProfile) {
            return collect();
        }

        // Get child profiles associated with this parent
        return ChildProfile::where('parent_profile_id', $clientProfile->id)->get();
    }

    // Get available subjects that the student is enrolled in
    public function subjects()
    {
        // Get all child profiles for this user
        $childProfiles = $this->getChildProfiles();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        // Get all subject IDs from exams and exam results
        $examSubjectIds = Exam::whereHas('examResults', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->pluck('subject_id')
            ->unique()
            ->toArray();

        return Subject::whereIn('id', $examSubjectIds)
            ->orderBy('name')
            ->get()
            ->map(function ($subject) {
                return [
                    'id' => $subject->id,
                    'name' => $subject->name
                ];
            });
    }

    // Get children for filtering
    public function children()
    {
        return $this->getChildProfiles()->map(function ($child) {
            return [
                'id' => $child->id,
                'name' => $child->user->name ?? "Child #{$child->id}"
            ];
        });
    }

    // Get available status options
    public function statuses()
    {
        return collect([
            ['id' => 'upcoming', 'name' => 'Upcoming'],
            ['id' => 'completed', 'name' => 'Completed'],
            ['id' => 'in_progress', 'name' => 'In Progress'],
        ]);
    }

    // Change view type
    public function setView(string $view): void
    {
        $this->view = $view;
        $this->resetPage();
    }

    // View exam details
    public function viewExam($examId)
    {
        return redirect()->route('student.exams.show', $examId);
    }

    // View exam result details
    public function viewExamResult($examResultId)
    {
        return redirect()->route('student.exams.result', $examResultId);
    }

    // Get exams with filtering
    public function exams()
    {
        // Get all child profiles for this user
        $childProfiles = $this->getChildProfiles();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        // Start with exam results to find exams the student's children are taking
        $query = Exam::query()
            ->with(['subject', 'examResults' => function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            }])
            ->whereHas('examResults', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->when($this->search, function (Builder $query) {
                $query->where(function($q) {
                    $q->where('title', 'like', '%' . $this->search . '%')
                      ->orWhere('description', 'like', '%' . $this->search . '%')
                      ->orWhereHas('subject', function ($sq) {
                          $sq->where('name', 'like', '%' . $this->search . '%')
                            ->orWhere('code', 'like', '%' . $this->search . '%');
                      });
                });
            })
            ->when($this->subject, function (Builder $query) {
                $query->where('subject_id', $this->subject);
            })
            ->when($this->status, function (Builder $query) {
                if ($this->status === 'upcoming') {
                    $query->where('exam_date', '>=', Carbon::now()->toDateString());
                } else if ($this->status === 'completed') {
                    $query->where('exam_date', '<', Carbon::now()->toDateString());
                } else if ($this->status === 'in_progress') {
                    $query->whereDate('exam_date', Carbon::now()->toDateString());
                }
            })
            ->when($this->childId, function (Builder $query) {
                $query->whereHas('examResults', function($q) {
                    $q->where('child_profile_id', $this->childId);
                });
            });

        // Apply view filter
        if ($this->view === 'upcoming') {
            $query->where('exam_date', '>=', Carbon::now()->toDateString())
                  ->orderBy('exam_date', 'asc');
        } elseif ($this->view === 'past') {
            $query->where('exam_date', '<', Carbon::now()->toDateString())
                  ->orderBy('exam_date', 'desc');
        } else {
            $query->orderBy('exam_date', 'desc');
        }

        return $query->paginate($this->perPage);
    }

    // Get upcoming exams
    public function upcomingExams()
    {
        // Get all child profiles for this user
        $childProfiles = $this->getChildProfiles();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        return Exam::with(['subject', 'examResults' => function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            }])
            ->whereHas('examResults', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->where('exam_date', '>=', Carbon::now()->toDateString())
            ->orderBy('exam_date', 'asc')
            ->limit(5)
            ->get();
    }

    // Get recent exam results
    public function recentExamResults()
    {
        // Get all child profiles for this user
        $childProfiles = $this->getChildProfiles();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        return ExamResult::with(['exam.subject', 'childProfile.user'])
            ->whereIn('child_profile_id', $childProfileIds)
            ->whereNotNull('score')
            ->orderBy('updated_at', 'desc')
            ->limit(5)
            ->get();
    }

    // Get exam statistics
    public function examStats()
    {
        // Get all child profiles for this user
        $childProfiles = $this->getChildProfiles();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        $now = Carbon::now();

        // Count upcoming exams
        $upcomingExams = Exam::whereHas('examResults', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->where('exam_date', '>=', $now->toDateString())
            ->count();

        // Count completed exams with results
        $completedExams = ExamResult::whereIn('child_profile_id', $childProfileIds)
            ->whereNotNull('score')
            ->count();

        // Count today's exams
        $todayExams = Exam::whereHas('examResults', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->whereDate('exam_date', $now->toDateString())
            ->count();

        // Calculate average score
        $averageScore = ExamResult::whereIn('child_profile_id', $childProfileIds)
            ->whereNotNull('score')
            ->avg('score');

        return [
            'upcoming' => $upcomingExams,
            'completed' => $completedExams,
            'today' => $todayExams,
            'average_score' => $averageScore ? round($averageScore, 1) : 0
        ];
    }

    public function with(): array
    {
        return [
            'exams' => $this->exams(),
            'upcomingExams' => $this->upcomingExams(),
            'recentExamResults' => $this->recentExamResults(),
            'subjects' => $this->subjects(),
            'statuses' => $this->statuses(),
            'children' => $this->children(),
            'examStats' => $this->examStats(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Exams & Assessments" separator progress-indicator>
        <x-slot:subtitle>
            View upcoming and past exams
        </x-slot:subtitle>

        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search exams..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <div class="join">
                <x-button
                    icon="o-clock"
                    :class="$view === 'upcoming' ? 'btn-primary' : 'bg-base-300'"
                    class="join-item"
                    wire:click="setView('upcoming')"
                    tooltip="Upcoming Exams"
                />
                <x-button
                    icon="o-check-circle"
                    :class="$view === 'past' ? 'btn-primary' : 'bg-base-300'"
                    class="join-item"
                    wire:click="setView('past')"
                    tooltip="Past Exams"
                />
                <x-button
                    icon="o-list-bullet"
                    :class="$view === 'all' ? 'btn-primary' : 'bg-base-300'"
                    class="join-item"
                    wire:click="setView('all')"
                    tooltip="All Exams"
                />
            </div>

            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$subject, $status, $childId]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- QUICK STATS PANEL -->
    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-4">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-calendar" class="w-8 h-8" />
            </div>
            <div class="stat-title">Upcoming</div>
            <div class="stat-value">{{ $examStats['upcoming'] }}</div>
            <div class="stat-desc">Scheduled exams</div>
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
            <div class="stat-figure text-warning">
                <x-icon name="o-sun" class="w-8 h-8" />
            </div>
            <div class="stat-title">Today</div>
            <div class="stat-value text-warning">{{ $examStats['today'] }}</div>
            <div class="stat-desc">Exams today</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-info">
                <x-icon name="o-academic-cap" class="w-8 h-8" />
            </div>
            <div class="stat-title">Average</div>
            <div class="stat-value text-info">{{ $examStats['average_score'] }}</div>
            <div class="stat-desc">Average score</div>
        </div>
    </div>

    @if($view === 'upcoming' && count($upcomingExams) > 0)
        <!-- UPCOMING EXAMS SECTION -->
        <div class="mb-6">
            <h2 class="mb-4 text-xl font-bold">Upcoming Exams</h2>

            @foreach($upcomingExams as $exam)
                <div class="p-4 mb-4 border rounded-lg {{ Carbon\Carbon::parse($exam->exam_date)->isToday() ? 'border-warning bg-warning bg-opacity-10' : 'border-base-300' }}">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <div class="col-span-1">
                            <div class="flex flex-col items-center justify-center h-full">
                                <div class="text-center">
                                    <div class="text-2xl font-bold">{{ Carbon\Carbon::parse($exam->exam_date)->format('d') }}</div>
                                    <div class="text-lg">{{ Carbon\Carbon::parse($exam->exam_date)->format('M') }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ Carbon\Carbon::parse($exam->exam_date)->format('l') }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-span-1 md:col-span-2">
                            <div>
                                <h3 class="text-lg font-bold">{{ $exam->title }}</h3>
                                <p class="text-sm text-gray-500">{{ $exam->subject->name }}</p>

                                <div class="mt-2">
                                    @if($exam->time)
                                        <div class="flex items-center">
                                            <x-icon name="o-clock" class="w-4 h-4 mr-1 text-gray-500" />
                                            <span class="text-sm">{{ $exam->time }}</span>
                                        </div>
                                    @endif

                                    @if($exam->location)
                                        <div class="flex items-center mt-1">
                                            <x-icon name="o-map-pin" class="w-4 h-4 mr-1 text-gray-500" />
                                            <span class="text-sm">{{ $exam->location }}</span>
                                        </div>
                                    @endif

                                    @if($exam->duration)
                                        <div class="flex items-center mt-1">
                                            <x-icon name="o-clock-counter-clockwise" class="w-4 h-4 mr-1 text-gray-500" />
                                            <span class="text-sm">{{ $exam->duration }} minutes</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="col-span-1">
                            <div class="flex flex-col justify-center h-full gap-2">
                                <x-button
                                    label="View Details"
                                    icon="o-eye"
                                    color="primary"
                                    class="w-full"
                                    wire:click="viewExam({{ $exam->id }})"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- RECENT RESULTS SECTION -->
        @if(count($recentExamResults) > 0)
            <div class="mb-6">
                <h2 class="mb-4 text-xl font-bold">Recent Results</h2>

                <x-card>
                    <div class="overflow-x-auto">
                        <table class="table w-full">
                            <thead>
                                <tr>
                                    <th>Exam</th>
                                    <th>Date</th>
                                    <th>Subject</th>
                                    <th>Child</th>
                                    <th>Score</th>
                                    <th>Grade</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentExamResults as $result)
                                    <tr class="hover">
                                        <td>{{ $result->exam->title }}</td>
                                        <td>{{ Carbon\Carbon::parse($result->exam->exam_date)->format('M d, Y') }}</td>
                                        <td>{{ $result->exam->subject->name }}</td>
                                        <td>{{ $result->childProfile->user->name ?? "Child #{$result->child_profile_id}" }}</td>
                                        <td>{{ $result->score }}/{{ $result->exam->max_score }}</td>
                                        <td>
                                            @if($result->grade)
                                                <x-badge label="{{ $result->grade }}" color="info" />
                                            @else
                                                <span class="text-gray-400">-</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            <x-button
                                                icon="o-eye"
                                                color="secondary"
                                                size="sm"
                                                tooltip="View Result Details"
                                                wire:click="viewExamResult({{ $result->id }})"
                                            />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-card>
            </div>
        @endif
    @else
        <!-- MAIN EXAMS TABLE -->
        <x-card>
            <div class="overflow-x-auto">
                <table class="table w-full table-zebra">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Title</th>
                            <th>Subject</th>
                            <th>Child</th>
                            <th>Status</th>
                            <th>Score</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($exams as $exam)
                            <tr class="hover">
                                <td>
                                    <div class="font-semibold">{{ Carbon\Carbon::parse($exam->exam_date)->format('M d, Y') }}</div>
                                    @if($exam->time)
                                        <div class="text-xs text-gray-500">{{ $exam->time }}</div>
                                    @endif
                                </td>
                                <td>{{ $exam->title }}</td>
                                <td>{{ $exam->subject->name }}</td>
                                <td>
                                    @if(count($exam->examResults) > 1)
                                        <div class="flex items-center">
                                            <span>Multiple</span>
                                            <div class="ml-1 dropdown dropdown-hover dropdown-end">
                                                <label tabindex="0">
                                                    <x-icon name="o-information-circle" class="w-4 h-4 text-gray-500 cursor-help" />
                                                </label>
                                                <div tabindex="0" class="z-50 p-2 shadow dropdown-content menu bg-base-200 rounded-box w-52">
                                                    <ul>
                                                        @foreach($exam->examResults as $result)
                                                            <li class="py-1 text-sm">{{ $result->childProfile->user->name ?? "Child #{$result->child_profile_id}" }}</li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    @elseif(count($exam->examResults) == 1)
                                        {{ $exam->examResults[0]->childProfile->user->name ?? "Child #{$exam->examResults[0]->child_profile_id}" }}
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $examDate = Carbon\Carbon::parse($exam->exam_date);
                                        $today = Carbon\Carbon::today();
                                    @endphp

                                    @if($examDate->isToday())
                                        <x-badge label="Today" color="warning" />
                                    @elseif($examDate->gt($today))
                                        <x-badge label="Upcoming" color="info" />
                                    @elseif($examDate->lt($today))
                                        <x-badge label="Completed" color="success" />
                                    @endif
                                </td>
                                <td>
                                    @if(count($exam->examResults) > 0 && $exam->examResults[0]->score !== null)
                                        <div class="font-medium">
                                            {{ $exam->examResults[0]->score }}/{{ $exam->max_score }}
                                            @if($exam->examResults[0]->grade)
                                                <x-badge label="{{ $exam->examResults[0]->grade }}" color="info" class="ml-1" />
                                            @endif
                                        </div>
                                    @elseif(Carbon\Carbon::parse($exam->exam_date)->lt(Carbon\Carbon::today()))
                                        <span class="text-gray-400">Pending</span>
                                    @else
                                        <span class="text-gray-400">-</span>
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

                                        @if(count($exam->examResults) > 0 && $exam->examResults[0]->score !== null)
                                            <x-button
                                                icon="o-document-chart-bar"
                                                color="primary"
                                                size="sm"
                                                tooltip="View Results"
                                                wire:click="viewExamResult({{ $exam->examResults[0]->id }})"
                                            />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <x-icon name="o-academic-cap" class="w-16 h-16 text-gray-400" />
                                        <h3 class="text-lg font-semibold text-gray-600">No exams found</h3>
                                        <p class="text-gray-500">No exams match your current filters</p>
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
        </x-card>
    @endif

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Exam Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search exams"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Exam title, subject, etc..."
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
                    label="Filter by status"
                    placeholder="All statuses"
                    :options="$statuses"
                    wire:model.live="status"
                    option-label="name"
                    option-value="id"
                    empty-message="No statuses found"
                />
            </div>

            @if(count($children) > 1)
                <div>
                    <x-select
                        label="Filter by child"
                        placeholder="All children"
                        :options="$children"
                        wire:model.live="childId"
                        option-label="name"
                        option-value="id"
                        empty-message="No children found"
                    />
                </div>
            @endif

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
