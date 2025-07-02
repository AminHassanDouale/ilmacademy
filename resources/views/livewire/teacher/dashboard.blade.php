<?php
// resources/views/livewire/teacher/dashboard.blade.php

use App\Models\Session;
use App\Models\Subject;
use App\Models\Attendance;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\SubjectEnrollment;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;

new class extends Component {
    public string $greeting = '';
    public $selectedPeriod = 'week';

    // Teacher data
    public $teacherProfile;
    public $teachingStats = [];
    public $todaysSessions = [];
    public $upcomingSessions = [];
    public $recentExams = [];
    public $myStudents = [];
    public $pendingTasks = [];

    // Charts
    public array $attendanceChart = [
        'type' => 'bar',
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
                    'backgroundColor' => 'rgba(59, 130, 246, 0.6)',
                    'borderColor' => 'rgba(59, 130, 246, 1)',
                    'borderWidth' => 1,
                ],
            ],
        ],
    ];

    public array $gradeDistribution = [
        'type' => 'doughnut',
        'options' => [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'position' => 'right',
                    'labels' => [
                        'usePointStyle' => true,
                        'padding' => 20,
                        'font' => ['size' => 11]
                    ],
                ],
            ],
            'cutout' => '65%',
        ],
        'data' => [
            'labels' => ['A (90-100%)', 'B (80-89%)', 'C (70-79%)', 'D (60-69%)', 'F (Below 60%)'],
            'datasets' => [
                [
                    'label' => 'Grade Distribution',
                    'data' => [],
                    'backgroundColor' => [
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(251, 191, 36, 0.8)',
                        'rgba(245, 101, 101, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                    ],
                    'borderColor' => [
                        'rgba(16, 185, 129, 1)',
                        'rgba(59, 130, 246, 1)',
                        'rgba(251, 191, 36, 1)',
                        'rgba(245, 101, 101, 1)',
                        'rgba(239, 68, 68, 1)',
                    ],
                    'borderWidth' => 1,
                ],
            ],
        ],
    ];

    public array $sessionTrends = [
        'type' => 'line',
        'options' => [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'x' => ['grid' => ['display' => false]],
                'y' => ['beginAtZero' => true],
            ],
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'top'],
            ],
            'elements' => [
                'line' => ['tension' => 0.4],
                'point' => ['radius' => 3, 'hoverRadius' => 5]
            ]
        ],
        'data' => [
            'labels' => [],
            'datasets' => [
                [
                    'label' => 'Sessions Conducted',
                    'data' => [],
                    'fill' => true,
                    'backgroundColor' => 'rgba(147, 51, 234, 0.2)',
                    'borderColor' => 'rgba(147, 51, 234, 1)',
                    'borderWidth' => 2,
                ],
            ],
        ],
    ];

    public function mount()
    {
        $this->greeting = $this->getGreeting();
        $this->teacherProfile = Auth::user()->teacherProfile;
        $this->loadTeachingStats();
        $this->loadSessionData();
        $this->loadStudentData();
        $this->loadExamData();
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
        $greeting = 'Welcome, ' . ($user ? $user->name : 'Teacher') . '! ';

        if ($currentHour < 12) {
            $greeting .= 'Ready for a great day of teaching?';
        } elseif ($currentHour < 18) {
            $greeting .= 'Hope your classes are going well!';
        } else {
            $greeting .= 'Hope you had a productive day!';
        }

        return $greeting;
    }

    private function loadTeachingStats()
    {
        if (!$this->teacherProfile) return;

        // Get subjects taught by this teacher
        $subjects = $this->teacherProfile->subjects;

        // Get total students across all subjects
        $totalStudents = SubjectEnrollment::whereIn('subject_id', $subjects->pluck('id'))
            ->distinct('program_enrollment_id')
            ->count();

        // Get sessions this week
        $weekSessions = Session::where('teacher_profile_id', $this->teacherProfile->id)
            ->whereBetween('start_time', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            ])
            ->count();

        // Get average attendance
        $attendanceData = Attendance::whereHas('session', function($q) {
            $q->where('teacher_profile_id', $this->teacherProfile->id);
        })
        ->selectRaw('
            COUNT(*) as total_attendance,
            SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present_count
        ')
        ->first();

        $averageAttendance = $attendanceData && $attendanceData->total_attendance > 0
            ? round(($attendanceData->present_count / $attendanceData->total_attendance) * 100, 1)
            : 0;

        // Get recent exams average
        $recentExamsAvg = ExamResult::whereHas('exam', function($q) {
            $q->where('teacher_profile_id', $this->teacherProfile->id)
              ->where('exam_date', '>=', Carbon::now()->subMonths(3));
        })->avg('score');

        $this->teachingStats = [
            'total_subjects' => $subjects->count(),
            'total_students' => $totalStudents,
            'week_sessions' => $weekSessions,
            'average_attendance' => $averageAttendance,
            'recent_exams_avg' => $recentExamsAvg ? round($recentExamsAvg, 1) : 0,
        ];
    }

    private function loadSessionData()
    {
        if (!$this->teacherProfile) return;

        // Today's sessions
        $this->todaysSessions = Session::where('teacher_profile_id', $this->teacherProfile->id)
            ->with(['subject', 'classroom'])
            ->whereDate('start_time', Carbon::today())
            ->orderBy('start_time')
            ->get();

        // Upcoming sessions (next 7 days)
        $this->upcomingSessions = Session::where('teacher_profile_id', $this->teacherProfile->id)
            ->with(['subject', 'classroom'])
            ->where('start_time', '>', Carbon::now())
            ->where('start_time', '<=', Carbon::now()->addDays(7))
            ->orderBy('start_time')
            ->take(5)
            ->get();
    }

    private function loadStudentData()
    {
        if (!$this->teacherProfile) return;

        // Get students from all subjects taught
        $this->myStudents = SubjectEnrollment::whereIn('subject_id', $this->teacherProfile->subjects->pluck('id'))
            ->with(['programEnrollment.childProfile', 'subject'])
            ->take(10)
            ->get();
    }

    private function loadExamData()
    {
        if (!$this->teacherProfile) return;

        // Recent exams
        $this->recentExams = Exam::where('teacher_profile_id', $this->teacherProfile->id)
            ->with(['subject', 'examResults'])
            ->orderBy('exam_date', 'desc')
            ->take(5)
            ->get();

        // Pending tasks (exams to grade, etc.)
        $this->pendingTasks = [
            'exams_to_grade' => Exam::where('teacher_profile_id', $this->teacherProfile->id)
                ->whereHas('examResults', function($q) {
                    $q->whereNull('score');
                })
                ->count(),
            'attendance_to_take' => Session::where('teacher_profile_id', $this->teacherProfile->id)
                ->where('start_time', '<', Carbon::now())
                ->whereDoesntHave('attendances')
                ->count(),
        ];
    }

    private function refreshCharts()
    {
        if (!$this->teacherProfile) return;

        $this->refreshAttendanceChart();
        $this->refreshGradeDistribution();
        $this->refreshSessionTrends();
    }

    private function refreshAttendanceChart()
    {
        // Get attendance data by subject
        $subjects = $this->teacherProfile->subjects;
        $attendanceData = [];
        $labels = [];

        foreach ($subjects as $subject) {
            $labels[] = $subject->name;

            $attendanceStats = Attendance::whereHas('session', function($q) use ($subject) {
                $q->where('subject_id', $subject->id)
                  ->where('teacher_profile_id', $this->teacherProfile->id);
            })
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present
            ')
            ->first();

            $rate = $attendanceStats && $attendanceStats->total > 0
                ? round(($attendanceStats->present / $attendanceStats->total) * 100, 1)
                : 0;

            $attendanceData[] = $rate;
        }

        Arr::set($this->attendanceChart, 'data.labels', $labels);
        Arr::set($this->attendanceChart, 'data.datasets.0.data', $attendanceData);
    }

    private function refreshGradeDistribution()
    {
        // Get grade distribution from recent exams
        $examResults = ExamResult::whereHas('exam', function($q) {
            $q->where('teacher_profile_id', $this->teacherProfile->id)
              ->where('exam_date', '>=', Carbon::now()->subMonths(6));
        })->get();

        $gradeDistribution = [
            0, 0, 0, 0, 0 // A, B, C, D, F
        ];

        foreach ($examResults as $result) {
            if ($result->score >= 90) $gradeDistribution[0]++;
            elseif ($result->score >= 80) $gradeDistribution[1]++;
            elseif ($result->score >= 70) $gradeDistribution[2]++;
            elseif ($result->score >= 60) $gradeDistribution[3]++;
            else $gradeDistribution[4]++;
        }

        Arr::set($this->gradeDistribution, 'data.datasets.0.data', $gradeDistribution);
    }

    private function refreshSessionTrends()
    {
        $now = Carbon::now();
        $periods = [];
        $sessionData = [];
        $labels = [];

        switch ($this->selectedPeriod) {
            case 'week':
                for ($i = 6; $i >= 0; $i--) {
                    $day = $now->copy()->subDays($i);
                    $periods[] = [
                        'start' => $day->copy()->startOfDay(),
                        'end' => $day->copy()->endOfDay(),
                        'label' => $day->format('M j')
                    ];
                }
                break;
            case 'month':
                for ($i = 3; $i >= 0; $i--) {
                    $week = $now->copy()->subWeeks($i);
                    $periods[] = [
                        'start' => $week->copy()->startOfWeek(),
                        'end' => $week->copy()->endOfWeek(),
                        'label' => 'Week ' . $week->format('W')
                    ];
                }
                break;
        }

        foreach ($periods as $period) {
            $labels[] = $period['label'];

            $sessionCount = Session::where('teacher_profile_id', $this->teacherProfile->id)
                ->whereBetween('start_time', [$period['start'], $period['end']])
                ->count();

            $sessionData[] = $sessionCount;
        }

        Arr::set($this->sessionTrends, 'data.labels', $labels);
        Arr::set($this->sessionTrends, 'data.datasets.0.data', $sessionData);
    }

    public function with(): array
    {
        return [
            'greeting' => $this->greeting,
            'teachingStats' => $this->teachingStats,
            'todaysSessions' => $this->todaysSessions,
            'upcomingSessions' => $this->upcomingSessions,
            'recentExams' => $this->recentExams,
            'myStudents' => $this->myStudents,
            'pendingTasks' => $this->pendingTasks,
            'attendanceChart' => $this->attendanceChart,
            'gradeDistribution' => $this->gradeDistribution,
            'sessionTrends' => $this->sessionTrends,
        ];
    }
};
?>

<div class="w-full min-h-screen bg-gradient-to-br from-purple-50 via-indigo-50 to-blue-50">
    <!-- Header with Teacher-specific styling -->
    <div class="border-b border-purple-100 shadow-lg bg-white/80 backdrop-blur-sm">
        <div class="container px-6 py-6 mx-auto">
            <div class="flex items-center justify-between">
                <div class="space-y-1">
                    <h1 class="text-3xl font-bold text-transparent bg-gradient-to-r from-purple-600 to-indigo-600 bg-clip-text">
                        {{ $greeting }}
                    </h1>
                    <p class="text-sm font-medium text-slate-600">
                        {{ now()->format('l, F j, Y') }} • Teacher Dashboard
                    </p>
                </div>
                <div class="flex items-center space-x-4">
                    <select wire:model.live="selectedPeriod"
                            class="px-4 py-2 font-medium transition-all duration-300 border border-purple-200 shadow-sm rounded-xl bg-white/70 text-slate-700 hover:shadow-md">
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                    </select>
                    <button class="p-3 text-white transition-all duration-300 transform shadow-lg rounded-xl bg-gradient-to-r from-purple-500 to-indigo-600 hover:shadow-xl hover:scale-105">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container px-6 py-8 mx-auto space-y-8">
        <!-- Teaching Stats Grid -->
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-5">
            <!-- Total Subjects -->
            <div class="relative p-6 overflow-hidden text-white transition-all duration-500 transform shadow-xl group rounded-2xl bg-gradient-to-br from-purple-500 to-purple-600 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ $teachingStats['total_subjects'] ?? 0 }}</span>
                    </div>
                    <h3 class="font-semibold text-purple-100">Subjects</h3>
                    <p class="mt-1 text-sm text-purple-200">Teaching</p>
                </div>
                <div class="absolute w-24 h-24 transition-transform duration-500 rounded-full -bottom-4 -right-4 bg-white/10 group-hover:scale-110"></div>
            </div>

            <!-- This Week's Sessions -->
            <div class="relative p-6 overflow-hidden text-white transition-all duration-500 transform shadow-xl group rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-600 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ $teachingStats['week_sessions'] ?? 0 }}</span>
                    </div>
                    <h3 class="font-semibold text-emerald-100">Sessions</h3>
                    <p class="mt-1 text-sm text-emerald-200">This week</p>
                </div>
                <div class="absolute w-24 h-24 transition-transform duration-500 rounded-full -bottom-4 -right-4 bg-white/10 group-hover:scale-110"></div>
            </div>

            <!-- Attendance Rate -->
            <div class="relative p-6 overflow-hidden text-white transition-all duration-500 transform shadow-xl group rounded-2xl bg-gradient-to-br from-amber-500 to-orange-500 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ $teachingStats['average_attendance'] ?? 0 }}%</span>
                    </div>
                    <h3 class="font-semibold text-amber-100">Attendance</h3>
                    <p class="mt-1 text-sm text-amber-200">Average rate</p>
                </div>
                <div class="absolute w-24 h-24 transition-transform duration-500 rounded-full -bottom-4 -right-4 bg-white/10 group-hover:scale-110"></div>
            </div>

            <!-- Average Grade -->
            <div class="relative p-6 overflow-hidden text-white transition-all duration-500 transform shadow-xl group rounded-2xl bg-gradient-to-br from-rose-500 to-pink-500 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ $teachingStats['recent_exams_avg'] ?? 0 }}</span>
                    </div>
                    <h3 class="font-semibold text-rose-100">Avg Grade</h3>
                    <p class="mt-1 text-sm text-rose-200">Recent exams</p>
                </div>
                <div class="absolute w-24 h-24 transition-transform duration-500 rounded-full -bottom-4 -right-4 bg-white/10 group-hover:scale-110"></div>
            </div>
        </div>

        <!-- Today's Schedule -->
        @if($todaysSessions->count() > 0)
        <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                    Today's Schedule
                </h2>
                <span class="px-3 py-1 text-sm font-medium text-purple-600 bg-purple-100 rounded-full">
                    {{ $todaysSessions->count() }} sessions
                </span>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach($todaysSessions as $session)
                <div class="p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-r from-purple-50 to-indigo-50 hover:from-purple-100 hover:to-indigo-100 hover:scale-105">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-semibold text-slate-900">{{ $session->subject->name }}</h3>
                        <span class="text-sm text-slate-500">
                            {{ $session->start_time->format('g:i A') }}
                        </span>
                    </div>
                    <div class="space-y-1 text-sm text-slate-600">
                        <p class="flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            {{ $session->start_time->format('g:i A') }} - {{ $session->end_time->format('g:i A') }}
                        </p>
                        @if($session->classroom)
                        <p class="flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            {{ $session->classroom->name }}
                        </p>
                        @endif
                    </div>
                    <div class="flex mt-3 space-x-2">
                        <a href="{{ route('teacher.sessions.show', $session) }}"
                           class="px-3 py-1 text-xs text-white transition-colors duration-300 bg-purple-500 rounded-lg hover:bg-purple-600">
                            View Details
                        </a>
                        <a href="{{ route('teacher.attendance.take', $session) }}"
                           class="px-3 py-1 text-xs text-white transition-colors duration-300 rounded-lg bg-emerald-500 hover:bg-emerald-600">
                            Take Attendance
                        </a>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        <!-- Charts Section -->
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
            <!-- Attendance by Subject -->
            <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                        Attendance by Subject
                    </h2>
                </div>
                <div class="h-80">
                    <x-chart wire:model="attendanceChart" class="w-full h-full" />
                </div>
            </div>

            <!-- Grade Distribution -->
            <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                        Grade Distribution
                    </h2>
                </div>
                <div class="h-80">
                    <x-chart wire:model="gradeDistribution" class="w-full h-full" />
                </div>
            </div>

            <!-- Session Trends -->
            <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                        Session Trends
                    </h2>
                </div>
                <div class="h-80">
                    <x-chart wire:model="sessionTrends" class="w-full h-full" />
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
            <!-- Upcoming Sessions -->
            <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                        Upcoming Sessions
                    </h2>
                    <a href="{{ route('teacher.sessions.index') }}"
                       class="text-sm font-medium text-purple-600 transition-colors duration-300 hover:text-purple-800">
                        View All →
                    </a>
                </div>
                <div class="space-y-4 overflow-y-auto max-h-80">
                    @forelse($upcomingSessions as $session)
                    <div class="flex items-center p-4 transition-all duration-300 rounded-xl bg-gradient-to-r from-purple-50 to-indigo-50 hover:from-purple-100 hover:to-indigo-100">
                        <div class="flex items-center justify-center w-12 h-12 mr-4 text-sm font-bold text-white rounded-full bg-gradient-to-r from-purple-500 to-indigo-600">
                            {{ $session->start_time->format('j') }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium truncate text-slate-900">
                                {{ $session->subject->name }}
                            </p>
                            <p class="text-sm text-slate-600">
                                {{ $session->start_time->format('M j • g:i A') }}
                            </p>
                            @if($session->classroom)
                            <p class="mt-1 text-xs text-slate-500">
                                Room: {{ $session->classroom->name }}
                            </p>
                            @endif
                        </div>
                    </div>
                    @empty
                    <div class="py-8 text-center">
                        <svg class="w-12 h-12 mx-auto mb-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <p class="text-slate-500">No upcoming sessions</p>
                    </div>
                    @endforelse
                </div>
            </div>

            <!-- Recent Exams -->
            <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                        Recent Exams
                    </h2>
                    <a href="{{ route('teacher.exams.index') }}"
                       class="text-sm font-medium text-purple-600 transition-colors duration-300 hover:text-purple-800">
                        View All →
                    </a>
                </div>
                <div class="space-y-4 overflow-y-auto max-h-80">
                    @forelse($recentExams as $exam)
                    <div class="flex items-center justify-between p-4 transition-all duration-300 rounded-xl bg-gradient-to-r from-slate-50 to-slate-100 hover:from-slate-100 hover:to-slate-200">
                        <div class="flex items-center">
                            <div class="flex items-center justify-center w-10 h-10 mr-3 rounded-full bg-gradient-to-r from-purple-500 to-indigo-600">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-900">
                                    {{ $exam->title }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $exam->subject->name }} • {{ $exam->exam_date->format('M j, Y') }}
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-slate-700">
                                {{ $exam->examResults->count() }} results
                            </p>
                            @if($exam->examResults->count() > 0)
                            <p class="text-xs text-slate-500">
                                Avg: {{ round($exam->examResults->avg('score'), 1) }}%
                            </p>
                            @endif
                        </div>
                    </div>
                    @empty
                    <div class="py-8 text-center">
                        <svg class="w-12 h-12 mx-auto mb-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <p class="text-slate-500">No recent exams</p>
                    </div>
                    @endforelse
                </div>
            </div>

            <!-- My Students -->
            <div class="p-6 transition-all duration-500 border shadow-xl bg-white/70 backdrop-blur-sm rounded-2xl border-white/50 hover:shadow-2xl">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-transparent bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text">
                        My Students
                    </h2>
                    <a href="{{ route('teacher.students.index') }}"
                       class="text-sm font-medium text-purple-600 transition-colors duration-300 hover:text-purple-800">
                        View All →
                    </a>
                </div>
                <div class="space-y-4 overflow-y-auto max-h-80">
                    @forelse($myStudents as $enrollment)
                    <div class="flex items-center p-4 transition-all duration-300 rounded-xl bg-gradient-to-r from-slate-50 to-slate-100 hover:from-slate-100 hover:to-slate-200">
                        <div class="flex items-center justify-center w-10 h-10 mr-3 text-sm font-bold text-white rounded-full bg-gradient-to-r from-purple-500 to-indigo-600">
                            {{ $enrollment->programEnrollment->childProfile->initials }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-slate-900">
                                {{ $enrollment->programEnrollment->childProfile->full_name }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ $enrollment->subject->name }}
                            </p>
                        </div>
                    </div>
                    @empty
                    <div class="py-8 text-center">
                        <svg class="w-12 h-12 mx-auto mb-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                        </svg>
                        <p class="text-slate-500">No students enrolled</p>
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
                <a href="{{ route('teacher.sessions.create') }}"
                   class="flex flex-col items-center p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-br from-purple-50 to-purple-100 hover:from-purple-100 hover:to-purple-200 hover:scale-105">
                    <div class="flex items-center justify-center w-12 h-12 mb-3 text-white transition-colors duration-300 bg-purple-500 rounded-xl group-hover:bg-purple-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium transition-colors duration-300 text-slate-700 group-hover:text-slate-900">Create Session</span>
                </a>

                <a href="{{ route('teacher.exams.create') }}"
                   class="flex flex-col items-center p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-br from-indigo-50 to-indigo-100 hover:from-indigo-100 hover:to-indigo-200 hover:scale-105">
                    <div class="flex items-center justify-center w-12 h-12 mb-3 text-white transition-colors duration-300 bg-indigo-500 rounded-xl group-hover:bg-indigo-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium transition-colors duration-300 text-slate-700 group-hover:text-slate-900">Create Exam</span>
                </a>

                <a href="{{ route('teacher.attendance.index') }}"
                   class="flex flex-col items-center p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-br from-emerald-50 to-emerald-100 hover:from-emerald-100 hover:to-emerald-200 hover:scale-105">
                    <div class="flex items-center justify-center w-12 h-12 mb-3 text-white transition-colors duration-300 rounded-xl bg-emerald-500 group-hover:bg-emerald-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium transition-colors duration-300 text-slate-700 group-hover:text-slate-900">Take Attendance</span>
                </a>

                <a href="{{ route('teacher.subjects.index') }}"
                   class="flex flex-col items-center p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-br from-amber-50 to-amber-100 hover:from-amber-100 hover:to-amber-200 hover:scale-105">
                    <div class="flex items-center justify-center w-12 h-12 mb-3 text-white transition-colors duration-300 rounded-xl bg-amber-500 group-hover:bg-amber-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium transition-colors duration-300 text-slate-700 group-hover:text-slate-900">My Subjects</span>
                </a>

                <a href="{{ route('teacher.timetable.index') }}"
                   class="flex flex-col items-center p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-br from-rose-50 to-rose-100 hover:from-rose-100 hover:to-rose-200 hover:scale-105">
                    <div class="flex items-center justify-center w-12 h-12 mb-3 text-white transition-colors duration-300 rounded-xl bg-rose-500 group-hover:bg-rose-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium transition-colors duration-300 text-slate-700 group-hover:text-slate-900">My Timetable</span>
                </a>

                <a href="{{ route('teacher.students.index') }}"
                   class="flex flex-col items-center p-4 transition-all duration-300 transform group rounded-xl bg-gradient-to-br from-teal-50 to-teal-100 hover:from-teal-100 hover:to-teal-200 hover:scale-105">
                    <div class="flex items-center justify-center w-12 h-12 mb-3 text-white transition-colors duration-300 bg-teal-500 rounded-xl group-hover:bg-teal-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium transition-colors duration-300 text-slate-700 group-hover:text-slate-900">My Students</span>
                </a>
            </div>
        </div>

        <!-- Pending Tasks Alert -->
        @if($pendingTasks['exams_to_grade'] > 0 || $pendingTasks['attendance_to_take'] > 0)
        <div class="p-6 border-l-4 shadow-lg bg-gradient-to-r from-amber-50 to-orange-100 border-amber-400 rounded-xl">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L4.35 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                </div>
                <div class="flex-1 ml-3">
                    <h3 class="text-lg font-medium text-amber-800">Pending Tasks</h3>
                    <div class="mt-2 space-y-2">
                        @if($pendingTasks['exams_to_grade'] > 0)
                        <p class="text-amber-700">
                            You have {{ $pendingTasks['exams_to_grade'] }} exam(s) that need grading.
                        </p>
                        @endif
                        @if($pendingTasks['attendance_to_take'] > 0)
                        <p class="text-amber-700">
                            You have {{ $pendingTasks['attendance_to_take'] }} session(s) with missing attendance records.
                        </p>
                        @endif
                    </div>
                    <div class="flex mt-4 space-x-3">
                        @if($pendingTasks['exams_to_grade'] > 0)
                        <a href="{{ route('teacher.exams.index') }}?filter=pending_grading"
                           class="inline-flex items-center px-4 py-2 font-medium text-white transition-colors duration-300 rounded-lg bg-amber-600 hover:bg-amber-700">
                            Grade Exams
                            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                        @endif
                        @if($pendingTasks['attendance_to_take'] > 0)
                        <a href="{{ route('teacher.attendance.index') }}?filter=missing"
                           class="inline-flex items-center px-4 py-2 font-medium text-white transition-colors duration-300 rounded-lg bg-emerald-600 hover:bg-emerald-700">
                            Take Attendance
                            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
