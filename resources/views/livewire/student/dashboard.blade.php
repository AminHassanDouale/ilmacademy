<?php
// resources/views/livewire/student/dashboard.blade.php

use App\Models\ProgramEnrollment;
use App\Models\Session;
use App\Models\Attendance;
use App\Models\ExamResult;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;

new class extends Component {
    public string $greeting = '';
    public $selectedPeriod = 'month';

    // Student data
    public $studentProfile;
    public $studentStats = [];
    public $upcomingClasses = [];
    public $recentGrades = [];
    public $attendanceHistory = [];
    public $pendingPayments = [];
    public $academicProgress = [];

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
                'legend' => ['display' => false],
            ],
            'elements' => [
                'line' => ['tension' => 0.4],
                'point' => ['radius' => 4, 'hoverRadius' => 6]
            ]
        ],
        'data' => [
            'labels' => [],
            'datasets' => [
                [
                    'label' => 'Attendance Rate (%)',
                    'data' => [],
                    'fill' => true,
                    'backgroundColor' => 'rgba(16, 185, 129, 0.2)',
                    'borderColor' => 'rgba(16, 185, 129, 1)',
                    'borderWidth' => 3,
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
                'legend' => ['display' => false],
            ],
        ],
        'data' => [
            'labels' => [],
            'datasets' => [
                [
                    'label' => 'Subject Grades',
                    'data' => [],
                    'fill' => true,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.2)',
                    'borderColor' => 'rgba(59, 130, 246, 1)',
                    'borderWidth' => 2,
                ],
            ],
        ],
    ];

    public function mount()
    {
        $this->greeting = $this->getGreeting();
        $this->studentProfile = Auth::user()->childProfile;
        $this->loadStudentStats();
        $this->loadAcademicData();
        $this->loadFinancialData();
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
        $greeting = 'Welcome back, ' . ($user ? $user->name : 'Student') . '! ';

        if ($currentHour < 12) {
            $greeting .= 'Ready for a productive day?';
        } elseif ($currentHour < 18) {
            $greeting .= 'Keep up the great work!';
        } else {
            $greeting .= 'Time to review today\'s learning!';
        }

        return $greeting;
    }

    private function loadStudentStats()
    {
        if (!$this->studentProfile) return;

        // Get active enrollments
        $activeEnrollments = ProgramEnrollment::where('child_profile_id', $this->studentProfile->id)
            ->where('status', 'Active')
            ->with(['subjectEnrollments.subject'])
            ->get();

        // Calculate overall attendance rate
        $totalSessions = 0;
        $attendedSessions = 0;

        foreach ($activeEnrollments as $enrollment) {
            foreach ($enrollment->subjectEnrollments as $subjectEnrollment) {
                $sessions = Session::where('subject_id', $subjectEnrollment->subject_id)
                    ->where('start_time', '<=', now())
                    ->count();
                $totalSessions += $sessions;

                $attended = Attendance::where('child_profile_id', $this->studentProfile->id)
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
        $recentExams = ExamResult::where('child_profile_id', $this->studentProfile->id)
            ->whereHas('exam', function($q) {
                $q->where('exam_date', '>=', Carbon::now()->subMonths(3));
            })
            ->get();

        $averageGrade = $recentExams->count() > 0 ? round($recentExams->avg('score'), 1) : 0;

        // Get subjects count
        $totalSubjects = 0;
        foreach ($activeEnrollments as $enrollment) {
            $totalSubjects += $enrollment->subjectEnrollments->count();
        }

        // Get upcoming classes this week
        $upcomingClasses = Session::whereHas('subject.subjectEnrollments.programEnrollment', function($q) {
            $q->where('child_profile_id', $this->studentProfile->id)
              ->where('status', 'Active');
        })
        ->where('start_time', '>', Carbon::now())
        ->where('start_time', '<=', Carbon::now()->addDays(7))
        ->count();

        $this->studentStats = [
            'active_enrollments' => $activeEnrollments->count(),
            'total_subjects' => $totalSubjects,
            'attendance_rate' => $attendanceRate,
            'average_grade' => $averageGrade,
            'upcoming_classes' => $upcomingClasses,
        ];
    }

    private function loadAcademicData()
    {
        if (!$this->studentProfile) return;

        // Load upcoming classes
        $this->upcomingClasses = Session::whereHas('subject.subjectEnrollments.programEnrollment', function($q) {
            $q->where('child_profile_id', $this->studentProfile->id)
              ->where('status', 'Active');
        })
        ->with(['subject', 'teacherProfile.user'])
        ->where('start_time', '>', Carbon::now())
        ->where('start_time', '<=', Carbon::now()->addDays(7))
        ->orderBy('start_time')
        ->take(5)
        ->get();

        // Load recent grades
        $this->recentGrades = ExamResult::where('child_profile_id', $this->studentProfile->id)
            ->with(['exam.subject'])
            ->whereHas('exam', function($q) {
                $q->where('exam_date', '>=', Carbon::now()->subMonths(6));
            })
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        // Load attendance history
        $this->attendanceHistory = Attendance::where('child_profile_id', $this->studentProfile->id)
            ->with(['session.subject'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();
    }

    private function loadFinancialData()
    {
        if (!$this->studentProfile) return;

        // Load pending payments
        $this->pendingPayments = Invoice::where('child_profile_id', $this->studentProfile->id)
            ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_SENT])
            ->orderBy('due_date')
            ->take(3)
            ->get();
    }

    private function refreshCharts()
    {
        if (!$this->studentProfile) return;

        $this->refreshAttendanceChart();
        $this->refreshGradesChart();
    }

    private function refreshAttendanceChart()
    {
        $now = Carbon::now();
        $periods = [];

        // Get last 6 months
        for ($i = 5; $i >= 0; $i--) {
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
            $totalSessions = Session::whereHas('subject.subjectEnrollments.programEnrollment', function($q) {
                $q->where('child_profile_id', $this->studentProfile->id);
            })
            ->whereBetween('start_time', [$period['start'], $period['end']])
            ->where('start_time', '<=', now())
            ->count();

            $attendedSessions = Attendance::where('child_profile_id', $this->studentProfile->id)
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
        // Get recent exam results by subject
        $examResults = ExamResult::where('child_profile_id', $this->studentProfile->id)
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
            'studentStats' => $this->studentStats,
            'upcomingClasses' => $this->upcomingClasses,
            'recentGrades' => $this->recentGrades,
            'attendanceHistory' => $this->attendanceHistory,
            'pendingPayments' => $this->pendingPayments,
            'attendanceChart' => $this->attendanceChart,
            'gradesChart' => $this->gradesChart,
        ];
    }
};
?>

<div class="w-full min-h-screen bg-gradient-to-br from-cyan-50 via-blue-50 to-indigo-50">
    <!-- Header with Student-specific styling -->
    <div class="border-b shadow-lg bg-white/80 backdrop-blur-sm border-cyan-100">
        <div class="container px-6 py-6 mx-auto">
            <div class="flex items-center justify-between">
                <div class="space-y-1">
                    <h1 class="text-3xl font-bold text-transparent bg-gradient-to-r from-cyan-600 to-blue-600 bg-clip-text">
                        {{ $greeting }}
                    </h1>
                    <p class="text-sm font-medium text-slate-600">
                        {{ now()->format('l, F j, Y') }} • Student Portal
                    </p>
                </div>
                <div class="flex items-center space-x-4">
                    <button class="p-3 text-white transition-all duration-300 transform shadow-lg rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 hover:shadow-xl hover:scale-105">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container px-6 py-8 mx-auto space-y-8">
        <!-- Student Stats Grid -->
        @if($studentProfile)
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-5">
            <!-- Active Enrollments -->
            <div class="relative p-6 overflow-hidden text-white transition-all duration-500 transform shadow-xl group rounded-2xl bg-gradient-to-br from-cyan-500 to-cyan-600 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ $studentStats['active_enrollments'] ?? 0 }}</span>
                    </div>
                    <h3 class="font-semibold text-cyan-100">Programs</h3>
                    <p class="mt-1 text-sm text-cyan-200">Active</p>
                </div>
                <div class="absolute w-24 h-24 transition-transform duration-500 rounded-full -bottom-4 -right-4 bg-white/10 group-hover:scale-110"></div>
            </div>

            <!-- Total Subjects -->
            <div class="relative p-6 overflow-hidden text-white transition-all duration-500 transform shadow-xl group rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9.5a2.5 2.5 0 00-2.5-2.5H15" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ $studentStats['total_subjects'] ?? 0 }}</span>
                    </div>
                    <h3 class="font-semibold text-blue-100">Subjects</h3>
                    <p class="mt-1 text-sm text-blue-200">Enrolled</p>
                </div>
                <div class="absolute w-24 h-24 transition-transform duration-500 rounded-full -bottom-4 -right-4 bg-white/10 group-hover:scale-110"></div>
            </div>

            <!-- Attendance Rate -->
            <div class="relative p-6 overflow-hidden text-white transition-all duration-500 transform shadow-xl group rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-600 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ $studentStats['attendance_rate'] ?? 0 }}%</span>
                    </div>
                    <h3 class="font-semibold text-emerald-100">Attendance</h3>
                    <p class="mt-1 text-sm text-emerald-200">Overall rate</p>
                </div>
                <div class="absolute w-24 h-24 transition-transform duration-500 rounded-full -bottom-4 -right-4 bg-white/10 group-hover:scale-110"></div>
            </div>

            <!-- Average Grade -->
            <div class="relative p-6 overflow-hidden text-white transition-all duration-500 transform shadow-xl group rounded-2xl bg-gradient-to-br from-purple-500 to-purple-600 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ $studentStats['average_grade'] ?? 0 }}</span>
                    </div>
                    <h3 class="font-semibold text-purple-100">Avg Grade</h3>
                    <p class="mt-1 text-sm text-purple-200">Recent exams</p>
                </div>
                <div class="absolute w-24 h-24 transition-transform duration-500 rounded-full -bottom-4 -right-4 bg-white/10 group-hover:scale-110"></div>
            </div>

            <!-- Upcoming Classes -->
            <div class="relative p-6 overflow-hidden text-white transition-all duration-500 transform shadow-xl group rounded-2xl bg-gradient-to-br from-amber-500 to-orange-500 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ $studentStats['upcoming_classes'] ?? 0 }}</span>
                    </div>
                    <h3 class="font-semibold text-amber-100">This Week</h3>
                    <p class="mt-1 text-sm text-amber-200">Classes ahead</p>
                </div>
                <div class="absolute w-24 h-24 transition-transform duration-500 rounded-full -bottom-4 -right-4 bg-white/10 group-hover:scale-110"></div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
            <!-- Attendance Trends -->
            <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                        My Attendance Trends
                    </h2>
                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-emerald-100 text-emerald-600">Last 6 Months</span>
                </div>
                <div class="h-80">
                    <x-chart wire:model="attendanceChart" class="w-full h-full" />
                </div>
            </div>

            <!-- Subject Performance -->
            <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                        Subject Performance
                    </h2>
                    <span class="px-3 py-1 text-xs font-medium text-blue-600 bg-blue-100 rounded-full">Recent Exams</span>
                </div>
                <div class="h-80">
                    <x-chart wire:model="gradesChart" class="w-full h-full" />
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
            <!-- Upcoming Classes -->
            <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                        Upcoming Classes
                    </h2>
                    <a href="{{ route('student.schedule.index') }}"
                       class="text-sm font-medium transition-colors duration-300 text-cyan-600 hover:text-cyan-800">
                        View Schedule →
                    </a>
                </div>
                <div class="space-y-4 overflow-y-auto max-h-80">
                    @forelse($upcomingClasses as $class)
                    <div class="flex items-center p-4 transition-all duration-300 rounded-xl bg-gradient-to-r from-cyan-50 to-blue-50 hover:from-cyan-100 hover:to-blue-100">
                        <div class="flex items-center justify-center w-12 h-12 mr-4 text-sm font-bold text-white rounded-full bg-gradient-to-r from-cyan-500 to-blue-600">
                            {{ $class->start_time->format('j') }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium truncate text-slate-900">
                                {{ $class->subject->name }}
                            </p>
                            <p class="text-sm text-slate-600">
                                {{ $class->start_time->format('M j • g:i A') }}
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                Teacher: {{ $class->teacherProfile->user->name }}
                            </p>
                        </div>
                    </div>
                    @empty
                    <div class="py-8 text-center">
                        <svg class="w-12 h-12 mx-auto mb-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <p class="text-slate-500">No upcoming classes this week</p>
                    </div>
                    @endforelse
                </div>
            </div>

            <!-- Recent Grades -->
            <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                        Recent Grades
                    </h2>
                    <a href="{{ route('student.grades.index') }}"
                       class="text-sm font-medium transition-colors duration-300 text-cyan-600 hover:text-cyan-800">
                        View All →
                    </a>
                </div>
                <div class="space-y-4 overflow-y-auto max-h-80">
                    @forelse($recentGrades as $grade)
                    <div class="flex items-center justify-between p-4 transition-all duration-300 rounded-xl bg-gradient-to-r from-slate-50 to-slate-100 hover:from-slate-100 hover:to-slate-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-r
                                @if($grade->score >= 80) from-emerald-500 to-emerald-600
                                @elseif($grade->score >= 60) from-amber-500 to-amber-600
                                @else from-red-500 to-red-600 @endif
                                flex items-center justify-center text-white font-bold text-sm mr-3">
                                {{ round($grade->score) }}
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-900">
                                    {{ $grade->exam->subject->name }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $grade->exam->title }} • {{ $grade->exam->exam_date->format('M j') }}
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-bold
                                @if($grade->score >= 80) text-emerald-600
                                @elseif($grade->score >= 60) text-amber-600
                                @else text-red-600 @endif">
                                {{ round($grade->score) }}%
                            </p>
                            @if($grade->remarks)
                            <p class="text-xs truncate text-slate-500 max-w-20">{{ $grade->remarks }}</p>
                            @endif
                        </div>
                    </div>
                    @empty
                    <div class="py-8 text-center">
                        <svg class="w-12 h-12 mx-auto mb-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <p class="text-slate-500">No recent exam results</p>
                    </div>
                    @endforelse
                </div>
            </div>

            <!-- Recent Attendance -->
            <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                        Attendance History
                    </h2>
                    <a href="{{ route('student.attendance.history') }}"
                       class="text-sm font-medium transition-colors duration-300 text-cyan-600 hover:text-cyan-800">
                        View All →
                    </a>
                </div>
                <div class="space-y-4 overflow-y-auto max-h-80">
                    @forelse($attendanceHistory as $attendance)
                    <div class="flex items-center justify-between p-4 transition-all duration-300 rounded-xl bg-gradient-to-r from-slate-50 to-slate-100 hover:from-slate-100 hover:to-slate-200">
                        <div class="flex items-center">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-r
                                @if($attendance->status === 'present') from-emerald-500 to-emerald-600
                                @elseif($attendance->status === 'late') from-amber-500 to-amber-600
                                @else from-red-500 to-red-600 @endif
                                flex items-center justify-center text-white mr-3">
                                @if($attendance->status === 'present')
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                @elseif($attendance->status === 'late')
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                @else
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                @endif
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-900">
                                    {{ $attendance->session->subject->name }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $attendance->session->start_time->format('M j, Y • g:i A') }}
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="px-2 py-1 rounded-full text-xs font-medium
                                @if($attendance->status === 'present') bg-emerald-100 text-emerald-600
                                @elseif($attendance->status === 'late') bg-amber-100 text-amber-600
                                @else bg-red-100 text-red-600 @endif">
                                {{ ucfirst($attendance->status) }}
                            </span>
                        </div>
                    </div>
                    @empty
                    <div class="py-8 text-center">
                        <svg class="w-12 h-12 mx-auto mb-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p class="text-slate-500">No attendance records yet</p>
                    </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
            <h2 class="mb-6 text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                Quick Actions
            </h2>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-6">
                <a href="{{ route('student.sessions.index') }}"
                   class="flex flex-col items-center p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-br from-cyan-50 to-cyan-100 hover:from-cyan-100 hover:to-cyan-200 hover:scale-105">
                    <div class="flex items-center justify-center w-12 h-12 mb-3 text-white transition-colors duration-300 rounded-xl bg-cyan-500 group-hover:bg-cyan-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium transition-colors duration-300 text-slate-700 group-hover:text-slate-900">My Classes</span>
                </a>

                <a href="{{ route('student.exams.index') }}"
                   class="flex flex-col items-center p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-br from-blue-50 to-blue-100 hover:from-blue-100 hover:to-blue-200 hover:scale-105">
                    <div class="flex items-center justify-center w-12 h-12 mb-3 text-white transition-colors duration-300 bg-blue-500 rounded-xl group-hover:bg-blue-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium transition-colors duration-300 text-slate-700 group-hover:text-slate-900">Exams</span>
                </a>

                <a href="{{ route('student.grades.index') }}"
                   class="flex flex-col items-center p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-br from-purple-50 to-purple-100 hover:from-purple-100 hover:to-purple-200 hover:scale-105">
                    <div class="flex items-center justify-center w-12 h-12 mb-3 text-white transition-colors duration-300 bg-purple-500 rounded-xl group-hover:bg-purple-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium transition-colors duration-300 text-slate-700 group-hover:text-slate-900">Grades</span>
                </a>

                <a href="{{ route('student.enrollments.index') }}"
                   class="flex flex-col items-center p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-br from-emerald-50 to-emerald-100 hover:from-emerald-100 hover:to-emerald-200 hover:scale-105">
                    <div class="flex items-center justify-center w-12 h-12 mb-3 text-white transition-colors duration-300 rounded-xl bg-emerald-500 group-hover:bg-emerald-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium transition-colors duration-300 text-slate-700 group-hover:text-slate-900">Enrollments</span>
                </a>

                <a href="{{ route('student.invoices.index') }}"
                   class="flex flex-col items-center p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-br from-amber-50 to-amber-100 hover:from-amber-100 hover:to-amber-200 hover:scale-105">
                    <div class="flex items-center justify-center w-12 h-12 mb-3 text-white transition-colors duration-300 rounded-xl bg-amber-500 group-hover:bg-amber-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium transition-colors duration-300 text-slate-700 group-hover:text-slate-900">Payments</span>
                </a>

                <a href="{{ route('student.schedule.index') }}"
                   class="flex flex-col items-center p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-br from-rose-50 to-rose-100 hover:from-rose-100 hover:to-rose-200 hover:scale-105">
                    <div class="flex items-center justify-center w-12 h-12 mb-3 text-white transition-colors duration-300 rounded-xl bg-rose-500 group-hover:bg-rose-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium transition-colors duration-300 text-slate-700 group-hover:text-slate-900">Schedule</span>
                </a>
            </div>
        </div>

        <!-- Pending Payments Alert -->
        @if($pendingPayments->count() > 0)
        <div class="p-6 border-l-4 shadow-lg bg-gradient-to-r from-amber-50 to-orange-100 border-amber-400 rounded-xl">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L4.35 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                </div>
                <div class="flex-1 ml-3">
                    <h3 class="text-lg font-medium text-amber-800">Payment Reminder</h3>
                    <p class="mt-1 text-amber-700">
                        You have {{ $pendingPayments->count() }} pending payment(s) that require attention.
                    </p>
                    <div class="mt-4 space-y-2">
                        @foreach($pendingPayments as $invoice)
                        <div class="flex items-center justify-between p-3 rounded-lg bg-white/70">
                            <div>
                                <p class="font-medium text-amber-800">{{ $invoice->invoice_number }}</p>
                                <p class="text-sm text-amber-600">Due: {{ $invoice->due_date->format('M j, Y') }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-amber-800">${{ number_format($invoice->amount, 2) }}</p>
                                <a href="{{ route('student.invoices.show', $invoice) }}"
                                   class="text-sm font-medium text-amber-600 hover:text-amber-800">Pay Now →</a>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        @endif

        @else
        <!-- No Profile State -->
        <div class="py-16 text-center">
            <div class="flex items-center justify-center w-24 h-24 mx-auto mb-6 rounded-full bg-gradient-to-r from-cyan-100 to-blue-100">
                <svg class="w-12 h-12 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
            </div>
            <h3 class="mb-4 text-2xl font-bold text-slate-800">Welcome to Your Student Portal</h3>
            <p class="max-w-md mx-auto mb-8 text-slate-600">
                Your student profile is being set up. Please contact your administrator or parent to complete your enrollment.
            </p>
            <a href="{{ route('student.profile.edit') }}"
               class="inline-flex items-center px-6 py-3 font-medium text-white transition-all duration-300 transform shadow-lg rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 hover:shadow-xl hover:scale-105">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                Complete Profile
            </a>
        </div>
        @endif
    </div>
</div>
