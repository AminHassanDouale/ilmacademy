<?php

use App\Models\Attendance;
use App\Models\ChildProfile;
use App\Models\Session;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Attendance Dashboard')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $child = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $session = '';

    #[Url]
    public string $period = 'month';

    #[Url]
    public string $startDate = '';

    #[Url]
    public string $endDate = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // For the calendar view
    public $calendarMonth;
    public $calendarYear;
    public $view = 'list'; // list, calendar, analytics

    public function mount(): void
    {
        // Default dates if not set
        if (empty($this->startDate)) {
            $this->startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
        }

        if (empty($this->endDate)) {
            $this->endDate = Carbon::now()->format('Y-m-d');
        }

        // Set calendar default month and year to current
        $this->calendarMonth = Carbon::now()->month;
        $this->calendarYear = Carbon::now()->year;

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Parent accessed attendance dashboard',
            Attendance::class,
            null,
            ['ip' => request()->ip(), 'view' => $this->view]
        );
    }

    // Switch view mode
    public function setView(string $view): void
    {
        $this->view = $view;
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

    // Change calendar month
    public function changeMonth(int $change): void
    {
        $date = Carbon::createFromDate($this->calendarYear, $this->calendarMonth, 1);
        $date->addMonths($change);

        $this->calendarMonth = $date->month;
        $this->calendarYear = $date->year;
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
            case 'year':
                $this->startDate = Carbon::now()->startOfYear()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfYear()->format('Y-m-d');
                break;
            case 'custom':
                // Keep existing dates
                break;
        }
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->child = '';
        $this->status = '';
        $this->session = '';
        $this->period = 'month';
        $this->startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
        $this->endDate = Carbon::now()->format('Y-m-d');
        $this->resetPage();
    }

    // Get children for this parent
    public function children()
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return collect();
        }

        return ChildProfile::where('parent_profile_id', $parentProfile->id)
            ->with('user')
            ->get()
            ->map(function ($child) {
                return [
                    'id' => $child->id,
                    'name' => $child->user?->name ?? 'Unknown'
                ];
            });
    }

    // Get sessions for filter
   // Get sessions for filter
// Get sessions for filter
public function sessions()
{
    $parentProfile = Auth::user()->parentProfile;

    if (!$parentProfile) {
        return collect();
    }

    $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

    if (empty($childrenIds)) {
        return collect();
    }

    // Get unique session IDs for these children
    $sessionIds = DB::table('attendances')
        ->whereIn('child_profile_id', $childrenIds)
        ->distinct()
        ->pluck('session_id');

    // Fetch the sessions with proper labels
    return Session::whereIn('id', $sessionIds->toArray())
        ->with(['subject'])
        ->get()
        ->map(function ($session) {
            // Create a descriptive name using subject name and session time
            $subjectName = $session->subject ? $session->subject->name : 'Unknown Subject';
            $date = $session->start_time ? $session->start_time->format('d/m/Y H:i') : 'No date';

            return [
                'id' => $session->id,
                'name' => "{$subjectName} - {$date}"
            ];
        });
}

    // Get filtered attendance records
    public function attendanceRecords(): LengthAwarePaginator
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        if (empty($childrenIds)) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        return Attendance::query()
            ->whereIn('child_profile_id', $childrenIds)
            ->with(['childProfile.user', 'session'])
            ->whereBetween('created_at', [$this->startDate . ' 00:00:00', $this->endDate . ' 23:59:59'])
            ->when($this->child, function (Builder $query) {
                $query->where('child_profile_id', $this->child);
            })
            ->when($this->status, function (Builder $query) {
                $query->where('status', $this->status);
            })
            ->when($this->session, function (Builder $query) {
                $query->where('session_id', $this->session);
            })
            ->when($this->sortBy['column'] === 'child', function (Builder $query) {
                $query->join('child_profiles', 'attendances.child_profile_id', '=', 'child_profiles.id')
                    ->join('users', 'child_profiles.user_id', '=', 'users.id')
                    ->orderBy('users.name', $this->sortBy['direction'])
                    ->select('attendances.*');
            }, function (Builder $query) {
                if ($this->sortBy['column'] === 'session') {
                    $query->join('sessions', 'attendances.session_id', '=', 'sessions.id')
                        ->orderBy('sessions.name', $this->sortBy['direction'])
                        ->select('attendances.*');
                } else {
                    $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);
                }
            })
            ->paginate($this->perPage);
    }

    // Get calendar data
    public function calendarData()
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return [];
        }

        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        if (empty($childrenIds)) {
            return [];
        }

        // Get the start and end of the month
        $startOfMonth = Carbon::createFromDate($this->calendarYear, $this->calendarMonth, 1)->startOfMonth();
        $endOfMonth = Carbon::createFromDate($this->calendarYear, $this->calendarMonth, 1)->endOfMonth();

        // Get all attendance records for this month
        $attendanceRecords = Attendance::query()
            ->whereIn('child_profile_id', $childrenIds)
            ->with(['childProfile.user', 'session'])
            ->whereBetween('created_at', [$startOfMonth->format('Y-m-d') . ' 00:00:00', $endOfMonth->format('Y-m-d') . ' 23:59:59'])
            ->when($this->child, function (Builder $query) {
                $query->where('child_profile_id', $this->child);
            })
            ->when($this->session, function (Builder $query) {
                $query->where('session_id', $this->session);
            })
            ->get()
            ->groupBy(function ($record) {
                return $record->created_at->format('Y-m-d');
            });

        // Create calendar array
        $calendar = [];

        // Get days in month
        $period = CarbonPeriod::create(
            $startOfMonth->startOfWeek(),
            $endOfMonth->endOfWeek()
        );

        foreach ($period as $date) {
            $dateString = $date->format('Y-m-d');
            $isCurrentMonth = $date->month === (int)$this->calendarMonth;

            $calendar[] = [
                'date' => $date,
                'isCurrentMonth' => $isCurrentMonth,
                'isToday' => $date->isToday(),
                'hasAttendance' => isset($attendanceRecords[$dateString]),
                'records' => $attendanceRecords[$dateString] ?? []
            ];
        }

        return $calendar;
    }

    // Get attendance statistics
    public function attendanceStats()
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return [
                'total' => 0,
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'excused' => 0,
                'presentPercentage' => 0,
                'absentPercentage' => 0,
                'latePercentage' => 0,
                'excusedPercentage' => 0
            ];
        }

        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        if (empty($childrenIds)) {
            return [
                'total' => 0,
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'excused' => 0,
                'presentPercentage' => 0,
                'absentPercentage' => 0,
                'latePercentage' => 0,
                'excusedPercentage' => 0
            ];
        }

        $records = Attendance::query()
            ->whereIn('child_profile_id', $childrenIds)
            ->whereBetween('created_at', [$this->startDate . ' 00:00:00', $this->endDate . ' 23:59:59'])
            ->when($this->child, function (Builder $query) {
                $query->where('child_profile_id', $this->child);
            })
            ->when($this->session, function (Builder $query) {
                $query->where('session_id', $this->session);
            })
            ->get();

        $total = $records->count();
        $present = $records->where('status', 'present')->count();
        $absent = $records->where('status', 'absent')->count();
        $late = $records->where('status', 'late')->count();
        $excused = $records->where('status', 'excused')->count();

        return [
            'total' => $total,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'excused' => $excused,
            'presentPercentage' => $total > 0 ? round(($present / $total) * 100) : 0,
            'absentPercentage' => $total > 0 ? round(($absent / $total) * 100) : 0,
            'latePercentage' => $total > 0 ? round(($late / $total) * 100) : 0,
            'excusedPercentage' => $total > 0 ? round(($excused / $total) * 100) : 0
        ];
    }

    // Get attendance trend data
    public function attendanceTrend()
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return [];
        }

        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        if (empty($childrenIds)) {
            return [];
        }

        // Get date range based on period
        $startDate = Carbon::parse($this->startDate);
        $endDate = Carbon::parse($this->endDate);

        // Determine period grouping (daily, weekly, monthly)
        $grouping = 'daily';
        $diffDays = $startDate->diffInDays($endDate);

        if ($diffDays > 60) {
            $grouping = 'monthly';
        } elseif ($diffDays > 14) {
            $grouping = 'weekly';
        }

        // Get attendance grouped by date
        $records = Attendance::query()
            ->whereIn('child_profile_id', $childrenIds)
            ->whereBetween('created_at', [$startDate->format('Y-m-d') . ' 00:00:00', $endDate->format('Y-m-d') . ' 23:59:59'])
            ->when($this->child, function (Builder $query) {
                $query->where('child_profile_id', $this->child);
            })
            ->when($this->session, function (Builder $query) {
                $query->where('session_id', $this->session);
            })
            ->get();

        // Group by period
        if ($grouping === 'monthly') {
            $grouped = $records->groupBy(function ($record) {
                return $record->created_at->format('Y-m');
            });

            $trend = [];
            foreach ($grouped as $month => $items) {
                $monthDate = Carbon::createFromFormat('Y-m', $month);
                $trend[] = [
                    'period' => $monthDate->format('m/Y'),
                    'total' => $items->count(),
                    'present' => $items->where('status', 'present')->count(),
                    'absent' => $items->where('status', 'absent')->count(),
                    'late' => $items->where('status', 'late')->count(),
                    'excused' => $items->where('status', 'excused')->count(),
                ];
            }
        } elseif ($grouping === 'weekly') {
            $grouped = $records->groupBy(function ($record) {
                return $record->created_at->startOfWeek()->format('Y-m-d');
            });

            $trend = [];
            foreach ($grouped as $week => $items) {
                $weekDate = Carbon::createFromFormat('Y-m-d', $week);
                $trend[] = [
                    'period' => 'Week of ' . $weekDate->format('d/m'),
                    'total' => $items->count(),
                    'present' => $items->where('status', 'present')->count(),
                    'absent' => $items->where('status', 'absent')->count(),
                    'late' => $items->where('status', 'late')->count(),
                    'excused' => $items->where('status', 'excused')->count(),
                ];
            }
        } else {
            $grouped = $records->groupBy(function ($record) {
                return $record->created_at->format('Y-m-d');
            });

            $trend = [];
            foreach ($grouped as $day => $items) {
                $dayDate = Carbon::createFromFormat('Y-m-d', $day);
                $trend[] = [
                    'period' => $dayDate->format('d/m'),
                    'total' => $items->count(),
                    'present' => $items->where('status', 'present')->count(),
                    'absent' => $items->where('status', 'absent')->count(),
                    'late' => $items->where('status', 'late')->count(),
                    'excused' => $items->where('status', 'excused')->count(),
                ];
            }
        }

        return $trend;
    }

    // Get child-specific attendance stats
    public function childAttendanceStats()
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return [];
        }

        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        if (empty($childrenIds)) {
            return [];
        }

        // Get all children with their users
        $children = ChildProfile::whereIn('id', $childrenIds)
            ->with('user')
            ->get();

        $stats = [];

        foreach ($children as $child) {
            $records = Attendance::query()
                ->where('child_profile_id', $child->id)
                ->whereBetween('created_at', [$this->startDate . ' 00:00:00', $this->endDate . ' 23:59:59'])
                ->when($this->session, function (Builder $query) {
                    $query->where('session_id', $this->session);
                })
                ->get();

            $total = $records->count();

            if ($total > 0) {
                $stats[] = [
                    'id' => $child->id,
                    'name' => $child->user?->name ?? 'Unknown',
                    'total' => $total,
                    'present' => $records->where('status', 'present')->count(),
                    'absent' => $records->where('status', 'absent')->count(),
                    'late' => $records->where('status', 'late')->count(),
                    'excused' => $records->where('status', 'excused')->count(),
                    'presentPercentage' => round(($records->where('status', 'present')->count() / $total) * 100),
                    'absentPercentage' => round(($records->where('status', 'absent')->count() / $total) * 100),
                    'latePercentage' => round(($records->where('status', 'late')->count() / $total) * 100),
                    'excusedPercentage' => round(($records->where('status', 'excused')->count() / $total) * 100),
                ];
            }
        }

        return $stats;
    }

    public function with(): array
    {
        return [
            'attendanceRecords' => $this->attendanceRecords(),
            'children' => $this->children(),
            'sessions' => $this->sessions(),
            'attendanceStats' => $this->attendanceStats(),
            'attendanceTrend' => $this->attendanceTrend(),
            'childAttendanceStats' => $this->childAttendanceStats(),
            'calendarData' => $this->view === 'calendar' ? $this->calendarData() : [],
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Attendance Dashboard" separator progress-indicator>
        <x-slot:subtitle>
            Track and monitor your children's attendance records
        </x-slot:subtitle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <div class="flex gap-2">
                <x-button
                    icon="o-list-bullet"
                    @click="$wire.setView('list')"
                    class="{{ $view === 'list' ? 'btn-primary' : 'btn-ghost' }}"
                    tooltip="List View"
                />

                <x-button
                    icon="o-calendar"
                    @click="$wire.setView('calendar')"
                    class="{{ $view === 'calendar' ? 'btn-primary' : 'btn-ghost' }}"
                    tooltip="Calendar View"
                />

                <x-button
                    icon="o-chart-bar"
                    @click="$wire.setView('analytics')"
                    class="{{ $view === 'analytics' ? 'btn-primary' : 'btn-ghost' }}"
                    tooltip="Analytics View"
                />
            </div>

            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$status, $child, $session, $period !== 'month']))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- QUICK STATS PANEL -->
    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-4">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-success">
                <x-icon name="o-check-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Present</div>
            <div class="stat-value text-success">{{ $attendanceStats['presentPercentage'] }}%</div>
            <div class="stat-desc">{{ $attendanceStats['present'] }} of {{ $attendanceStats['total'] }} sessions</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-error">
                <x-icon name="o-x-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Absent</div>
            <div class="stat-value text-error">{{ $attendanceStats['absentPercentage'] }}%</div>
            <div class="stat-desc">{{ $attendanceStats['absent'] }} of {{ $attendanceStats['total'] }} sessions</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-warning">
                <x-icon name="o-clock" class="w-8 h-8" />
            </div>
            <div class="stat-title">Late</div>
            <div class="stat-value text-warning">{{ $attendanceStats['latePercentage'] }}%</div>
            <div class="stat-desc">{{ $attendanceStats['late'] }} of {{ $attendanceStats['total'] }} sessions</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-info">
                <x-icon name="o-document-check" class="w-8 h-8" />
            </div>
            <div class="stat-title">Excused</div>
            <div class="stat-value text-info">{{ $attendanceStats['excusedPercentage'] }}%</div>
            <div class="stat-desc">{{ $attendanceStats['excused'] }} of {{ $attendanceStats['total'] }} sessions</div>
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

    <!-- LIST VIEW -->
    <div x-show="$wire.view === 'list'">
        <x-card>
            <div class="overflow-x-auto">
                <table class="table w-full table-zebra">
                    <thead>
                        <tr>
                            <th class="cursor-pointer" wire:click="sortBy('created_at')">
                                <div class="flex items-center">
                                    Date
                                    @if ($sortBy['column'] === 'created_at')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="cursor-pointer" wire:click="sortBy('child')">
                                <div class="flex items-center">
                                    Child
                                    @if ($sortBy['column'] === 'child')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="cursor-pointer" wire:click="sortBy('session')">
                                <div class="flex items-center">
                                    Session
                                    @if ($sortBy['column'] === 'session')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th class="cursor-pointer" wire:click="sortBy('status')">
                                <div class="flex items-center">
                                    Status
                                    @if ($sortBy['column'] === 'status')
                                        <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                    @endif
                                </div>
                            </th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($attendanceRecords as $record)
                            <tr class="hover">
                                <td>{{ $record->created_at->format('d/m/Y') }}</td>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="avatar">
                                            <div class="w-10 h-10 mask mask-squircle">
                                                @if ($record->childProfile->photo)
                                                    <img src="{{ asset('storage/' . $record->childProfile->photo) }}" alt="{{ $record->childProfile->user?->name ?? 'Child' }}">
                                                @else
                                                    <img src="{{ $record->childProfile->user?->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Child&color=7F9CF5&background=EBF4FF' }}" alt="{{ $record->childProfile->user?->name ?? 'Child' }}">
                                                @endif
                                            </div>
                                        </div>
                                        <div>
                                            {{ $record->childProfile->user?->name ?? 'Unknown Child' }}
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    @if($record->session)
        <div>
            {{ $record->session->subject->name ?? 'Unknown Subject' }}
        </div>
        <div class="text-xs text-gray-500">
            {{ $record->session->start_time ? $record->session->start_time->format('d/m/Y H:i') : 'No time' }}
        </div>
    @else
        Unknown Session
    @endif
                                </td>
                                <td>
                                    <x-badge
                                        label="{{ ucfirst($record->status) }}"
                                        color="{{ match($record->status) {
                                            'present' => 'success',
                                            'absent' => 'error',
                                            'late' => 'warning',
                                            'excused' => 'info',
                                            default => 'ghost'
                                        } }}"
                                    />
                                </td>
                                <td>{{ $record->notes ?? 'No notes' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-8 text-center">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <x-icon name="o-calendar" class="w-16 h-16 text-gray-400" />
                                        <h3 class="text-lg font-semibold text-gray-600">No attendance records found</h3>
                                        <p class="text-gray-500">No records match your current filters for the selected time period</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                   </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="mt-4">
                    {{ $attendanceRecords->links() }}
                </div>
            </x-card>
        </div>

        <!-- CALENDAR VIEW -->
        <div x-show="$wire.view === 'calendar'">
            <x-card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold">
                      {{ \Carbon\Carbon::createFromDate($calendarYear, $calendarMonth, 1)->format('F Y') }}
                    </h3>
                    <div class="flex gap-2">
                        <x-button icon="o-chevron-left" @click="$wire.changeMonth(-1)" />
<x-button label="Today" @click="$wire.calendarMonth = {{ \Carbon\Carbon::now()->month }}; $wire.calendarYear = {{ \Carbon\Carbon::now()->year }}" />
                            <x-button icon="o-chevron-right" @click="$wire.changeMonth(1)" />
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-1">
                    <!-- Day headers -->
                    @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dayName)
                        <div class="py-2 font-semibold text-center">{{ $dayName }}</div>
                    @endforeach

                    <!-- Calendar days -->
                    @foreach($calendarData as $day)
                        <div class="min-h-24 border rounded-lg p-2 {{ $day['isCurrentMonth'] ? 'bg-base-100' : 'bg-base-200 opacity-50' }} {{ $day['isToday'] ? 'border-primary' : '' }}">
                            <div class="text-sm {{ $day['isToday'] ? 'font-bold text-primary' : '' }}">
                                {{ $day['date']->format('j') }}
                            </div>

                            @if($day['hasAttendance'])
                                <div class="mt-1 space-y-1">
                                    @foreach($day['records'] as $record)
                                        <div class="text-xs p-1 rounded-md {{ match($record->status) {
                                            'present' => 'bg-success/20 text-success',
                                            'absent' => 'bg-error/20 text-error',
                                            'late' => 'bg-warning/20 text-warning',
                                            'excused' => 'bg-info/20 text-info',
                                            default => 'bg-base-200'
                                        } }}">
                                            <div class="truncate">
    {{ $record->session->subject->name ?? 'Unknown Subject' }}
    ({{ $record->session->start_time ? $record->session->start_time->format('H:i') : 'No time' }})
</div>

                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap gap-2 mt-4">
                    <div class="flex items-center gap-1">
                        <span class="inline-block w-3 h-3 rounded-full bg-success"></span>
                        <span class="text-xs">Present</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="inline-block w-3 h-3 rounded-full bg-error"></span>
                        <span class="text-xs">Absent</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="inline-block w-3 h-3 rounded-full bg-warning"></span>
                        <span class="text-xs">Late</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="inline-block w-3 h-3 rounded-full bg-info"></span>
                        <span class="text-xs">Excused</span>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- ANALYTICS VIEW -->
        <div x-show="$wire.view === 'analytics'">
            <div class="grid gap-6 md:grid-cols-2">
                <!-- ATTENDANCE TREND CHART -->
                <x-card title="Attendance Trend" separator>
                    @if(count($attendanceTrend) > 0)
                        <div class="h-80">
                            <canvas id="attendanceTrendChart"></canvas>
                        </div>

                        <script>
                            document.addEventListener('livewire:initialized', function () {
                                const renderChart = () => {
                                    const ctx = document.getElementById('attendanceTrendChart');

                                    // Destroy existing chart if it exists
                                    if (window.attendanceTrendChart) {
                                        window.attendanceTrendChart.destroy();
                                    }

                                    const data = @json($attendanceTrend);

                                    window.attendanceTrendChart = new Chart(ctx, {
                                        type: 'line',
                                        data: {
                                            labels: data.map(item => item.period),
                                            datasets: [
                                                {
                                                    label: 'Present',
                                                    data: data.map(item => item.present),
                                                    borderColor: '#36d399',
                                                    backgroundColor: 'rgba(54, 211, 153, 0.2)',
                                                    tension: 0.2
                                                },
                                                {
                                                    label: 'Absent',
                                                    data: data.map(item => item.absent),
                                                    borderColor: '#f87272',
                                                    backgroundColor: 'rgba(248, 114, 114, 0.2)',
                                                    tension: 0.2
                                                },
                                                {
                                                    label: 'Late',
                                                    data: data.map(item => item.late),
                                                    borderColor: '#fbbd23',
                                                    backgroundColor: 'rgba(251, 189, 35, 0.2)',
                                                    tension: 0.2
                                                },
                                                {
                                                    label: 'Excused',
                                                    data: data.map(item => item.excused),
                                                    borderColor: '#3abff8',
                                                    backgroundColor: 'rgba(58, 191, 248, 0.2)',
                                                    tension: 0.2
                                                }
                                            ]
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            scales: {
                                                y: {
                                                    beginAtZero: true,
                                                    ticks: {
                                                        precision: 0
                                                    }
                                                }
                                            }
                                        }
                                    });
                                };

                                renderChart();

                                Livewire.on('refreshCharts', () => {
                                    setTimeout(renderChart, 200);
                                });
                            });
                        </script>
                    @else
                        <div class="flex flex-col items-center justify-center py-10">
                            <x-icon name="o-chart-bar" class="w-16 h-16 text-gray-400" />
                            <h3 class="mt-2 text-lg font-semibold text-gray-600">No trend data available</h3>
                            <p class="text-gray-500">There is no attendance data for the selected period</p>
                        </div>
                    @endif
                </x-card>

                <!-- ATTENDANCE SUMMARY BY CHILD -->
                <x-card title="Attendance by Child" separator>
                    @if(count($childAttendanceStats) > 0)
                        <div class="space-y-4">
                            @foreach($childAttendanceStats as $childStat)
                                <div class="p-4 border rounded-lg">
                                    <div class="flex items-center gap-4 mb-3">
                                        <div class="font-bold">{{ $childStat['name'] }}</div>
                                        <div class="text-sm">
                                            <span class="text-success">{{ $childStat['presentPercentage'] }}% Present</span> |
                                            <span class="text-error">{{ $childStat['absentPercentage'] }}% Absent</span>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-4 gap-2 mb-3">
                                        <div>
                                            <div class="text-xs text-gray-500">Present</div>
                                            <div class="flex items-center gap-1">
                                                <span class="font-semibold">{{ $childStat['present'] }}</span>
                                                <span class="text-xs text-gray-500">/ {{ $childStat['total'] }}</span>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-gray-500">Absent</div>
                                            <div class="flex items-center gap-1">
                                                <span class="font-semibold">{{ $childStat['absent'] }}</span>
                                                <span class="text-xs text-gray-500">/ {{ $childStat['total'] }}</span>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-gray-500">Late</div>
                                            <div class="flex items-center gap-1">
                                                <span class="font-semibold">{{ $childStat['late'] }}</span>
                                                <span class="text-xs text-gray-500">/ {{ $childStat['total'] }}</span>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-gray-500">Excused</div>
                                            <div class="flex items-center gap-1">
                                                <span class="font-semibold">{{ $childStat['excused'] }}</span>
                                                <span class="text-xs text-gray-500">/ {{ $childStat['total'] }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="w-full h-2 overflow-hidden bg-gray-200 rounded-full">
                                        <div class="flex h-full">
                                            <div class="h-full bg-success" style="width: {{ $childStat['presentPercentage'] }}%"></div>
                                            <div class="h-full bg-error" style="width: {{ $childStat['absentPercentage'] }}%"></div>
                                            <div class="h-full bg-warning" style="width: {{ $childStat['latePercentage'] }}%"></div>
                                            <div class="h-full bg-info" style="width: {{ $childStat['excusedPercentage'] }}%"></div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-10">
                            <x-icon name="o-user-group" class="w-16 h-16 text-gray-400" />
                            <h3 class="mt-2 text-lg font-semibold text-gray-600">No child data available</h3>
                            <p class="text-gray-500">There is no attendance data for any children in the selected period</p>
                        </div>
                    @endif
                </x-card>
            </div>
        </div>

        <!-- Filters drawer -->
        <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
            <div class="flex flex-col gap-4 mb-4">
                <div>
                    <x-select
                        label="Filter by child"
                        placeholder="All children"
                        :options="$children"
                        wire:model.live="child"
                        option-label="name"
                        option-value="id"
                        empty-message="No children found"
                    />
                </div>

                <div>
                    <x-select
                        label="Filter by status"
                        placeholder="All statuses"
                        :options="[
                            ['label' => 'Present', 'value' => 'present'],
                            ['label' => 'Absent', 'value' => 'absent'],
                            ['label' => 'Late', 'value' => 'late'],
                            ['label' => 'Excused', 'value' => 'excused']
                        ]"
                        wire:model.live="status"
                        option-label="label"
                        option-value="value"
                    />
                </div>

                <div>
                    <x-select
                        label="Filter by session"
                        placeholder="All sessions"
                        :options="$sessions"
                        wire:model.live="session"
                        option-label="name"
                        option-value="id"
                        empty-message="No sessions found"
                    />
                </div>

                <div>
                    <x-select
                        label="Items per page"
                        :options="[15, 30, 50, 100]"
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
