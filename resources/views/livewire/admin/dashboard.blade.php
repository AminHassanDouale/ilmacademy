<?php
// resources/views/livewire/admin/dashboard.blade.php

use App\Models\User;
use App\Models\ChildProfile;
use App\Models\TeacherProfile;
use App\Models\ProgramEnrollment;
use App\Models\Payment;
use App\Models\Invoice;
use App\Models\ActivityLog;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use Carbon\Carbon;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public string $greeting = '';
    public $selectedPeriod = 'month';

    // Stats
    public $userStats = [];
    public $enrollmentStats = [];
    public $financialStats = [];
    public $activityStats = [];

    // Recent data
    public $recentEnrollments = [];
    public $recentPayments = [];
    public $recentActivity = [];
    public $overduePayments = [];
    public $upcomingEvents = [];

    // Charts
    public array $enrollmentChart = [
        'type' => 'line',
        'options' => [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'x' => [
                    'grid' => ['display' => false],
                    'ticks' => ['font' => ['size' => 10]]
                ],
                'y' => [
                    'beginAtZero' => true,
                    'grid' => ['borderDash' => [3, 3]],
                    'ticks' => ['stepSize' => 5, 'font' => ['size' => 10]]
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                    'labels' => [
                        'usePointStyle' => true,
                        'padding' => 15,
                        'font' => ['size' => 11]
                    ]
                ]
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
                    'label' => 'New Enrollments',
                    'data' => [],
                    'fill' => true,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.2)',
                    'borderColor' => 'rgba(59, 130, 246, 1)',
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Payments Received',
                    'data' => [],
                    'fill' => true,
                    'backgroundColor' => 'rgba(16, 185, 129, 0.2)',
                    'borderColor' => 'rgba(16, 185, 129, 1)',
                    'borderWidth' => 2,
                ],
            ],
        ],
    ];

    public array $paymentStatusChart = [
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
            'labels' => [],
            'datasets' => [
                [
                    'label' => 'Payments',
                    'data' => [],
                    'backgroundColor' => [
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(251, 191, 36, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(99, 102, 241, 0.8)',
                    ],
                    'borderColor' => [
                        'rgba(16, 185, 129, 1)',
                        'rgba(251, 191, 36, 1)',
                        'rgba(239, 68, 68, 1)',
                        'rgba(99, 102, 241, 1)',
                    ],
                    'borderWidth' => 1,
                ],
            ],
        ],
    ];

    public array $curriculumDistribution = [
        'type' => 'bar',
        'options' => [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'x' => ['grid' => ['display' => false]],
                'y' => [
                    'beginAtZero' => true,
                    'grid' => ['borderDash' => [3, 3]],
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
                    'label' => 'Students',
                    'data' => [],
                    'backgroundColor' => 'rgba(147, 51, 234, 0.6)',
                    'borderColor' => 'rgba(147, 51, 234, 1)',
                    'borderWidth' => 1,
                ],
            ],
        ],
    ];

    public function mount()
    {
        $this->greeting = $this->getGreeting();
        $this->loadStats();
        $this->loadRecentData();
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
        $greeting = 'Welcome, ' . ($user ? $user->name : 'Administrator') . '. ';

        if ($currentHour < 12) {
            $greeting .= 'Good morning!';
        } elseif ($currentHour < 18) {
            $greeting .= 'Good afternoon!';
        } else {
            $greeting .= 'Good evening!';
        }

        return $greeting;
    }

    private function loadStats()
    {
        // User Statistics
        $this->userStats = [
            'total_students' => User::role('student')->count() + ChildProfile::count(),
            'total_parents' => User::role('parent')->count(),
            'total_teachers' => User::role('teacher')->count(),
            'total_admins' => User::role('admin')->count(),
            'active_users' => User::where('last_login_at', '>=', Carbon::now()->subDays(30))->count(),
        ];

        // Enrollment Statistics
        $currentYear = AcademicYear::where('is_current', true)->first();
        $this->enrollmentStats = [
            'total_enrollments' => ProgramEnrollment::count(),
            'active_enrollments' => ProgramEnrollment::where('status', 'Active')->count(),
            'current_year_enrollments' => $currentYear ?
                ProgramEnrollment::where('academic_year_id', $currentYear->id)->count() : 0,
            'pending_enrollments' => ProgramEnrollment::where('status', 'Pending')->count(),
        ];

        // Financial Statistics
        $this->financialStats = [
            'total_revenue' => Payment::where('status', Payment::STATUS_COMPLETED)->sum('amount'),
            'pending_payments' => Payment::where('status', Payment::STATUS_PENDING)->sum('amount'),
            'overdue_payments' => Payment::where('status', Payment::STATUS_OVERDUE)->sum('amount'),
            'this_month_revenue' => Payment::where('status', Payment::STATUS_COMPLETED)
                ->whereMonth('payment_date', Carbon::now()->month)
                ->sum('amount'),
        ];

        // Activity Statistics
        $this->activityStats = [
            'today_activities' => ActivityLog::whereDate('created_at', Carbon::today())->count(),
            'week_activities' => ActivityLog::whereBetween('created_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            ])->count(),
        ];
    }

    private function loadRecentData()
    {
        // Recent Enrollments
        $this->recentEnrollments = ProgramEnrollment::with([
            'childProfile', 'curriculum', 'academicYear'
        ])->orderBy('created_at', 'desc')->take(5)->get();

        // Recent Payments
        $this->recentPayments = Payment::with([
            'student', 'invoice'
        ])->orderBy('created_at', 'desc')->take(5)->get();

        // Recent Activity
        $this->recentActivity = ActivityLog::with('user')
            ->orderBy('created_at', 'desc')->take(10)->get();

        // Overdue Payments
        $this->overduePayments = Payment::where('status', Payment::STATUS_OVERDUE)
            ->with(['student', 'invoice'])
            ->orderBy('due_date', 'asc')->take(5)->get();
    }

    public function refreshCharts(): void
    {
        $this->refreshEnrollmentChart();
        $this->refreshPaymentStatusChart();
        $this->refreshCurriculumDistribution();
    }

    private function refreshEnrollmentChart(): void
    {
        $now = Carbon::now();
        $periods = [];
        $enrollmentData = [];
        $paymentData = [];
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
                for ($i = 29; $i >= 0; $i -= 7) {
                    $start = $now->copy()->subDays($i);
                    $end = $now->copy()->subDays(max(0, $i - 6));
                    $periods[] = [
                        'start' => $start->startOfDay(),
                        'end' => $end->endOfDay(),
                        'label' => $start->format('M j')
                    ];
                }
                break;
            case 'year':
                for ($i = 11; $i >= 0; $i--) {
                    $month = $now->copy()->subMonths($i);
                    $periods[] = [
                        'start' => $month->copy()->startOfMonth(),
                        'end' => $month->copy()->endOfMonth(),
                        'label' => $month->format('M Y')
                    ];
                }
                break;
        }

        foreach ($periods as $period) {
            $labels[] = $period['label'];

            $enrollmentData[] = ProgramEnrollment::whereBetween('created_at', [
                $period['start'], $period['end']
            ])->count();

            $paymentData[] = Payment::where('status', Payment::STATUS_COMPLETED)
                ->whereBetween('payment_date', [$period['start'], $period['end']])
                ->count();
        }

        Arr::set($this->enrollmentChart, 'data.labels', $labels);
        Arr::set($this->enrollmentChart, 'data.datasets.0.data', $enrollmentData);
        Arr::set($this->enrollmentChart, 'data.datasets.1.data', $paymentData);
    }

    private function refreshPaymentStatusChart(): void
    {
        $paymentStats = [
            'Completed' => Payment::where('status', Payment::STATUS_COMPLETED)->count(),
            'Pending' => Payment::where('status', Payment::STATUS_PENDING)->count(),
            'Overdue' => Payment::where('status', Payment::STATUS_OVERDUE)->count(),
            'Failed' => Payment::where('status', Payment::STATUS_FAILED)->count(),
        ];

        Arr::set($this->paymentStatusChart, 'data.labels', array_keys($paymentStats));
        Arr::set($this->paymentStatusChart, 'data.datasets.0.data', array_values($paymentStats));
    }

    private function refreshCurriculumDistribution(): void
    {
        $curriculumData = Curriculum::withCount('programEnrollments')
            ->orderByDesc('program_enrollments_count')
            ->take(6)->get();

        $labels = $curriculumData->pluck('name')->toArray();
        $data = $curriculumData->pluck('program_enrollments_count')->toArray();

        Arr::set($this->curriculumDistribution, 'data.labels', $labels);
        Arr::set($this->curriculumDistribution, 'data.datasets.0.data', $data);
    }

    public function with(): array
    {
        return [
            'greeting' => $this->greeting,
            'userStats' => $this->userStats,
            'enrollmentStats' => $this->enrollmentStats,
            'financialStats' => $this->financialStats,
            'activityStats' => $this->activityStats,
            'recentEnrollments' => $this->recentEnrollments,
            'recentPayments' => $this->recentPayments,
            'recentActivity' => $this->recentActivity,
            'overduePayments' => $this->overduePayments,
            'enrollmentChart' => $this->enrollmentChart,
            'paymentStatusChart' => $this->paymentStatusChart,
            'curriculumDistribution' => $this->curriculumDistribution,
        ];
    }
};
?>

<div class="w-full min-h-screen bg-gradient-to-br from-slate-50 via-blue-50 to-indigo-50">
    <!-- Header with Enhanced Styling -->
    <div class="bg-white/80 backdrop-blur-sm shadow-lg border-b border-indigo-100">
        <div class="container mx-auto px-6 py-6">
            <div class="flex items-center justify-between">
                <div class="space-y-1">
                    <h1 class="text-3xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent animate-pulse">
                        {{ $greeting }}
                    </h1>
                    <p class="text-sm text-slate-600 font-medium">
                        {{ now()->format('l, F j, Y') }} • System Dashboard
                    </p>
                </div>
                <div class="flex items-center space-x-4">
                    <select wire:model.live="selectedPeriod"
                            class="px-4 py-2 rounded-xl border border-indigo-200 bg-white/70 text-slate-700 font-medium shadow-sm hover:shadow-md transition-all duration-300 focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="year">This Year</option>
                    </select>
                    <button class="p-3 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-600 text-white shadow-lg hover:shadow-xl transform hover:scale-105 transition-all duration-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-6 py-8 space-y-8">
        <!-- Enhanced Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <!-- Total Students -->
            <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 p-6 text-white shadow-xl hover:shadow-2xl transform hover:-translate-y-2 transition-all duration-500">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ number_format($userStats['total_students']) }}</span>
                    </div>
                    <h3 class="font-semibold text-blue-100">Total Students</h3>
                    <p class="text-sm text-blue-200 mt-1">Active learners in system</p>
                </div>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 rounded-full bg-white/10 group-hover:scale-110 transition-transform duration-500"></div>
            </div>

            <!-- Total Teachers -->
            <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-600 p-6 text-white shadow-xl hover:shadow-2xl transform hover:-translate-y-2 transition-all duration-500">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ number_format($userStats['total_teachers']) }}</span>
                    </div>
                    <h3 class="font-semibold text-emerald-100">Total Teachers</h3>
                    <p class="text-sm text-emerald-200 mt-1">Active teaching staff</p>
                </div>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 rounded-full bg-white/10 group-hover:scale-110 transition-transform duration-500"></div>
            </div>

            <!-- Active Enrollments -->
            <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-purple-500 to-purple-600 p-6 text-white shadow-xl hover:shadow-2xl transform hover:-translate-y-2 transition-all duration-500">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">{{ number_format($enrollmentStats['active_enrollments']) }}</span>
                    </div>
                    <h3 class="font-semibold text-purple-100">Active Enrollments</h3>
                    <p class="text-sm text-purple-200 mt-1">Current academic year</p>
                </div>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 rounded-full bg-white/10 group-hover:scale-110 transition-transform duration-500"></div>
            </div>

            <!-- Monthly Revenue -->
            <div class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-amber-500 to-orange-500 p-6 text-white shadow-xl hover:shadow-2xl transform hover:-translate-y-2 transition-all duration-500">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 to-transparent"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-4">
                        <div class="p-3 rounded-xl bg-white/20 backdrop-blur-sm">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
                            </svg>
                        </div>
                        <span class="text-3xl font-bold">${{ number_format($financialStats['this_month_revenue'], 0) }}</span>
                    </div>
                    <h3 class="font-semibold text-amber-100">Monthly Revenue</h3>
                    <p class="text-sm text-amber-200 mt-1">Current month earnings</p>
                </div>
                <div class="absolute -bottom-4 -right-4 w-24 h-24 rounded-full bg-white/10 group-hover:scale-110 transition-transform duration-500"></div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Enrollment Trends -->
            <div class="lg:col-span-2 bg-white/70 backdrop-blur-sm rounded-2xl shadow-xl border border-white/50 p-6 hover:shadow-2xl transition-all duration-500">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">
                        Enrollment & Payment Trends
                    </h2>
                    <div class="flex space-x-2">
                        <span class="px-3 py-1 rounded-full bg-blue-100 text-blue-600 text-xs font-medium">Enrollments</span>
                        <span class="px-3 py-1 rounded-full bg-emerald-100 text-emerald-600 text-xs font-medium">Payments</span>
                    </div>
                </div>
                <div class="h-80">
                    <x-chart wire:model="enrollmentChart" class="w-full h-full" />
                </div>
            </div>

            <!-- Payment Status -->
            <div class="bg-white/70 backdrop-blur-sm rounded-2xl shadow-xl border border-white/50 p-6 hover:shadow-2xl transition-all duration-500">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">
                        Payment Status
                    </h2>
                    <button class="p-2 rounded-lg bg-gradient-to-r from-slate-100 to-slate-200 hover:from-slate-200 hover:to-slate-300 transition-all duration-300">
                        <svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </button>
                </div>
                <div class="h-64">
                    <x-chart wire:model="paymentStatusChart" class="w-full h-full" />
                </div>
            </div>
        </div>

        <!-- Additional Sections -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Curriculum Distribution -->
            <div class="bg-white/70 backdrop-blur-sm rounded-2xl shadow-xl border border-white/50 p-6 hover:shadow-2xl transition-all duration-500">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">
                        Curriculum Distribution
                    </h2>
                </div>
                <div class="h-64">
                    <x-chart wire:model="curriculumDistribution" class="w-full h-full" />
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="lg:col-span-2 bg-white/70 backdrop-blur-sm rounded-2xl shadow-xl border border-white/50 p-6 hover:shadow-2xl transition-all duration-500">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent">
                        Recent System Activity
                    </h2>
                    <a href="{{ route('admin.activity-logs.index') }}"
                       class="text-sm text-indigo-600 hover:text-indigo-800 font-medium transition-colors duration-300">
                        View All →
                    </a>
                </div>
                <div class="space-y-4 max-h-64 overflow-y-auto">
                    @foreach($recentActivity as $activity)
                    <div class="flex items-start space-x-4 p-4 rounded-xl bg-gradient-to-r from-slate-50 to-slate-100 hover:from-slate-100 hover:to-slate-200 transition-all duration-300">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-r from-indigo-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm">
                            {{ substr($activity->user->name ?? 'System', 0, 2) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-slate-900">
                                {{ $activity->user->name ?? 'System' }}
                            </p>
                            <p class="text-sm text-slate-600">{{ $activity->description }}</p>
                            <p class="text-xs text-slate-400 mt-1">{{ $activity->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white/70 backdrop-blur-sm rounded-2xl shadow-xl border border-white/50 p-6 hover:shadow-2xl transition-all duration-500">
            <h2 class="text-xl font-bold bg-gradient-to-r from-slate-800 to-slate-600 bg-clip-text text-transparent mb-6">
                Quick Actions
            </h2>
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4">
                <a href="{{ route('admin.users.create') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-blue-50 to-blue-100 hover:from-blue-100 hover:to-blue-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-blue-500 flex items-center justify-center text-white mb-3 group-hover:bg-blue-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">Add User</span>
                </a>

                <a href="{{ route('admin.enrollments.create') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-emerald-50 to-emerald-100 hover:from-emerald-100 hover:to-emerald-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500 flex items-center justify-center text-white mb-3 group-hover:bg-emerald-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">New Enrollment</span>
                </a>

                <a href="{{ route('admin.invoices.create') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-purple-50 to-purple-100 hover:from-purple-100 hover:to-purple-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-purple-500 flex items-center justify-center text-white mb-3 group-hover:bg-purple-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">Create Invoice</span>
                </a>

                <a href="{{ route('admin.reports.finances') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-amber-50 to-amber-100 hover:from-amber-100 hover:to-amber-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-amber-500 flex items-center justify-center text-white mb-3 group-hover:bg-amber-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">Financial Report</span>
                </a>

                <a href="{{ route('admin.curricula.index') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-rose-50 to-rose-100 hover:from-rose-100 hover:to-rose-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-rose-500 flex items-center justify-center text-white mb-3 group-hover:bg-rose-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">Curricula</span>
                </a>

                <a href="{{ route('admin.teachers.index') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-indigo-50 to-indigo-100 hover:from-indigo-100 hover:to-indigo-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-indigo-500 flex items-center justify-center text-white mb-3 group-hover:bg-indigo-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">Teachers</span>
                </a>

                <a href="{{ route('admin.timetable.index') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-teal-50 to-teal-100 hover:from-teal-100 hover:to-teal-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-teal-500 flex items-center justify-center text-white mb-3 group-hover:bg-teal-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">Timetable</span>
                </a>

                <a href="{{ route('admin.settings.index') }}"
                   class="group flex flex-col items-center p-4 rounded-xl bg-gradient-to-br from-slate-50 to-slate-100 hover:from-slate-100 hover:to-slate-200 transition-all duration-300 transform hover:scale-105">
                    <div class="w-12 h-12 rounded-xl bg-slate-500 flex items-center justify-center text-white mb-3 group-hover:bg-slate-600 transition-colors duration-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors duration-300">Settings</span>
                </a>
            </div>
        </div>

        <!-- Alerts & Notifications -->
        @if($overduePayments->count() > 0)
        <div class="bg-gradient-to-r from-red-50 to-red-100 border-l-4 border-red-400 rounded-xl p-6 shadow-lg">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L4.35 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                </div>
                <div class="ml-3 flex-1">
                    <h3 class="text-lg font-medium text-red-800">Payment Alert</h3>
                    <p class="text-red-700 mt-1">
                        You have {{ $overduePayments->count() }} overdue payments requiring immediate attention.
                    </p>
                    <div class="mt-4">
                        <a href="{{ route('admin.invoices.index') }}?status=overdue"
                           class="inline-flex items-center px-4 py-2 rounded-lg bg-red-600 text-white font-medium hover:bg-red-700 transition-colors duration-300">
                            Review Overdue Payments
                            <svg class="ml-2 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
