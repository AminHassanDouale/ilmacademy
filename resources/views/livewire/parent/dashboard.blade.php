<?php
// resources/views/livewire/parent/dashboard.blade.php

use App\Models\ChildProfile;
use App\Models\ProgramEnrollment;
use App\Models\Payment;
use App\Models\Invoice;
use App\Models\Session;
use App\Models\Attendance;
use App\Models\ExamResult;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;

new class extends Component {
    public string $greeting = '';
    public $selectedChild = null;
    public $selectedPeriod = 'month';

    // Data collections
    public $children = [];
    public $childrenStats = [];
    public $upcomingEvents = [];
    public $recentPayments = [];
    public $pendingInvoices = [];
    public $attendanceData = [];
    public $examResults = [];

    // Charts
    public array $attendanceChart = [
        'type' => 'line',
        'options' => [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'x' => ['grid' => ['display' => false]],
                'y' => ['beginAtZero' => true, 'max' => 100],
            ],
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'top'],
            ],
        ],
        'data' => [
            'labels' => [],
            'datasets' => [
                [
                    'label' => 'Attendance Rate (%)',
                    'data' => [],
                    'fill' => true,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.2)',
                    'borderColor' => 'rgba(59, 130, 246, 1)',
                    'borderWidth' => 2,
                    'tension' => 0.4,
                ],
            ],
        ],
    ];

    public array $gradesChart = [
        'type' => 'radar',
        'options' => [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'r' => [
                    'beginAtZero' => true,
                    'max' => 100,
                    'ticks' => ['stepSize' => 20],
                ],
            ],
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'top'],
            ],
        ],
        'data' => [
            'labels' => [],
            'datasets' => [
                [
                    'label' => 'Grades',
                    'data' => [],
                    'fill' => true,
                    'backgroundColor' => 'rgba(16, 185, 129, 0.2)',
                    'borderColor' => 'rgba(16, 185, 129, 1)',
                    'borderWidth' => 2,
                ],
            ],
        ],
    ];

    public function mount()
    {
        $this->greeting = $this->getGreeting();
        $this->loadChildren();
        $this->selectedChild = $this->children->first()?->id;
        $this->loadDashboardData();
        $this->refreshCharts();
    }

    public function updatedSelectedChild()
    {
        $this->loadDashboardData();
        $this->refreshCharts();
    }

    public function updatedSelectedPeriod()
    {
        $this->refreshCharts();
    }

    private function getGreeting(): string
    {
        $user = Auth::user();
        $currentHour = Carbon::now()->format('H');
        $greeting = 'Welcome, ' . ($user ? $user->name : 'Parent') . '! ';

        if ($currentHour < 12) {
            $greeting .= 'Good morning!';
        } elseif ($currentHour < 18) {
            $greeting .= 'Good afternoon!';
        } else {
            $greeting .= 'Good evening!';
        }

        return $greeting;
    }

    private function loadChildren()
    {
        $this->children = ChildProfile::where('parent_id', Auth::id())
            ->with(['programEnrollments.curriculum', 'programEnrollments.academicYear'])
            ->get();
    }

    private function loadDashboardData()
    {
        if (!$this->selectedChild) return;

        $child = ChildProfile::find($this->selectedChild);
        if (!$child) return;

        // Load child statistics
        $this->loadChildStats($child);
        $this->loadUpcomingEvents($child);
        $this->loadFinancialData($child);
        $this->loadAcademicData($child);
    }

    private function loadChildStats($child)
    {
        $enrollments = $child->programEnrollments;
        $activeEnrollments = $enrollments->where('status', 'Active');

        // Calculate attendance rate
        $totalSessions = 0;
        $attendedSessions = 0;

        foreach ($activeEnrollments as $enrollment) {
            foreach ($enrollment->subjectEnrollments as $subjectEnrollment) {
                $sessions = Session::where('subject_id', $subjectEnrollment->subject_id)
                    ->where('start_time', '<=', now())
                    ->count();
                $totalSessions += $sessions;

                $attended = Attendance::where('child_profile_id', $child->id)
                    ->whereHas('session', function($q) use ($subjectEnrollment) {
                        $q->where('subject_id', $subjectEnrollment->subject_id);
                    })
                    ->where('status', 'present')
                    ->count();
                $attendedSessions += $attended;
            }
        }

        $attendanceRate = $totalSessions > 0 ? round(($attendedSessions / $totalSessions) * 100, 1) : 0;

        // Get recent exam average
        $recentExams = ExamResult::where('child_profile_id', $child->id)
            ->whereHas('exam', function($q) {
                $q->where('exam_date', '>=', Carbon::now()->subMonths(3));
            })
            ->get();

        $averageGrade = $recentExams->count() > 0 ? round($recentExams->avg('score'), 1) : 0;

        // Get pending payments
        $pendingPayments = Payment::where('child_profile_id', $child->id)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_OVERDUE])
            ->sum('amount');

        $this->childrenStats = [
            'active_enrollments' => $activeEnrollments->count(),
            'attendance_rate' => $attendanceRate,
            'average_grade' => $averageGrade,
            'pending_payments' => $pendingPayments,
            'total_sessions_this_week' => $this->getWeeklySessionCount($child),
        ];
    }

    private function getWeeklySessionCount($child)
    {
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();

        $enrollments = $child->programEnrollments->where('status', 'Active');
        $sessionCount = 0;

        foreach ($enrollments as $enrollment) {
            foreach ($enrollment->subjectEnrollments as $subjectEnrollment) {
                $sessions = Session::where('subject_id', $subjectEnrollment->subject_id)
                    ->whereBetween('start_time', [$startOfWeek, $endOfWeek])
                    ->count();
                $sessionCount += $sessions;
            }
        }

        return $sessionCount;
    }

    private function loadUpcomingEvents($child)
    {
        $this->upcomingEvents = Session::whereHas('subject.subjectEnrollments.programEnrollment', function($q) use ($child) {
            $q->where('child_profile_id', $child->id)
              ->where('status', 'Active');
        })
        ->with(['subject', 'teacherProfile.user'])
        ->where('start_time', '>', Carbon::now())
        ->where('start_time', '<=', Carbon::now()->addDays(7))
        ->orderBy('start_time')
        ->take(5)
        ->get();
    }

    private function loadFinancialData($child)
    {
        $this->recentPayments = Payment::where('child_profile_id', $child->id)
            ->with(['invoice'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        $this->pendingInvoices = Invoice::where('child_profile_id', $child->id)
            ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_SENT])
            ->orderBy('due_date')
            ->take(3)
            ->get();
    }

    private function loadAcademicData($child)
    {
        $this->examResults = ExamResult::where('child_profile_id', $child->id)
            ->with(['exam.subject'])
            ->whereHas('exam', function($q) {
                $q->where('exam_date', '>=', Carbon::now()->subMonths(6));
            })
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();
    }

    private function refreshCharts()
    {
        if (!$this->selectedChild) return;

        $this->refreshAttendanceChart();
        $this->refreshGradesChart();
    }

    private function refreshAttendanceChart()
    {
        $child = ChildProfile::find($this->selectedChild);
        if (!$child) return;

        $now = Carbon::now();
        $periods = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i);
            $periods[] = [
                'start' => $month->copy()->startOfMonth(),
                'end' => $month->copy()->endOfMonth(),
                'label' => $month->format('M Y')
            ];
        }

        $attendanceData = [];
        $labels = [];

        foreach ($periods as $period) {
            $labels[] = $period['label'];

            // Calculate attendance rate for this period
            $totalSessions = Session::whereHas('subject.subjectEnrollments.programEnrollment', function($q) use ($child) {
                $q->where('child_profile_id', $child->id);
            })
            ->whereBetween('start_time', [$period['start'], $period['end']])
            ->where('start_time', '<=', now())
            ->count();

            $attendedSessions = Attendance::where('child_profile_id', $child->id)
                ->where('status', 'present')
                ->whereHas('session', function($q) use ($period) {
                    $q->whereBetween('start_time', [$period['start'], $period['end']]);
                })
                ->count();

            $rate = $totalSessions > 0 ? round(($attendedSessions / $totalSessions) * 100, 1) : 0;
            $attendanceData[] = $rate;
        }

        Arr::set($this->attendanceChart, 'data.labels', $labels);
        Arr::set($this->attendanceChart, 'data.datasets.0.data', $attendanceData);
    }

    private function refreshGradesChart()
    {
        $child = ChildProfile::find($this->selectedChild);
        if (!$child) return;

        // Get recent exam results by subject
        $examResults = ExamResult::where('child_profile_id', $child->id)
            ->with(['exam.subject'])
            ->whereHas('exam', function($q) {
                $q->where('exam_date', '>=', Carbon::now()->subMonths(3));
            })
            ->get()
            ->groupBy('exam.subject.name');

        $subjects = [];
        $grades = [];

        foreach ($examResults as $subject => $results) {
            $subjects[] = $subject;
            $grades[] = round($results->avg('score'), 1);
        }

        Arr::set($this->gradesChart, 'data.labels', $subjects);
        Arr::set($this->gradesChart, 'data.datasets.0.data', $grades);
    }

    public function with(): array
    {
        return [
            'greeting' => $this->greeting,
            'children' => $this->children,
            'childrenStats' => $this->childrenStats,
            'upcomingEvents' => $this->upcomingEvents,
            'recentPayments' => $this->recentPayments,
            'pendingInvoices' => $this->pendingInvoices,
            'examResults' => $this->examResults,
            'attendanceChart' => $this->attendanceChart,
            'gradesChart' => $this->gradesChart,
        ];
    }
};
?>

<div class="w-full min-h-screen bg-gradient-to-br from-emerald-50 via-teal-50 to-cyan-50">
    <!-- Header with Parent-specific styling -->
    <div class="bg-white/80 backdrop-blur-sm shadow-lg border-b border-emerald-100">
        <div class="container mx-auto px-6 py-6">
            <div class="flex items-center justify-between">
                <div class="space-y-1">
                    <h1 class="text-3xl font-bold bg-gradient-to-r from-emerald-600 to-teal-600 bg-clip-text text-transparent">
                        {{ $greeting }}
                    </h1>
                    <p class="text-sm text-slate-600 font-medium">
                        {{ now()->format('l, F j, Y') }} • Parent Dashboard
                    </p>
                </div>
                <div class="flex items-center space-x-4">
                    @if($children->count() > 1)
                    <select wire:model.live="selectedChild"
                            class="px-4 py-2 rounded-xl border border-emerald-200 bg-white/70 text-slate-700 font-medium shadow-sm hover:shadow-md transition-all duration-300">
                        @foreach($children as $child)
                        <option value="{{ $child->id }}">{{ $child->full_name }}</option>
                        @endforeach
                    </select>
                    @endif
                    <button class="p-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 text-white shadow-lg hover:shadow-xl transform hover:scale-105 transition-all duration-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5 5v-5z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 19h5v-5H4v5z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h5v5H4V4z" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-6 py-8 space-y-8">
        <!-- Children Overview Cards -->
        @if($children->count() > 0)
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <!-- Active Enrollments -->
            <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-600 p-6 text-white shadow-xl hover:shadow-2xl transform hover:-translate-y-2 transition-all duration-500">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ $childrenStats['active_enrollments'] ?? 0 }}</span>
                    </div>
                    <h3 class="font-semibold text-emerald-100">Active Programs</h3>
                    <p class="text-sm text-emerald-200 mt-1">Current enrollments</p>
                </div>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 rounded-full bg-white/10 group-hover:scale-110 transition-transform duration-500"></div>
            </div>

            <!-- Attendance Rate -->
            <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 p-6 text-white shadow-xl hover:shadow-2xl transform hover:-translate-y-2 transition-all duration-500">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ $childrenStats['attendance_rate'] ?? 0 }}%</span>
                    </div>
                    <h3 class="font-semibold text-blue-100">Attendance Rate</h3>
                    <p class="text-sm text-blue-200 mt-1">Overall performance</p>
                </div>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 rounded-full bg-white/10 group-hover:scale-110 transition-transform duration-500"></div>
            </div>

            <!-- Average Grade -->
            <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-purple-500 to-purple-600 p-6 text-white shadow-xl hover:shadow-2xl transform hover:-translate-y-2 transition-all duration-500">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ $childrenStats['average_grade'] ?? 0 }}</span>
                    </div>
                    <h3 class="font-semibold text-purple-100">Average Grade</h3>
                    <p class="text-sm text-purple-200 mt-1">Recent exams</p>
                </div>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 rounded-full bg-white/10 group-hover:scale-110 transition-transform duration-500"></div>
            </div>

            <!-- Pending Payments -->
            <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-amber-500 to-orange-500 p-6 text-white shadow-xl hover:shadow-2xl transform hover:-translate-y-2 transition-all duration-500">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <span class="text-2xl font-bold">${{ number_format($childrenStats['pending_payments'] ?? 0, 0) }}</span>
                    </div>
                    <h3 class="font-semibold text-amber-100">Pending Payments</h3>
                    <p class="text-sm text-amber-200 mt-1">Outstanding balance</p>
                </div>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 rounded-full bg-white/10 group-hover:scale-110 transition-transform duration-500"></div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Attendance Trends -->
            <div class="bg-white/70 backdrop-blur-sm rounded-2xl shadow-xl border border-white/50 p-6 hover:shadow-2xl transition-all duration-500">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">
                        Attendance Trends
                    </h2>
                    <div class="flex space-x-2">
                        <span class="px-3 py-1 rounded-full bg-blue-100 text-blue-600 text-xs font-medium">Monthly View</span>
                    </div>
                </div>
                <div class="h-80">
                    <x-chart wire:model="attendanceChart" class="w-full h-full" />
                </div>
            </div>

            <!-- Academic Performance -->
            <div class="bg-white/70 backdrop-blur-sm rounded-2xl shadow-xl border border-white/50 p-6 hover:shadow-2xl transition-all duration-500">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">
                        Subject Performance
                    </h2>
                    <button class="p-2 rounded-lg bg-gradient-to-r from-slate-100 to-slate-200 hover:from-slate-200 hover:to-slate-300 transition-all duration-300">
                        <svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </button>
                </div>
                <div class="h-80">
                    <x-chart wire:model="gradesChart" class="w-full h-full" />
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Upcoming Events -->
            <div class="bg-white/70 backdrop-blur-sm rounded-2xl shadow-xl border border-white/50 p-6 hover:shadow-2xl transition-all duration-500">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">
                        Upcoming Classes
                    </h2>
                    <a href="{{ route('parent.schedule.index') }}"
                       class="text-sm text-emerald-600 hover:text-emerald-800 font-medium transition-colors duration-300">
                        View Schedule →
                    </a>
                </div>
                <div class="space-y-4 max-h-80 overflow-y-auto">
                    @forelse($upcomingEvents as $event)
                    <div class="flex items-center p-4 rounded-xl bg-gradient-to-r from-emerald-50 to-teal-50 hover:from-emerald-100 hover:to-teal-100 transition-all duration-300">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-r from-emerald-500 to-teal-600 flex items-center justify-center text-white font-bold text-sm mr-4">
                            {{ $event->start_time->format('j') }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-slate-900 truncate">
                                {{ $event->subject->name }}
                            </p>
                            <p class="text-sm text-slate-600">
                                {{ $event->start_time->format('M j, Y • g:i A') }}
                            </p>
                            <p class="text-xs text-slate-500 mt-1">
                                Teacher: {{ $event->teacherProfile->user->name }}
                            </p>
                        </div>
                    </div>
                    @empty
                    <div class="text-center py-8">
                        <svg class="w-12 h-12 text-slate-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <p class="text-slate-500">No upcoming classes this week</p>
                    </div>
                    @endforelse
                </div>
            </div>

            <!-- Recent Payments -->
            <div class="bg-white/70 backdrop-blur-sm rounded-2xl shadow-xl border border-white/50 p-6 hover:shadow-2xl transition-all duration-500">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">
                        Recent Payments
                    </h2>
                    <a href="{{ route('parent.invoices.index') }}"
                       class="text-sm text-emerald-600 hover:text-emerald-800 font-medium transition-colors duration-300">
                        View All →
                    </a>
                </div>
                <div class="space-y-4 max-h-80 overflow-y-auto">
                    @forelse($recentPayments as $payment)
                    <div class="flex items-center justify-between p-4 rounded-xl bg-gradient-to-r from-slate-50 to-slate-100 hover:from-slate-100 hover:to-slate-200 transition-all duration-300">
                        <div class="flex items-center">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-r from-emerald-500 to-teal-600 flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-900">
                                    ${{ number_format($payment->amount, 2) }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $payment->created_at->format('M j, Y') }}
                                </p>
                            </div>
                        </div>
                        <span class="px-2 py-1 rounded-full text-xs font-medium
                            @if($payment->status === 'completed') bg-emerald-100 text-emerald-600
                            @elseif($payment->status === 'pending') bg-amber-100 text-amber-600
                            @else bg-red-100 text-red-600 @endif">
                            {{ ucfirst($payment->status) }}
                        </span>
                    </div>
                    @empty
                    <div class="text-center py-8">
                        <svg class="w-12 h-12 text-slate-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        <p class="text-slate-500">No recent payments</p>
                    </div>
                    @endforelse
                </div>
            </div>

            <!-- Recent Exam Results -->
            <div class="bg-white/70 backdrop-blur-sm rounded-2xl shadow-xl border border-white/50 p-6 hover:shadow-2xl transition-all duration-500">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">
                        Recent Results
                    </h2>
                    <a href="{{ route('parent.exams.index') }}"
                       class="text-sm text-emerald-600 hover:text-emerald-800 font-medium transition-colors duration-300">
                        View All →
                    </a>
                </div>
                <div class="space-y-4 max-h-80 overflow-y-auto">
                    @forelse($examResults as $result)
                    <div class="flex items-center justify-between p-4 rounded-xl bg-gradient-to-r from-slate-50 to-slate-100 hover:from-slate-100 hover:to-slate-200 transition-all duration-300">
                        <div class="flex items-center">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-r
                                @if($result->score >= 80) from-emerald-500 to-emerald-600
                                @elseif($result->score >= 60) from-amber-500 to-amber-600
                                @else from-red-500 to-red-600 @endif
                                flex items-center justify-center text-white font-bold text-sm mr-3">
                                {{ round($result->score) }}
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-900">
                                    {{ $result->exam->subject->name }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $result->exam->title }} • {{ $result->exam->exam_date->format('M j') }}
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold
                                @if($result->score >= 80) text-emerald-600
                                @elseif($result->score >= 60) text-amber-600
                                @else text-red-600 @endif">
                                {{ round($result->score) }}%
                            </p>
                        </div>
                    </div>
                    @empty
                    <div class="text-center py-8">
                        <svg class="w-12 h-12 text-slate-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <p class="text-slate-500">No recent exam results</p>
                    </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white/70 backdrop-blur-sm rounded-2xl shadow-xl border border-white/50 p-6 hover:shadow-2xl transition-all duration-500">
            <h2 class="text-xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent mb-6">
                Quick Actions
            </h2>
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                <a href="{{ route('parent.attendance.index') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-blue-50 to-blue-100 hover:from-blue-100 hover:to-blue-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-blue-500 flex items-center justify-center text-white mb-3 group-hover:bg-blue-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">Attendance</span>
                </a>

                <a href="{{ route('parent.exams.index') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-purple-50 to-purple-100 hover:from-purple-100 hover:to-purple-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-purple-500 flex items-center justify-center text-white mb-3 group-hover:bg-purple-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">Exam Results</span>
                </a>

                <a href="{{ route('parent.invoices.index') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-emerald-50 to-emerald-100 hover:from-emerald-100 hover:to-emerald-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500 flex items-center justify-center text-white mb-3 group-hover:bg-emerald-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">Pay Bills</span>
                </a>

                <a href="{{ route('parent.children.index') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-amber-50 to-amber-100 hover:from-amber-100 hover:to-amber-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-amber-500 flex items-center justify-center text-white mb-3 group-hover:bg-amber-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">My Children</span>
                </a>

                <a href="{{ route('parent.enrollments.index') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-rose-50 to-rose-100 hover:from-rose-100 hover:to-rose-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-rose-500 flex items-center justify-center text-white mb-3 group-hover:bg-rose-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">Enrollments</span>
                </a>

                <a href="{{ route('parent.schedule.index') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-indigo-50 to-indigo-100 hover:from-indigo-100 hover:to-indigo-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-indigo-500 flex items-center justify-center text-white mb-3 group-hover:bg-indigo-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">Schedule</span>
                </a>
            </div>
        </div>

        <!-- Pending Invoices Alert -->
        @if($pendingInvoices->count() > 0)
        <div class="bg-gradient-to-r from-amber-50 to-orange-100 border-l-4 border-amber-400 rounded-xl p-6 shadow-lg">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L4.35 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                </div>
                <div class="ml-3 flex-1">
                    <h3 class="text-lg font-medium text-amber-800">Payment Reminder</h3>
                    <p class="text-amber-700 mt-1">
                        You have {{ $pendingInvoices->count() }} pending invoice(s) that require payment.
                    </p>
                    <div class="mt-4 space-y-2">
                        @foreach($pendingInvoices as $invoice)
                        <div class="flex items-center justify-between bg-white/70 rounded-lg p-3">
                            <div>
                                <p class="font-medium text-amber-800">{{ $invoice->invoice_number }}</p>
                                <p class="text-sm text-amber-600">Due: {{ $invoice->due_date->format('M j, Y') }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-amber-800">${{ number_format($invoice->amount, 2) }}</p>
                                <a href="{{ route('parent.invoices.show', $invoice) }}"
                                   class="text-sm text-amber-600 hover:text-amber-800 font-medium">Pay Now →</a>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        @endif

        @else
        <!-- No Children State -->
        <div class="text-center py-16">
            <div class="w-24 h-24 bg-gradient-to-r from-emerald-100 to-teal-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-12 h-12 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                </svg>
            </div>
            <h3 class="text-2xl font-bold text-slate-800 mb-4">Welcome to Your Parent Dashboard</h3>
            <p class="text-slate-600 mb-8 max-w-md mx-auto">
                It looks like you don't have any children profiles set up yet. Add your child's information to get started.
            </p>
            <a href="{{ route('parent.children.create') }}"
               class="inline-flex items-center px-6 py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-medium shadow-lg hover:shadow-xl transform hover:scale-105 transition-all duration-300">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Add Child Profile
            </a>
        </div>
        @endif
    </div>
</div>
