<?php

use App\Models\Session;
use App\Models\Subject;
use App\Models\Attendance;
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

new #[Title('Attendance Records')] class extends Component {
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
    public string $period = 'month';

    #[Url]
    public string $startDate = '';

    #[Url]
    public string $endDate = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'date', 'direction' => 'desc'];

    public function mount(): void
    {
        // Default dates if not set
        if (empty($this->startDate)) {
            $this->startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
        }

        if (empty($this->endDate)) {
            $this->endDate = Carbon::now()->format('Y-m-d');
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher accessed attendance index page',
            Attendance::class,
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
            case 'yesterday':
                $this->startDate = Carbon::yesterday()->format('Y-m-d');
                $this->endDate = Carbon::yesterday()->format('Y-m-d');
                break;
            case 'week':
                $this->startDate = Carbon::now()->startOfWeek()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfWeek()->format('Y-m-d');
                break;
            case 'month':
                $this->startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfMonth()->format('Y-m-d');
                break;
            case 'quarter':
                $this->startDate = Carbon::now()->startOfQuarter()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfQuarter()->format('Y-m-d');
                break;
            case 'semester':
                $this->startDate = Carbon::now()->subMonths(6)->format('Y-m-d');
                $this->endDate = Carbon::now()->format('Y-m-d');
                break;
            case 'year':
                $this->startDate = Carbon::now()->startOfYear()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfYear()->format('Y-m-d');
                break;
            case 'all':
                $this->startDate = Carbon::now()->subYears(5)->format('Y-m-d');
                $this->endDate = Carbon::now()->format('Y-m-d');
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
        $this->status = '';
        $this->period = 'month';
        $this->startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
        $this->endDate = Carbon::now()->format('Y-m-d');
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

            // Fallback: Get subjects from sessions
            return Subject::whereHas('sessions', function ($query) use ($teacherProfile) {
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

    // View session details
    public function viewSession($sessionId)
    {
        return redirect()->route('teacher.sessions.show', $sessionId);
    }

    // Mark attendance for a session
    public function markAttendance($sessionId)
    {
        return redirect()->route('teacher.sessions.attendance', $sessionId);
    }

    // View student profile
    public function viewStudent($studentId)
    {
        return redirect()->route('teacher.students.show', $studentId);
    }

    // Get sessions with attendance data
    public function sessions()
    {
        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            return [];
        }

        try {
            $query = Session::where('teacher_profile_id', $teacherProfile->id)
                ->with(['subject', 'subject.curriculum'])
                ->whereBetween('date', [$this->startDate, $this->endDate])
                ->where(function ($query) {
                    $query->where('status', '!=', 'cancelled')
                          ->orWhereNull('status');
                })
                ->when($this->search, function (Builder $query) {
                    $query->where(function($q) {
                        $q->where('topic', 'like', '%' . $this->search . '%')
                          ->orWhereHas('subject', function ($subquery) {
                              $subquery->where('name', 'like', '%' . $this->search . '%')
                                      ->orWhere('code', 'like', '%' . $this->search . '%');
                          });
                    });
                })
                ->when($this->subject, function (Builder $query) {
                    $query->where('subject_id', $this->subject);
                })
                ->when($this->status, function (Builder $query) {
                    // Filter by session completion status
                    $now = Carbon::now();

                    if ($this->status === 'completed') {
                        $query->where(function($q) use ($now) {
                            $q->where('date', '<', $now->format('Y-m-d'))
                              ->orWhere(function($q2) use ($now) {
                                  $q2->where('date', '=', $now->format('Y-m-d'))
                                     ->where('end_time', '<', $now->format('H:i:s'));
                              });
                        });
                    } elseif ($this->status === 'upcoming') {
                        $query->where(function($q) use ($now) {
                            $q->where('date', '>', $now->format('Y-m-d'))
                              ->orWhere(function($q2) use ($now) {
                                  $q2->where('date', '=', $now->format('Y-m-d'))
                                     ->where('start_time', '>', $now->format('H:i:s'));
                              });
                        });
                    } elseif ($this->status === 'in_progress') {
                        $query->where('date', '=', $now->format('Y-m-d'))
                              ->where('start_time', '<=', $now->format('H:i:s'))
                              ->where('end_time', '>=', $now->format('H:i:s'));
                    }
                });

            // Get sessions
            $sessions = $query->orderBy($this->sortBy['column'], $this->sortBy['direction'])
                ->paginate($this->perPage);

            // Load attendance data for each session
            foreach ($sessions as $session) {
                try {
                    // Get enrollment count for the subject
                    $enrollmentCount = 0;
                    if (method_exists($session->subject, 'enrolledStudents')) {
                        $enrollmentCount = $session->subject->enrolledStudents()->count();
                    }

                    // Get attendance count
                    $attendanceCount = 0;
                    $absentCount = 0;
                    $lateCount = 0;
                    $excusedCount = 0;

                    if (method_exists($session, 'attendances')) {
                        $attendanceCount = $session->attendances()->where('status', 'present')->count();
                        $absentCount = $session->attendances()->where('status', 'absent')->count();
                        $lateCount = $session->attendances()->where('status', 'late')->count();
                        $excusedCount = $session->attendances()->where('status', 'excused')->count();
                    } else {
                        // Try direct query if relationship not defined
                        $attendanceCount = Attendance::where('session_id', $session->id)
                            ->where('status', 'present')
                            ->count();
                        $absentCount = Attendance::where('session_id', $session->id)
                            ->where('status', 'absent')
                            ->count();
                        $lateCount = Attendance::where('session_id', $session->id)
                            ->where('status', 'late')
                            ->count();
                        $excusedCount = Attendance::where('session_id', $session->id)
                            ->where('status', 'excused')
                            ->count();
                    }

                    // Calculate percentage
                    $attendancePercentage = $enrollmentCount > 0
                        ? round((($attendanceCount + $lateCount) / $enrollmentCount) * 100)
                        : 0;

                    // Add data to session
                    $session->attendance_data = [
                        'enrollment_count' => $enrollmentCount,
                        'attendance_count' => $attendanceCount,
                        'absent_count' => $absentCount,
                        'late_count' => $lateCount,
                        'excused_count' => $excusedCount,
                        'attendance_percentage' => $attendancePercentage,
                        'is_taken' => ($attendanceCount + $absentCount + $lateCount + $excusedCount) > 0,
                    ];

                    // Check if session is upcoming, in progress, or completed
                    $now = Carbon::now();
                    $sessionDate = Carbon::parse($session->date);
                    $startTime = Carbon::parse($session->date . ' ' . $session->start_time);
                    $endTime = Carbon::parse($session->date . ' ' . $session->end_time);

                    $session->status_data = [
                        'is_upcoming' => $startTime->isFuture(),
                        'is_in_progress' => $startTime->isPast() && $endTime->isFuture(),
                        'is_completed' => $endTime->isPast(),
                    ];
                } catch (\Exception $e) {
                    // Log error but continue
                    ActivityLog::log(
                        Auth::id(),
                        'error',
                        'Error loading attendance data: ' . $e->getMessage(),
                        Session::class,
                        $session->id,
                        ['ip' => request()->ip()]
                    );

                    // Set default values
                    $session->attendance_data = [
                        'enrollment_count' => 0,
                        'attendance_count' => 0,
                        'absent_count' => 0,
                        'late_count' => 0,
                        'excused_count' => 0,
                        'attendance_percentage' => 0,
                        'is_taken' => false,
                    ];

                    $session->status_data = [
                        'is_upcoming' => false,
                        'is_in_progress' => false,
                        'is_completed' => true,
                    ];
                }
            }

            return $sessions;
        } catch (\Exception $e) {
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading sessions: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip()]
            );

            return [];
        }
    }

    // Get attendance statistics
    public function attendanceStats()
    {
        $teacherProfile = Auth::user()->teacherProfile;

        if (!$teacherProfile) {
            return [
                'total_sessions' => 0,
                'attendance_marked' => 0,
                'average_attendance' => 0,
                'total_students' => 0,
                'attendance_rate' => 0,
            ];
        }

        try {
            // Get sessions in date range
            $sessionIds = Session::where('teacher_profile_id', $teacherProfile->id)
                ->whereBetween('date', [$this->startDate, $this->endDate])
                ->where(function ($query) {
                    $query->where('status', '!=', 'cancelled')
                          ->orWhereNull('status');
                })
                ->when($this->subject, function (Builder $query) {
                    $query->where('subject_id', $this->subject);
                })
                ->pluck('id');

            // Total sessions
            $totalSessions = count($sessionIds);

            // Sessions with attendance marked
            $attendanceMarked = 0;
            if ($totalSessions > 0) {
                $attendanceMarked = Attendance::whereIn('session_id', $sessionIds)
                    ->distinct('session_id')
                    ->count('session_id');
            }

            // Attendance percentage across all sessions
            $totalStudents = 0;
            $presentStudents = 0;
            $lateStudents = 0;

            if ($totalSessions > 0) {
                // Get unique student count across all sessions (based on enrolled students)
                $subjectIds = Session::whereIn('id', $sessionIds)
                    ->distinct('subject_id')
                    ->pluck('subject_id');

                // Try to get enrolled students count
                foreach ($subjectIds as $subjectId) {
                    $subject = Subject::find($subjectId);
                    if ($subject && method_exists($subject, 'enrolledStudents')) {
                        $totalStudents += $subject->enrolledStudents()->count();
                    }
                }

                // Get attendance counts
                $presentStudents = Attendance::whereIn('session_id', $sessionIds)
                    ->where('status', 'present')
                    ->count();

                $lateStudents = Attendance::whereIn('session_id', $sessionIds)
                    ->where('status', 'late')
                    ->count();
            }

            // Calculate average attendance rate
            $attendanceRate = $totalStudents > 0
                ? round((($presentStudents + $lateStudents) / $totalStudents) * 100)
                : 0;

            // Calculate average attendance per session
            $averageAttendance = $totalSessions > 0 && $totalStudents > 0
                ? round(($presentStudents + $lateStudents) / $totalSessions)
                : 0;

            return [
                'total_sessions' => $totalSessions,
                'attendance_marked' => $attendanceMarked,
                'average_attendance' => $averageAttendance,
                'total_students' => $totalStudents,
                'attendance_rate' => $attendanceRate,
            ];
        } catch (\Exception $e) {
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error calculating attendance stats: ' . $e->getMessage(),
                TeacherProfile::class,
                $teacherProfile->id,
                ['ip' => request()->ip()]
            );

            return [
                'total_sessions' => 0,
                'attendance_marked' => 0,
                'average_attendance' => 0,
                'total_students' => 0,
                'attendance_rate' => 0,
            ];
        }
    }

    public function with(): array
    {
        return [
            'sessions' => $this->sessions(),
            'subjects' => $this->subjects(),
            'attendanceStats' => $this->attendanceStats(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Attendance Records" separator progress-indicator>
        <x-slot:subtitle>
            View and manage student attendance for your sessions
        </x-slot:subtitle>

        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search sessions..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$subject, $status, $period !== 'month']))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- QUICK STATS PANEL -->
    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-5">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-calendar" class="w-8 h-8" />
            </div>
            <div class="stat-title">Sessions</div>
            <div class="stat-value">{{ $attendanceStats['total_sessions'] }}</div>
            <div class="stat-desc">Total sessions</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-info">
                <x-icon name="o-clipboard-document-check" class="w-8 h-8" />
            </div>
            <div class="stat-title">Marked</div>
            <div class="stat-value text-info">{{ $attendanceStats['attendance_marked'] }}</div>
            <div class="stat-desc">Attendance recorded</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-warning">
                <x-icon name="o-users" class="w-8 h-8" />
            </div>
            <div class="stat-title">Average</div>
            <div class="stat-value text-warning">{{ $attendanceStats['average_attendance'] }}</div>
            <div class="stat-desc">Students per session</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-success">
                <x-icon name="o-presentation-chart-bar" class="w-8 h-8" />
            </div>
            <div class="stat-title">Rate</div>
            <div class="stat-value text-success">{{ $attendanceStats['attendance_rate'] }}%</div>
            <div class="stat-desc">Overall attendance</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-secondary">
                <x-icon name="o-user-group" class="w-8 h-8" />
            </div>
            <div class="stat-title">Students</div>
            <div class="stat-value text-secondary">{{ $attendanceStats['total_students'] }}</div>
            <div class="stat-desc">Total enrolled</div>
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
                label="Yesterday"
                @click="$wire.setPeriod('yesterday')"
                class="{{ $period === 'yesterday' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="This Week"
                @click="$wire.setPeriod('week')"
                class="{{ $period === 'week' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="This Month"
                @click="$wire.setPeriod('month')"
                class="{{ $period === 'month' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="This Quarter"
                @click="$wire.setPeriod('quarter')"
                class="{{ $period === 'quarter' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="This Year"
                @click="$wire.setPeriod('year')"
                class="{{ $period === 'year' ? 'btn-primary' : 'btn-outline' }}"
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

    <!-- SESSIONS TABLE -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('date')">
                            <div class="flex items-center">
                                Date & Time
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
                        <th class="cursor-pointer" wire:click="sortBy('topic')">
                            <div class="flex items-center">
                                Topic
                                @if ($sortBy['column'] === 'topic')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Attendance</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sessions as $session)
                        <tr class="hover">
                            <td>
                                <div class="flex flex-col">
                                    <span class="font-medium">{{ $session->date->format('d M Y') }}</span>
                                    <span class="text-sm text-gray-600">{{ $session->start_time }} - {{ $session->end_time }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="flex flex-col">
                                    <span>{{ $session->subject->name ?? 'Unknown Subject' }}</span>
                                    <span class="text-sm text-gray-600">{{ $session->subject->code ?? '' }}</span>
                                </div>
                            </td>
                            <td>{{ $session->topic }}</td>
                            <td>
                                <div class="flex items-center gap-1">
                                    @if ($session->attendance_data['is_taken'])
                                        <div class="radial-progress text-success" style="--value:{{ $session->attendance_data['attendance_percentage'] }}; --size:2rem; --thickness: 2px;">
                                            <span class="text-xs">{{ $session->attendance_data['attendance_percentage'] }}%</span>
                                        </div>
                                        <div class="ml-2">
                                            <div class="text-sm">
                                                <span class="text-success">{{ $session->attendance_data['attendance_count'] }}</span> present
                                                @if ($session->attendance_data['late_count'] > 0)
                                                    • <span class="text-warning">{{ $session->attendance_data['late_count'] }}</span> late
                                                @endif
                                                @if ($session->attendance_data['absent_count'] > 0)
                                                    • <span class="text-error">{{ $session->attendance_data['absent_count'] }}</span> absent
                                                @endif
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-gray-500">Not marked</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if ($session->status_data['is_upcoming'])
                                    <x-badge label="Upcoming" color="info" />
                                @elseif ($session->status_data['is_in_progress'])
                                    <x-badge label="In Progress" color="warning" />
                                @elseif ($session->status_data['is_completed'])
                                    <x-badge label="Completed" color="success" />
                                @else
                                    <x-badge label="Unknown" color="ghost" />
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button
                                        icon="o-eye"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View Session Details"
                                        wire:click="viewSession({{ $session->id }})"
                                    />

                                    @if ($session->status_data['is_in_progress'] || $session->status_data['is_completed'])
                                        <x-button
                                            icon="o-clipboard-document-check"
                                            color="primary"
                                            size="sm"
                                            tooltip="{{ $session->attendance_data['is_taken'] ? 'Update Attendance' : 'Mark Attendance' }}"
                                            wire:click="markAttendance({{ $session->id }})"
                                        />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-clipboard-document-check" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No sessions found</h3>
                                    <p class="text-gray-500">No sessions match your current filters for the selected time period</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if ($sessions instanceof \Illuminate\Pagination\LengthAwarePaginator)
            <div class="mt-4">
                {{ $sessions->links() }}
            </div>
        @endif
    </x-card>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search sessions"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Topic or subject..."
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
                    :options="[
                        ['label' => 'Completed', 'value' => 'completed'],
                        ['label' => 'In Progress', 'value' => 'in_progress'],
                        ['label' => 'Upcoming', 'value' => 'upcoming']
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
                        ['label' => 'Yesterday', 'value' => 'yesterday'],
                        ['label' => 'This Week', 'value' => 'week'],
                        ['label' => 'This Month', 'value' => 'month'],
                        ['label' => 'This Quarter', 'value' => 'quarter'],
                        ['label' => 'This Year', 'value' => 'year'],
                        ['label' => 'All Time', 'value' => 'all'],
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
