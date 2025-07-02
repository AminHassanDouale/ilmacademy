<?php

use App\Models\Attendance;
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

new #[Title('Attendance Management')] class extends Component {
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
    public string $statusFilter = '';

    #[Url]
    public string $dateFilter = '';

    #[Url]
    public string $attendanceStatusFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Stats
    public array $stats = [];

    // Filter options
    public array $subjectOptions = [];
    public array $statusOptions = [];
    public array $dateOptions = [];
    public array $attendanceStatusOptions = [];

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
            'Accessed teacher attendance management page',
            Attendance::class,
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

            // Session status options
            $this->statusOptions = [
                ['id' => '', 'name' => 'All Sessions'],
                ['id' => 'upcoming', 'name' => 'Upcoming Sessions'],
                ['id' => 'completed', 'name' => 'Completed Sessions'],
                ['id' => 'with_attendance', 'name' => 'Sessions with Attendance'],
                ['id' => 'without_attendance', 'name' => 'Sessions without Attendance'],
            ];

            // Attendance status options
            $this->attendanceStatusOptions = [
                ['id' => '', 'name' => 'All Statuses'],
                ['id' => 'present', 'name' => 'Present'],
                ['id' => 'absent', 'name' => 'Absent'],
                ['id' => 'late', 'name' => 'Late'],
                ['id' => 'excused', 'name' => 'Excused'],
            ];

            // Date filter options
            $this->dateOptions = [
                ['id' => '', 'name' => 'All Dates'],
                ['id' => 'today', 'name' => 'Today'],
                ['id' => 'yesterday', 'name' => 'Yesterday'],
                ['id' => 'this_week', 'name' => 'This Week'],
                ['id' => 'last_week', 'name' => 'Last Week'],
                ['id' => 'this_month', 'name' => 'This Month'],
                ['id' => 'last_month', 'name' => 'Last Month'],
            ];

        } catch (\Exception $e) {
            $this->subjectOptions = [['id' => '', 'name' => 'All Subjects']];
            $this->statusOptions = [['id' => '', 'name' => 'All Sessions']];
            $this->attendanceStatusOptions = [['id' => '', 'name' => 'All Statuses']];
            $this->dateOptions = [['id' => '', 'name' => 'All Dates']];
        }
    }

    protected function loadStats(): void
    {
        try {
            if (!$this->teacherProfile) {
                $this->stats = [
                    'total_sessions' => 0,
                    'sessions_with_attendance' => 0,
                    'total_attendance_records' => 0,
                    'average_attendance_rate' => 0,
                    'present_count' => 0,
                    'absent_count' => 0,
                ];
                return;
            }

            $totalSessions = Session::where('teacher_profile_id', $this->teacherProfile->id)->count();

            $sessionsWithAttendance = Session::where('teacher_profile_id', $this->teacherProfile->id)
                ->whereHas('attendances')
                ->count();

            $totalAttendanceRecords = Attendance::whereHas('session', function ($query) {
                $query->where('teacher_profile_id', $this->teacherProfile->id);
            })->count();

            $presentCount = Attendance::whereHas('session', function ($query) {
                $query->where('teacher_profile_id', $this->teacherProfile->id);
            })->where('status', 'present')->count();

            $absentCount = Attendance::whereHas('session', function ($query) {
                $query->where('teacher_profile_id', $this->teacherProfile->id);
            })->where('status', 'absent')->count();

            $lateCount = Attendance::whereHas('session', function ($query) {
                $query->where('teacher_profile_id', $this->teacherProfile->id);
            })->where('status', 'late')->count();

            // Calculate average attendance rate
            $averageAttendanceRate = 0;
            if ($totalAttendanceRecords > 0) {
                $attendingCount = $presentCount + $lateCount;
                $averageAttendanceRate = round(($attendingCount / $totalAttendanceRecords) * 100, 1);
            }

            $this->stats = [
                'total_sessions' => $totalSessions,
                'sessions_with_attendance' => $sessionsWithAttendance,
                'total_attendance_records' => $totalAttendanceRecords,
                'average_attendance_rate' => $averageAttendanceRate,
                'present_count' => $presentCount,
                'absent_count' => $absentCount,
                'late_count' => $lateCount,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_sessions' => 0,
                'sessions_with_attendance' => 0,
                'total_attendance_records' => 0,
                'average_attendance_rate' => 0,
                'present_count' => 0,
                'absent_count' => 0,
                'late_count' => 0,
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
    public function redirectToTakeAttendance(int $sessionId): void
    {
        $this->redirect(route('teacher.attendance.take', $sessionId));
    }

    public function redirectToSessionShow(int $sessionId): void
    {
        $this->redirect(route('teacher.sessions.show', $sessionId));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSubjectFilter(): void
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

    public function updatedAttendanceStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->subjectFilter = '';
        $this->statusFilter = '';
        $this->dateFilter = '';
        $this->attendanceStatusFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated sessions with attendance data
    public function sessions(): LengthAwarePaginator
    {
        if (!$this->teacherProfile) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        $query = Session::query()
            ->with(['subject', 'attendances', 'attendances.childProfile'])
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

        if ($this->statusFilter) {
            switch ($this->statusFilter) {
                case 'upcoming':
                    $query->where('start_time', '>', now());
                    break;
                case 'completed':
                    $query->where('end_time', '<', now());
                    break;
                case 'with_attendance':
                    $query->whereHas('attendances');
                    break;
                case 'without_attendance':
                    $query->whereDoesntHave('attendances');
                    break;
            }
        }

        if ($this->attendanceStatusFilter) {
            $query->whereHas('attendances', function (Builder $q) {
                $q->where('status', $this->attendanceStatusFilter);
            });
        }

        if ($this->dateFilter) {
            $now = now();
            switch ($this->dateFilter) {
                case 'today':
                    $query->whereDate('start_time', $now->toDateString());
                    break;
                case 'yesterday':
                    $query->whereDate('start_time', $now->copy()->subDay()->toDateString());
                    break;
                case 'this_week':
                    $query->whereBetween('start_time', [
                        $now->copy()->startOfWeek(),
                        $now->copy()->endOfWeek()
                    ]);
                    break;
                case 'last_week':
                    $query->whereBetween('start_time', [
                        $now->copy()->subWeek()->startOfWeek(),
                        $now->copy()->subWeek()->endOfWeek()
                    ]);
                    break;
                case 'this_month':
                    $query->whereBetween('start_time', [
                        $now->copy()->startOfMonth(),
                        $now->copy()->endOfMonth()
                    ]);
                    break;
                case 'last_month':
                    $query->whereBetween('start_time', [
                        $now->copy()->subMonth()->startOfMonth(),
                        $now->copy()->subMonth()->endOfMonth()
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

    // Get attendance statistics for a session
    public function getAttendanceStats(Session $session): array
    {
        $attendances = $session->attendances;
        $total = $attendances->count();

        if ($total === 0) {
            return [
                'total' => 0,
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'excused' => 0,
                'rate' => 0
            ];
        }

        $present = $attendances->where('status', 'present')->count();
        $absent = $attendances->where('status', 'absent')->count();
        $late = $attendances->where('status', 'late')->count();
        $excused = $attendances->where('status', 'excused')->count();

        $attendingCount = $present + $late;
        $rate = round(($attendingCount / $total) * 100, 1);

        return [
            'total' => $total,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'excused' => $excused,
            'rate' => $rate
        ];
    }

    // Get attendance status color
    public function getAttendanceStatusColor(string $status): string
    {
        return match(strtolower($status)) {
            'present' => 'bg-green-100 text-green-800',
            'absent' => 'bg-red-100 text-red-800',
            'late' => 'bg-yellow-100 text-yellow-800',
            'excused' => 'bg-blue-100 text-blue-800',
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
    <x-header title="Attendance Management" subtitle="Track and manage student attendance" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search sessions..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$subjectFilter, $statusFilter, $dateFilter, $attendanceStatusFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-7">
        <x-card class="lg:col-span-2">
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-blue-100 rounded-full">
                        <x-icon name="o-presentation-chart-line" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_sessions']) }}</div>
                        <div class="text-sm text-gray-500">Total Sessions</div>
                        <div class="text-xs text-gray-400">{{ $stats['sessions_with_attendance'] }} with attendance</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-clipboard-document-check" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ number_format($stats['total_attendance_records']) }}</div>
                        <div class="text-sm text-gray-500">Records</div>
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
                        <div class="text-2xl font-bold text-green-600">{{ $stats['average_attendance_rate'] }}%</div>
                        <div class="text-sm text-gray-500">Avg Rate</div>
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
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['present_count']) }}</div>
                        <div class="text-sm text-gray-500">Present</div>
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
                        <div class="text-2xl font-bold text-yellow-600">{{ number_format($stats['late_count']) }}</div>
                        <div class="text-sm text-gray-500">Late</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-red-100 rounded-full">
                        <x-icon name="o-x-circle" class="w-8 h-8 text-red-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-red-600">{{ number_format($stats['absent_count']) }}</div>
                        <div class="text-sm text-gray-500">Absent</div>
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
                                Session Details
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
                        <th>Status</th>
                        <th>Attendance Summary</th>
                        <th>Attendance Rate</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sessions as $session)
                        @php
                            $sessionStatus = $this->getSessionStatus($session);
                            $attendanceStats = $this->getAttendanceStats($session);
                        @endphp
                        <tr class="hover">
                            <td>
                                <div>
                                    <button
                                        wire:click="redirectToSessionShow({{ $session->id }})"
                                        class="font-semibold text-blue-600 hover:text-blue-800 hover:underline"
                                    >
                                        Session #{{ $session->id }}
                                    </button>
                                    <div class="text-sm text-gray-500">
                                        {{ $session->start_time->format('M d, Y \a\t g:i A') }}
                                        @if($session->end_time)
                                            - {{ $session->end_time->format('g:i A') }}
                                        @endif
                                    </div>
                                    @if($session->type)
                                        <div class="text-xs text-gray-400">{{ ucfirst($session->type) }}</div>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div>
                                    <div class="font-medium">{{ $session->subject->name }}</div>
                                    <div class="text-sm text-gray-500">{{ $session->subject->code }}</div>
                                </div>
                            </td>
                            <td>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $sessionStatus['color'] }}">
                                    {{ ucfirst($sessionStatus['status']) }}
                                </span>
                                @if($attendanceStats['total'] > 0)
                                    <div class="mt-1">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            Attendance Taken
                                        </span>
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if($attendanceStats['total'] > 0)
                                    <div class="text-sm">
                                        <div class="flex space-x-4">
                                            <span class="font-medium text-green-600">{{ $attendanceStats['present'] }}P</span>
                                            <span class="font-medium text-yellow-600">{{ $attendanceStats['late'] }}L</span>
                                            <span class="font-medium text-red-600">{{ $attendanceStats['absent'] }}A</span>
                                            @if($attendanceStats['excused'] > 0)
                                                <span class="font-medium text-blue-600">{{ $attendanceStats['excused'] }}E</span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-gray-500">Total: {{ $attendanceStats['total'] }} students</div>
                                    </div>
                                @else
                                    <span class="text-sm text-gray-500">No attendance</span>
                                @endif
                            </td>
                            <td>
                                @if($attendanceStats['total'] > 0)
                                    <div class="flex items-center">
                                        <div class="w-16 h-2 mr-2 bg-gray-200 rounded-full">
                                            <div
                                                class="h-2 rounded-full {{ $attendanceStats['rate'] >= 80 ? 'bg-green-600' : ($attendanceStats['rate'] >= 60 ? 'bg-yellow-600' : 'bg-red-600') }}"
                                                style="width: {{ $attendanceStats['rate'] }}%"
                                            ></div>
                                        </div>
                                        <span class="text-sm font-medium">{{ $attendanceStats['rate'] }}%</span>
                                    </div>
                                @else
                                    <span class="text-gray-500">-</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <button
                                        wire:click="redirectToSessionShow({{ $session->id }})"
                                        class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                        title="View Session"
                                    >
                                        👁️
                                    </button>
                                    @if($sessionStatus['status'] === 'upcoming' || $sessionStatus['status'] === 'ongoing' || $attendanceStats['total'] > 0)
                                        <button
                                            wire:click="redirectToTakeAttendance({{ $session->id }})"
                                            class="p-2 text-green-600 bg-green-100 rounded-md hover:text-green-900 hover:bg-green-200"
                                            title="{{ $attendanceStats['total'] > 0 ? 'Edit Attendance' : 'Take Attendance' }}"
                                        >
                                            📋
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-clipboard-document-check" class="w-20 h-20 text-gray-300" />
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-600">No sessions found</h3>
                                        <p class="mt-1 text-gray-500">
                                            @if($search || $subjectFilter || $statusFilter || $dateFilter || $attendanceStatusFilter)
                                                No sessions match your current filters.
                                            @else
                                                You haven't created any sessions yet.
                                            @endif
                                        </p>
                                    </div>
                                    @if($search || $subjectFilter || $statusFilter || $dateFilter || $attendanceStatusFilter)
                                        <x-button
                                            label="Clear Filters"
                                            wire:click="clearFilters"
                                            color="secondary"
                                            size="sm"
                                        />
                                    @else
                                        <x-button
                                            label="Create Session"
                                            icon="o-plus"
                                            link="{{ route('teacher.sessions.create') }}"
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
            @if($search || $subjectFilter || $statusFilter || $dateFilter || $attendanceStatusFilter)
                (filtered from total)
            @endif
        </div>
        @endif
    </x-card>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Filter Attendance" position="right" class="p-4">
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
                    label="Filter by session status"
                    :options="$statusOptions"
                    wire:model.live="statusFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All sessions"
                />
            </div>

            <div>
                <x-select
                    label="Filter by attendance status"
                    :options="$attendanceStatusOptions"
                    wire:model.live="attendanceStatusFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All statuses"
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
