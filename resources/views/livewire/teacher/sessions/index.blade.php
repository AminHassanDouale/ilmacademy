<?php

use App\Models\Session;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('My Sessions')] class extends Component {
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
    public string $dateFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'start_time', 'direction' => 'desc'];

    // Stats
    public array $stats = [];

    // Filter options
    public array $subjectOptions = [];
    public array $typeOptions = [];
    public array $statusOptions = [];
    public array $dateOptions = [];

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
            'Accessed teacher sessions page',
            Session::class,
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

            // Session type options
            $this->typeOptions = [
                ['id' => '', 'name' => 'All Types'],
                ['id' => 'lecture', 'name' => 'Lecture'],
                ['id' => 'practical', 'name' => 'Practical'],
                ['id' => 'tutorial', 'name' => 'Tutorial'],
                ['id' => 'lab', 'name' => 'Lab'],
                ['id' => 'seminar', 'name' => 'Seminar'],
            ];

            // Status options based on time
            $this->statusOptions = [
                ['id' => '', 'name' => 'All Sessions'],
                ['id' => 'upcoming', 'name' => 'Upcoming'],
                ['id' => 'ongoing', 'name' => 'Ongoing'],
                ['id' => 'completed', 'name' => 'Completed'],
            ];

            // Date filter options
            $this->dateOptions = [
                ['id' => '', 'name' => 'All Dates'],
                ['id' => 'today', 'name' => 'Today'],
                ['id' => 'tomorrow', 'name' => 'Tomorrow'],
                ['id' => 'this_week', 'name' => 'This Week'],
                ['id' => 'next_week', 'name' => 'Next Week'],
                ['id' => 'this_month', 'name' => 'This Month'],
                ['id' => 'next_month', 'name' => 'Next Month'],
            ];

        } catch (\Exception $e) {
            $this->subjectOptions = [['id' => '', 'name' => 'All Subjects']];
            $this->typeOptions = [['id' => '', 'name' => 'All Types']];
            $this->statusOptions = [['id' => '', 'name' => 'All Sessions']];
            $this->dateOptions = [['id' => '', 'name' => 'All Dates']];
        }
    }

    protected function loadStats(): void
    {
        try {
            if (!$this->teacherProfile) {
                $this->stats = [
                    'total_sessions' => 0,
                    'upcoming_sessions' => 0,
                    'ongoing_sessions' => 0,
                    'completed_sessions' => 0,
                    'today_sessions' => 0,
                    'this_week_sessions' => 0,
                ];
                return;
            }

            $now = now();
            $todayStart = $now->copy()->startOfDay();
            $todayEnd = $now->copy()->endOfDay();
            $weekStart = $now->copy()->startOfWeek();
            $weekEnd = $now->copy()->endOfWeek();

            $totalSessions = Session::where('teacher_profile_id', $this->teacherProfile->id)->count();

            $upcomingSessions = Session::where('teacher_profile_id', $this->teacherProfile->id)
                ->where('start_time', '>', $now)
                ->count();

            $ongoingSessions = Session::where('teacher_profile_id', $this->teacherProfile->id)
                ->where('start_time', '<=', $now)
                ->where('end_time', '>=', $now)
                ->count();

            $completedSessions = Session::where('teacher_profile_id', $this->teacherProfile->id)
                ->where('end_time', '<', $now)
                ->count();

            $todaySessions = Session::where('teacher_profile_id', $this->teacherProfile->id)
                ->whereBetween('start_time', [$todayStart, $todayEnd])
                ->count();

            $thisWeekSessions = Session::where('teacher_profile_id', $this->teacherProfile->id)
                ->whereBetween('start_time', [$weekStart, $weekEnd])
                ->count();

            $this->stats = [
                'total_sessions' => $totalSessions,
                'upcoming_sessions' => $upcomingSessions,
                'ongoing_sessions' => $ongoingSessions,
                'completed_sessions' => $completedSessions,
                'today_sessions' => $todaySessions,
                'this_week_sessions' => $thisWeekSessions,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_sessions' => 0,
                'upcoming_sessions' => 0,
                'ongoing_sessions' => 0,
                'completed_sessions' => 0,
                'today_sessions' => 0,
                'this_week_sessions' => 0,
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
        $this->redirect(route('teacher.sessions.create'));
    }

    public function redirectToShow(int $sessionId): void
    {
        $this->redirect(route('teacher.sessions.show', $sessionId));
    }

    public function redirectToEdit(int $sessionId): void
    {
        $this->redirect(route('teacher.sessions.edit', $sessionId));
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

    public function updatedDateFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->subjectFilter = '';
        $this->typeFilter = '';
        $this->statusFilter = '';
        $this->dateFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated sessions
    public function sessions(): LengthAwarePaginator
    {
        if (!$this->teacherProfile) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        $query = Session::query()
            ->with(['subject', 'attendances'])
            ->where('teacher_profile_id', $this->teacherProfile->id);

        // Apply filters
        if ($this->search) {
            $query->whereHas('subject', function (Builder $q) {
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('code', 'like', "%{$this->search}%");
            });
        }

        if ($this->subjectFilter) {
            $query->where('subject_id', $this->subjectFilter);
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        if ($this->statusFilter) {
            $now = now();
            switch ($this->statusFilter) {
                case 'upcoming':
                    $query->where('start_time', '>', $now);
                    break;
                case 'ongoing':
                    $query->where('start_time', '<=', $now)
                          ->where('end_time', '>=', $now);
                    break;
                case 'completed':
                    $query->where('end_time', '<', $now);
                    break;
            }
        }

        if ($this->dateFilter) {
            $now = now();
            switch ($this->dateFilter) {
                case 'today':
                    $query->whereDate('start_time', $now->toDateString());
                    break;
                case 'tomorrow':
                    $query->whereDate('start_time', $now->copy()->addDay()->toDateString());
                    break;
                case 'this_week':
                    $query->whereBetween('start_time', [
                        $now->copy()->startOfWeek(),
                        $now->copy()->endOfWeek()
                    ]);
                    break;
                case 'next_week':
                    $query->whereBetween('start_time', [
                        $now->copy()->addWeek()->startOfWeek(),
                        $now->copy()->addWeek()->endOfWeek()
                    ]);
                    break;
                case 'this_month':
                    $query->whereBetween('start_time', [
                        $now->copy()->startOfMonth(),
                        $now->copy()->endOfMonth()
                    ]);
                    break;
                case 'next_month':
                    $query->whereBetween('start_time', [
                        $now->copy()->addMonth()->startOfMonth(),
                        $now->copy()->addMonth()->endOfMonth()
                    ]);
                    break;
            }
        }

        return $query->orderBy($this->sortBy['column'], $this->sortBy['direction'])
                     ->paginate($this->perPage);
    }

    // Get session status
    public function getSessionStatus(Session $session): array
    {
        $now = now();

        if ($session->start_time > $now) {
            return ['status' => 'upcoming', 'color' => 'bg-blue-100 text-blue-800'];
        } elseif ($session->start_time <= $now && $session->end_time >= $now) {
            return ['status' => 'ongoing', 'color' => 'bg-green-100 text-green-800'];
        } else {
            return ['status' => 'completed', 'color' => 'bg-gray-100 text-gray-600'];
        }
    }

    // Get session type color
    public function getSessionTypeColor(string $type): string
    {
        return match(strtolower($type)) {
            'lecture' => 'bg-blue-100 text-blue-800',
            'practical' => 'bg-green-100 text-green-800',
            'tutorial' => 'bg-purple-100 text-purple-800',
            'lab' => 'bg-orange-100 text-orange-800',
            'seminar' => 'bg-indigo-100 text-indigo-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function with(): array
    {
        return [
            'sessions' => $this->sessions(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="My Sessions" subtitle="Manage your teaching sessions" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search sessions..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$subjectFilter, $typeFilter, $statusFilter, $dateFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="Create Session"
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
                        <x-icon name="o-presentation-chart-line" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_sessions']) }}</div>
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
                        <div class="text-sm text-gray-500">Upcoming</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-green-100 rounded-full">
                        <x-icon name="o-play" class="w-8 h-8 text-green-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['ongoing_sessions']) }}</div>
                        <div class="text-sm text-gray-500">Ongoing</div>
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
                        <div class="text-2xl font-bold text-gray-600">{{ number_format($stats['completed_sessions']) }}</div>
                        <div class="text-sm text-gray-500">Completed</div>
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
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['today_sessions']) }}</div>
                        <div class="text-sm text-gray-500">Today</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-indigo-100 rounded-full">
                        <x-icon name="o-calendar" class="w-8 h-8 text-indigo-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-indigo-600">{{ number_format($stats['this_week_sessions']) }}</div>
                        <div class="text-sm text-gray-500">This Week</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Sessions Table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('start_time')">
                            <div class="flex items-center">
                                Date & Time
                                @if ($sortBy['column'] === 'start_time')
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
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Attendance</th>
                        <th>Link</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sessions as $session)
                        @php
                            $sessionStatus = $this->getSessionStatus($session);
                        @endphp
                        <tr class="hover">
                            <td>
                                <div>
                                    <div class="font-semibold">{{ $session->start_time->format('M d, Y') }}</div>
                                    <div class="text-sm text-gray-500">
                                        {{ $session->start_time->format('g:i A') }}
                                        @if($session->end_time)
                                            - {{ $session->end_time->format('g:i A') }}
                                        @endif
                                    </div>
                                    <div class="text-xs text-gray-400">{{ $session->start_time->diffForHumans() }}</div>
                                </div>
                            </td>
                            <td>
                                <div>
                                    <button
                                        wire:click="redirectToShow({{ $session->id }})"
                                        class="font-semibold text-blue-600 hover:text-blue-800 hover:underline"
                                    >
                                        {{ $session->subject->name }}
                                    </button>
                                    <div class="text-sm text-gray-500">{{ $session->subject->code }}</div>
                                </div>
                            </td>
                            <td>
                                @if($session->type)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getSessionTypeColor($session->type) }}">
                                        {{ ucfirst($session->type) }}
                                    </span>
                                @else
                                    <span class="text-gray-500">-</span>
                                @endif
                            </td>
                            <td>
                                @if($session->start_time && $session->end_time)
                                    <div class="text-sm">
                                        {{ $session->start_time->diffInMinutes($session->end_time) }} min
                                    </div>
                                @else
                                    <span class="text-gray-500">-</span>
                                @endif
                            </td>
                            <td>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $sessionStatus['color'] }}">
                                    {{ ucfirst($sessionStatus['status']) }}
                                </span>
                            </td>
                            <td>
                                @if($session->attendances->count() > 0)
                                    <div class="text-sm">
                                        <span class="font-medium">{{ $session->attendances->count() }}</span>
                                        <span class="text-gray-500">attendees</span>
                                    </div>
                                @else
                                    <span class="text-gray-500">No attendance</span>
                                @endif
                            </td>
                            <td>
                                @if($session->link)
                                    <a
                                        href="{{ $session->link }}"
                                        target="_blank"
                                        class="text-blue-600 hover:text-blue-800"
                                        title="Join session"
                                    >
                                        <x-icon name="o-link" class="w-4 h-4" />
                                    </a>
                                @else
                                    <span class="text-gray-500">-</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <button
                                        wire:click="redirectToShow({{ $session->id }})"
                                        class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                        title="View"
                                    >
                                        👁️
                                    </button>
                                    <button
                                        wire:click="redirectToEdit({{ $session->id }})"
                                        class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                        title="Edit"
                                    >
                                        ✏️
                                    </button>
                                    @if($sessionStatus['status'] === 'upcoming' || $sessionStatus['status'] === 'ongoing')
                                        <a
                                            href="{{ route('teacher.attendance.take', $session->id) }}"
                                            class="p-2 text-green-600 bg-green-100 rounded-md hover:text-green-900 hover:bg-green-200"
                                            title="Take Attendance"
                                        >
                                            📋
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-presentation-chart-line" class="w-20 h-20 text-gray-300" />
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-600">No sessions found</h3>
                                        <p class="mt-1 text-gray-500">
                                            @if($search || $subjectFilter || $typeFilter || $statusFilter || $dateFilter)
                                                No sessions match your current filters.
                                            @else
                                                You haven't created any sessions yet.
                                            @endif
                                        </p>
                                    </div>
                                    @if($search || $subjectFilter || $typeFilter || $statusFilter || $dateFilter)
                                        <x-button
                                            label="Clear Filters"
                                            wire:click="clearFilters"
                                            color="secondary"
                                            size="sm"
                                        />
                                    @else
                                        <x-button
                                            label="Create First Session"
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
            {{ $sessions->links() }}
        </div>

        <!-- Results summary -->
        @if($sessions->count() > 0)
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $sessions->firstItem() ?? 0 }} to {{ $sessions->lastItem() ?? 0 }}
            of {{ $sessions->total() }} sessions
            @if($search || $subjectFilter || $typeFilter || $statusFilter || $dateFilter)
                (filtered from total)
            @endif
        </div>
        @endif
    </x-card>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Filter Sessions" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search sessions"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by subject name or code..."
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
                    placeholder="All sessions"
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
