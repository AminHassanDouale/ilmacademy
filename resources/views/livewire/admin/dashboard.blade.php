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
                    'backgroundColor' => 'rgba(255, 255, 255, 1)',
                    'borderColor' => 'rgba(255, 255, 255, 1)',
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

<div class="w-full min-h-screen bg-gradient-to-br from-indigo-900 via-purple-800 to-pink-900">
    <!-- Mobile-first responsive header -->
    <div class="border-b border-purple-500 shadow-lg bg-gradient-to-r from-blue-600 to-indigo-700">
        <div class="container px-4 py-4 mx-auto sm:px-6 sm:py-6">
            <div class="flex flex-col items-start justify-between space-y-4 sm:flex-row sm:items-center sm:space-y-0">
                <div class="w-full space-y-1 sm:w-auto">
                    <h1 class="text-xl font-bold text-white sm:text-2xl lg:text-3xl animate-pulse">
                        {{ $greeting }}
                    </h1>
                    <p class="text-xs font-medium text-indigo-100 sm:text-sm">
                        {{ now()->format('l, F j, Y') }} • System Dashboard
                    </p>
                </div>
                <div class="flex items-center w-full space-x-2 sm:w-auto sm:space-x-4">
                    <select wire:model.live="selectedPeriod"
                            class="flex-1 px-3 py-2 text-sm font-medium text-blue-900 transition-all duration-300 bg-blue-100 border border-blue-300 shadow-sm sm:flex-none sm:px-4 rounded-xl hover:shadow-md focus:ring-2 focus:ring-blue-400 focus:border-blue-500">
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="year">This Year</option>
                    </select>
                    <button class="p-2 text-white transition-all duration-300 transform shadow-lg sm:p-3 rounded-xl bg-gradient-to-r from-pink-500 to-rose-600 hover:shadow-xl hover:scale-105">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container px-4 py-6 mx-auto space-y-6 sm:px-6 sm:py-8 sm:space-y-8">
        <!-- Responsive Stats Grid -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 sm:gap-6">
            <!-- Total Students -->
            <div class="relative p-4 overflow-hidden text-white transition-all duration-500 transform bg-blue-600 shadow-xl group sm:p-6 rounded-2xl hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-blue-500 to-blue-700"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-3 sm:mb-4">
                        <div class="p-2 bg-blue-400 border-2 border-blue-300 sm:p-3 rounded-xl">
                            <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                            </svg>
                        </div>
                        <span class="text-2xl font-bold sm:text-3xl">{{ number_format($userStats['total_students']) }}</span>
                    </div>
                    <h3 class="font-semibold text-blue-100">Total Students</h3>
                    <p class="mt-1 text-xs text-blue-200 sm:text-sm">Active learners in system</p>
                </div>
                <div class="absolute w-16 h-16 transition-transform duration-500 bg-blue-400 rounded-full sm:w-24 sm:h-24 -bottom-4 -right-4 group-hover:scale-110"></div>
            </div>

            <!-- Total Teachers -->
            <div class="relative p-4 overflow-hidden text-white transition-all duration-500 transform shadow-xl group sm:p-6 rounded-2xl bg-emerald-600 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-emerald-500 to-emerald-700"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-3 sm:mb-4">
                        <div class="p-2 border-2 sm:p-3 rounded-xl bg-emerald-400 border-emerald-300">
                            <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <span class="text-2xl font-bold sm:text-3xl">{{ number_format($userStats['total_teachers']) }}</span>
                    </div>
                    <h3 class="font-semibold text-emerald-100">Total Teachers</h3>
                    <p class="mt-1 text-xs text-emerald-200 sm:text-sm">Active teaching staff</p>
                </div>
                <div class="absolute w-16 h-16 transition-transform duration-500 rounded-full sm:w-24 sm:h-24 -bottom-4 -right-4 bg-emerald-400 group-hover:scale-110"></div>
            </div>

            <!-- Active Enrollments -->
            <div class="relative p-4 overflow-hidden text-white transition-all duration-500 transform bg-purple-600 shadow-xl group sm:p-6 rounded-2xl hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-purple-500 to-purple-700"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-3 sm:mb-4">
                        <div class="p-2 bg-purple-400 border-2 border-purple-300 sm:p-3 rounded-xl">
                            <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <span class="text-2xl font-bold sm:text-3xl">{{ number_format($enrollmentStats['active_enrollments']) }}</span>
                    </div>
                    <h3 class="font-semibold text-purple-100">Active Enrollments</h3>
                    <p class="mt-1 text-xs text-purple-200 sm:text-sm">Current academic year</p>
                </div>
                <div class="absolute w-16 h-16 transition-transform duration-500 bg-purple-400 rounded-full sm:w-24 sm:h-24 -bottom-4 -right-4 group-hover:scale-110"></div>
            </div>

            <!-- Monthly Revenue -->
            <div class="relative p-4 overflow-hidden text-white transition-all duration-500 transform bg-orange-600 shadow-xl group sm:p-6 rounded-2xl hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-orange-500 to-orange-700"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-3 sm:mb-4">
                        <div class="p-2 bg-orange-400 border-2 border-orange-300 sm:p-3 rounded-xl">
                            <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
                            </svg>
                        </div>
                        <span class="text-xl font-bold sm:text-3xl">${{ number_format($financialStats['this_month_revenue'], 0) }}</span>
                    </div>
                    <h3 class="font-semibold text-orange-100">Monthly Revenue</h3>
                    <p class="mt-1 text-xs text-orange-200 sm:text-sm">Current month earnings</p>
                </div>
                <div class="absolute w-16 h-16 transition-transform duration-500 bg-orange-400 rounded-full sm:w-24 sm:h-24 -bottom-4 -right-4 group-hover:scale-110"></div>
            </div>
        </div>

        <!-- Responsive Charts Section -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 sm:gap-8">
            <!-- Enrollment Trends - Full width on mobile, 2/3 on desktop -->
            <div class="p-4 transition-all duration-500 bg-blue-500 border border-blue-400 shadow-xl sm:p-6 lg:col-span-2 rounded-2xl hover:shadow-2xl">
                <div class="flex flex-col items-start justify-between mb-4 space-y-3 sm:flex-row sm:items-center sm:space-y-0 sm:mb-6">
                    <h2 class="text-lg font-bold text-white sm:text-xl">
                        Enrollment & Payment Trends
                    </h2>
                    <div class="flex space-x-2">
                        <span class="px-2 py-1 text-xs font-medium text-blue-900 bg-blue-200 rounded-full sm:px-3">Enrollments</span>
                        <span class="px-2 py-1 text-xs font-medium rounded-full sm:px-3 text-emerald-900 bg-emerald-200">Payments</span>
                    </div>
                </div>
                <div class="h-64 sm:h-80">
                    <x-chart wire:model="enrollmentChart" class="w-full h-full" />
                </div>
            </div>

            <!-- Payment Status -->
            <div class="p-4 transition-all duration-500 bg-purple-500 border border-purple-400 shadow-xl sm:p-6 rounded-2xl hover:shadow-2xl">
                <div class="flex items-center justify-between mb-4 sm:mb-6">
                    <h2 class="text-lg font-bold text-white sm:text-xl">
                        Payment Status
                    </h2>
                    <button class="p-2 transition-all duration-300 bg-purple-300 rounded-lg hover:bg-purple-200">
                        <svg class="w-4 h-4 text-purple-800" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </button>
                </div>
                <div class="h-48 sm:h-64">
                    <x-chart wire:model="paymentStatusChart" class="w-full h-full" />
                </div>
            </div>
        </div>

        <!-- Additional Responsive Sections -->
        <div class="grid grid-cols-1 gap-6 sm:gap-8 lg:grid-cols-3">
            <!-- Curriculum Distribution -->
            <div class="p-4 transition-all duration-500 border border-green-200 shadow-xl sm:p-6 rounded-2xl bg-gradient-to-br from-green-100 to-emerald-100 hover:shadow-2xl">
                <div class="flex items-center justify-between mb-4 sm:mb-6">
                    <h2 class="text-lg font-bold sm:text-xl text-slate-800">
                        Curriculum Distribution
                    </h2>
                </div>
                <div class="h-48 sm:h-64">
                    <x-chart wire:model="curriculumDistribution" class="w-full h-full" />
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="p-4 transition-all duration-500 border shadow-xl sm:p-6 lg:col-span-2 rounded-2xl bg-gradient-to-br from-amber-100 to-orange-100 border-amber-200 hover:shadow-2xl">
                <div class="flex flex-col items-start justify-between mb-4 space-y-2 sm:flex-row sm:items-center sm:space-y-0 sm:mb-6">
                    <h2 class="text-lg font-bold sm:text-xl text-slate-800">
                        Recent System Activity
                    </h2>
                    <a href="{{ route('admin.activity-logs.index') }}"
                       class="text-sm font-medium text-orange-700 transition-colors duration-300 hover:text-orange-900">
                        View All →
                    </a>
                </div>
                <div class="space-y-3 overflow-y-auto sm:space-y-4 max-h-48 sm:max-h-64">
                    @foreach($recentActivity as $activity)
                    <div class="flex items-start p-3 space-x-3 transition-all duration-300 sm:p-4 sm:space-x-4 rounded-xl bg-gradient-to-r from-yellow-50 to-orange-50 hover:from-yellow-100 hover:to-orange-100">
                        <div class="flex items-center justify-center w-8 h-8 text-xs font-bold text-white rounded-full sm:w-10 sm:h-10 sm:text-sm bg-gradient-to-r from-orange-500 to-red-600">
                            {{ substr($activity->user->name ?? 'System', 0, 2) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-slate-900">
                                {{ $activity->user->name ?? 'System' }}
                            </p>
                            <p class="text-xs text-slate-700 sm:text-sm">{{ $activity->description }}</p>
                            <p class="mt-1 text-xs text-slate-600">{{ $activity->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Responsive Quick Actions -->
        <div class="p-4 transition-all duration-500 border border-indigo-200 shadow-xl sm:p-6 rounded-2xl bg-gradient-to-br from-indigo-100 to-purple-100 hover:shadow-2xl">
            <h2 class="mb-4 text-lg font-bold sm:mb-6 sm:text-xl text-slate-800">
                Quick Actions
            </h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 sm:gap-4">
                <a href="{{ route('admin.users.create') }}"
                   class="flex flex-col items-center p-3 transition-all duration-300 transform group sm:p-4 rounded-xl bg-gradient-to-br from-blue-200 to-blue-300 hover:from-blue-300 hover:to-blue-400 hover:scale-105">
                    <div class="flex items-center justify-center w-10 h-10 mb-2 text-white transition-colors duration-300 bg-blue-600 rounded-xl sm:w-12 sm:h-12 sm:mb-3 group-hover:bg-blue-700">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </div>
                    <span class="text-xs font-medium text-center text-blue-800 transition-colors duration-300 sm:text-sm group-hover:text-blue-900">Add User</span>
                </a>

                <a href="{{ route('admin.enrollments.create') }}"
                   class="flex flex-col items-center p-3 transition-all duration-300 transform group sm:p-4 rounded-xl bg-gradient-to-br from-emerald-200 to-emerald-300 hover:from-emerald-300 hover:to-emerald-400 hover:scale-105">
                    <div class="flex items-center justify-center w-10 h-10 mb-2 text-white transition-colors duration-300 rounded-xl sm:w-12 sm:h-12 sm:mb-3 bg-emerald-600 group-hover:bg-emerald-700">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <span class="text-xs font-medium text-center transition-colors duration-300 sm:text-sm text-emerald-800 group-hover:text-emerald-900">New Enrollment</span>
                </a>

                <a href="{{ route('admin.invoices.create') }}"
                   class="flex flex-col items-center p-3 transition-all duration-300 transform group sm:p-4 rounded-xl bg-gradient-to-br from-purple-200 to-purple-300 hover:from-purple-300 hover:to-purple-400 hover:scale-105">
                    <div class="flex items-center justify-center w-10 h-10 mb-2 text-white transition-colors duration-300 bg-purple-600 rounded-xl sm:w-12 sm:h-12 sm:mb-3 group-hover:bg-purple-700">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span class="text-xs font-medium text-center text-purple-800 transition-colors duration-300 sm:text-sm group-hover:text-purple-900">Create Invoice</span>
                </a>

                <a href="{{ route('admin.reports.finances') }}"
                   class="flex flex-col items-center p-3 transition-all duration-300 transform group sm:p-4 rounded-xl bg-gradient-to-br from-amber-200 to-amber-300 hover:from-amber-300 hover:to-amber-400 hover:scale-105">
                    <div class="flex items-center justify-center w-10 h-10 mb-2 text-white transition-colors duration-300 rounded-xl sm:w-12 sm:h-12 sm:mb-3 bg-amber-600 group-hover:bg-amber-700">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                    <span class="text-xs font-medium text-center transition-colors duration-300 sm:text-sm text-amber-800 group-hover:text-amber-900">Financial Report</span>
                </a>

                <a href="{{ route('admin.curricula.index') }}"
                   class="flex flex-col items-center p-3 transition-all duration-300 transform group sm:p-4 rounded-xl bg-gradient-to-br from-rose-200 to-rose-300 hover:from-rose-300 hover:to-rose-400 hover:scale-105">
                    <div class="flex items-center justify-center w-10 h-10 mb-2 text-white transition-colors duration-300 rounded-xl sm:w-12 sm:h-12 sm:mb-3 bg-rose-600 group-hover:bg-rose-700">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                        </svg>
                    </div>
                    <span class="text-xs font-medium text-center transition-colors duration-300 sm:text-sm text-rose-800 group-hover:text-rose-900">Curricula</span>
                </a>

                <a href="{{ route('admin.teachers.index') }}"
                   class="flex flex-col items-center p-3 transition-all duration-300 transform group sm:p-4 rounded-xl bg-gradient-to-br from-indigo-200 to-indigo-300 hover:from-indigo-300 hover:to-indigo-400 hover:scale-105">
                    <div class="flex items-center justify-center w-10 h-10 mb-2 text-white transition-colors duration-300 bg-indigo-600 rounded-xl sm:w-12 sm:h-12 sm:mb-3 group-hover:bg-indigo-700">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <span class="text-xs font-medium text-center text-indigo-800 transition-colors duration-300 sm:text-sm group-hover:text-indigo-900">Teachers</span>
                </a>

                <a href="{{ route('admin.timetable.index') }}"
                   class="flex flex-col items-center p-3 transition-all duration-300 transform group sm:p-4 rounded-xl bg-gradient-to-br from-teal-200 to-teal-300 hover:from-teal-300 hover:to-teal-400 hover:scale-105">
                    <div class="flex items-center justify-center w-10 h-10 mb-2 text-white transition-colors duration-300 bg-teal-600 rounded-xl sm:w-12 sm:h-12 sm:mb-3 group-hover:bg-teal-700">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span class="text-xs font-medium text-center text-teal-800 transition-colors duration-300 sm:text-sm group-hover:text-teal-900">Timetable</span>
                </a>

                <a href="{{ route('admin.settings.index') }}"
                   class="flex flex-col items-center p-3 transition-all duration-300 transform group sm:p-4 rounded-xl bg-gradient-to-br from-slate-200 to-slate-300 hover:from-slate-300 hover:to-slate-400 hover:scale-105">
                    <div class="flex items-center justify-center w-10 h-10 mb-2 text-white transition-colors duration-300 rounded-xl sm:w-12 sm:h-12 sm:mb-3 bg-slate-600 group-hover:bg-slate-700">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <span class="text-xs font-medium text-center transition-colors duration-300 sm:text-sm text-slate-800 group-hover:text-slate-900">Settings</span>
                </a>
            </div>
        </div>

        <!-- Responsive Alerts & Notifications -->
        @if($overduePayments->count() > 0)
        <div class="p-4 border-l-4 border-red-500 shadow-lg sm:p-6 bg-gradient-to-r from-red-200 to-pink-200 rounded-xl">
            <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                    <svg class="w-5 h-5 text-red-600 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L4.35 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-base font-medium text-red-800 sm:text-lg">Payment Alert</h3>
                    <p class="mt-1 text-sm text-red-800 sm:text-base">
                        You have {{ $overduePayments->count() }} overdue payments requiring immediate attention.
                    </p>
                    <div class="mt-3 sm:mt-4">
                        <a href="{{ route('admin.invoices.index') }}?status=overdue"
                           class="inline-flex items-center px-3 py-2 text-sm font-medium text-white transition-colors duration-300 bg-red-600 rounded-lg sm:px-4 hover:bg-red-700">
                            Review Overdue Payments
                            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Additional Responsive Stats Row -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 sm:gap-6">
            <!-- Total Parents -->
            <div class="relative p-4 overflow-hidden text-white transition-all duration-500 transform shadow-xl group sm:p-6 rounded-2xl bg-gradient-to-br from-pink-500 to-rose-700 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-rose-400/20 to-pink-600/20"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-3 sm:mb-4">
                        <div class="p-2 rounded-xl sm:p-3 bg-rose-400/30 backdrop-blur-sm">
                            <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <span class="text-2xl font-bold sm:text-3xl">{{ number_format($userStats['total_parents']) }}</span>
                    </div>
                    <h3 class="font-semibold text-pink-100">Total Parents</h3>
                    <p class="mt-1 text-xs text-pink-200 sm:text-sm">Registered guardians</p>
                </div>
                <div class="absolute w-16 h-16 transition-transform duration-500 rounded-full sm:w-24 sm:h-24 -bottom-4 -right-4 bg-rose-400/20 group-hover:scale-110"></div>
            </div>

            <!-- Pending Enrollments -->
            <div class="relative p-4 overflow-hidden text-white transition-all duration-500 transform shadow-xl group sm:p-6 rounded-2xl bg-gradient-to-br from-yellow-500 to-amber-700 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-amber-400/20 to-yellow-600/20"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-3 sm:mb-4">
                        <div class="p-2 rounded-xl sm:p-3 bg-amber-400/30 backdrop-blur-sm">
                            <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <span class="text-2xl font-bold sm:text-3xl">{{ number_format($enrollmentStats['pending_enrollments']) }}</span>
                    </div>
                    <h3 class="font-semibold text-yellow-100">Pending Enrollments</h3>
                    <p class="mt-1 text-xs text-yellow-200 sm:text-sm">Awaiting approval</p>
                </div>
                <div class="absolute w-16 h-16 transition-transform duration-500 rounded-full sm:w-24 sm:h-24 -bottom-4 -right-4 bg-amber-400/20 group-hover:scale-110"></div>
            </div>

            <!-- Total Revenue -->
            <div class="relative p-4 overflow-hidden text-white transition-all duration-500 transform shadow-xl group sm:p-6 rounded-2xl bg-gradient-to-br from-green-500 to-lime-700 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-lime-400/20 to-green-600/20"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-3 sm:mb-4">
                        <div class="p-2 rounded-xl sm:p-3 bg-lime-400/30 backdrop-blur-sm">
                            <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                            </svg>
                        </div>
                        <span class="text-xl font-bold sm:text-3xl">${{ number_format($financialStats['total_revenue'], 0) }}</span>
                    </div>
                    <h3 class="font-semibold text-green-100">Total Revenue</h3>
                    <p class="mt-1 text-xs text-green-200 sm:text-sm">All time earnings</p>
                </div>
                <div class="absolute w-16 h-16 transition-transform duration-500 rounded-full sm:w-24 sm:h-24 -bottom-4 -right-4 bg-lime-400/20 group-hover:scale-110"></div>
            </div>

            <!-- Active Users -->
            <div class="relative p-4 overflow-hidden text-white transition-all duration-500 transform shadow-xl group sm:p-6 rounded-2xl bg-gradient-to-br from-cyan-500 to-sky-700 hover:shadow-2xl hover:-translate-y-2">
                <div class="absolute inset-0 bg-gradient-to-br from-sky-400/20 to-cyan-600/20"></div>
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-3 sm:mb-4">
                        <div class="p-2 rounded-xl sm:p-3 bg-sky-400/30 backdrop-blur-sm">
                            <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M13 12a1 1 0 11-2 0 1 1 0 012 0z" />
                            </svg>
                        </div>
                        <span class="text-2xl font-bold sm:text-3xl">{{ number_format($userStats['active_users']) }}</span>
                    </div>
                    <h3 class="font-semibold text-cyan-100">Active Users</h3>
                    <p class="mt-1 text-xs text-cyan-200 sm:text-sm">Last 30 days</p>
                </div>
                <div class="absolute w-16 h-16 transition-transform duration-500 rounded-full sm:w-24 sm:h-24 -bottom-4 -right-4 bg-sky-400/20 group-hover:scale-110"></div>
            </div>
        </div>

        <!-- Responsive Performance Metrics -->
        <div class="grid grid-cols-1 gap-6 sm:gap-8 lg:grid-cols-2">
            <!-- Financial Overview -->
            <div class="p-4 transition-all duration-500 border border-teal-200 shadow-xl sm:p-6 rounded-2xl bg-gradient-to-br from-teal-100 to-cyan-100 hover:shadow-2xl">
                <div class="flex flex-col items-start justify-between mb-4 space-y-2 sm:flex-row sm:items-center sm:space-y-0 sm:mb-6">
                    <h2 class="text-lg font-bold sm:text-xl text-slate-800">
                        Financial Overview
                    </h2>
                    <span class="px-2 py-1 text-xs font-medium text-teal-700 bg-teal-200 rounded-full sm:px-3">Last Updated</span>
                </div>
                <div class="space-y-3 sm:space-y-4">
                    <div class="flex items-center justify-between p-3 rounded-xl sm:p-4 bg-gradient-to-r from-emerald-50 to-teal-50">
                        <div class="flex items-center space-x-2 sm:space-x-3">
                            <div class="p-1.5 rounded-lg sm:p-2 bg-emerald-500">
                                <svg class="w-4 h-4 text-white sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <span class="text-sm font-medium sm:text-base text-slate-700">Completed Payments</span>
                        </div>
                        <span class="text-base font-bold sm:text-lg text-emerald-600">${{ number_format($financialStats['total_revenue'] - $financialStats['pending_payments'] - $financialStats['overdue_payments'], 0) }}</span>
                    </div>

                    <div class="flex items-center justify-between p-3 rounded-xl sm:p-4 bg-gradient-to-r from-yellow-50 to-amber-50">
                        <div class="flex items-center space-x-2 sm:space-x-3">
                            <div class="p-1.5 bg-yellow-500 rounded-lg sm:p-2">
                                <svg class="w-4 h-4 text-white sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <span class="text-sm font-medium sm:text-base text-slate-700">Pending Payments</span>
                        </div>
                        <span class="text-base font-bold text-yellow-600 sm:text-lg">${{ number_format($financialStats['pending_payments'], 0) }}</span>
                    </div>

                    <div class="flex items-center justify-between p-3 rounded-xl sm:p-4 bg-gradient-to-r from-red-50 to-pink-50">
                        <div class="flex items-center space-x-2 sm:space-x-3">
                            <div class="p-1.5 bg-red-500 rounded-lg sm:p-2">
                                <svg class="w-4 h-4 text-white sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L4.35 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                </svg>
                            </div>
                            <span class="text-sm font-medium sm:text-base text-slate-700">Overdue Payments</span>
                        </div>
                        <span class="text-base font-bold text-red-600 sm:text-lg">${{ number_format($financialStats['overdue_payments'], 0) }}</span>
                    </div>
                </div>
            </div>

            <!-- System Activity Summary -->
            <div class="p-4 transition-all duration-500 border shadow-xl sm:p-6 rounded-2xl bg-gradient-to-br from-violet-100 to-purple-100 border-violet-200 hover:shadow-2xl">
                <div class="flex flex-col items-start justify-between mb-4 space-y-2 sm:flex-row sm:items-center sm:space-y-0 sm:mb-6">
                    <h2 class="text-lg font-bold sm:text-xl text-slate-800">
                        System Activity
                    </h2>
                    <span class="px-2 py-1 text-xs font-medium rounded-full sm:px-3 text-violet-700 bg-violet-200">Live Data</span>
                </div>
                <div class="space-y-3 sm:space-y-4">
                    <div class="flex items-center justify-between p-3 rounded-xl sm:p-4 bg-gradient-to-r from-blue-50 to-indigo-50">
                        <div class="flex items-center space-x-2 sm:space-x-3">
                            <div class="p-1.5 bg-blue-500 rounded-lg sm:p-2">
                                <svg class="w-4 h-4 text-white sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <span class="text-sm font-medium sm:text-base text-slate-700">Today's Activities</span>
                        </div>
                        <span class="text-base font-bold text-blue-600 sm:text-lg">{{ number_format($activityStats['today_activities']) }}</span>
                    </div>

                    <div class="flex items-center justify-between p-3 rounded-xl sm:p-4 bg-gradient-to-r from-purple-50 to-violet-50">
                        <div class="flex items-center space-x-2 sm:space-x-3">
                            <div class="p-1.5 bg-purple-500 rounded-lg sm:p-2">
                                <svg class="w-4 h-4 text-white sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                </svg>
                            </div>
                            <span class="text-sm font-medium sm:text-base text-slate-700">Week's Activities</span>
                        </div>
                        <span class="text-base font-bold text-purple-600 sm:text-lg">{{ number_format($activityStats['week_activities']) }}</span>
                    </div>

                    <div class="flex items-center justify-between p-3 rounded-xl sm:p-4 bg-gradient-to-r from-green-50 to-emerald-50">
                        <div class="flex items-center space-x-2 sm:space-x-3">
                            <div class="p-1.5 bg-green-500 rounded-lg sm:p-2">
                                <svg class="w-4 h-4 text-white sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <span class="text-sm font-medium sm:text-base text-slate-700">Current Year Enrollments</span>
                        </div>
                        <span class="text-base font-bold text-green-600 sm:text-lg">{{ number_format($enrollmentStats['current_year_enrollments']) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile-optimized Recent Data Section -->
        <div class="grid grid-cols-1 gap-6 sm:gap-8 lg:grid-cols-2">
            <!-- Recent Enrollments -->
            <div class="p-4 transition-all duration-500 border shadow-xl sm:p-6 rounded-2xl bg-gradient-to-br from-sky-100 to-blue-100 border-sky-200 hover:shadow-2xl">
                <div class="flex flex-col items-start justify-between mb-4 space-y-2 sm:flex-row sm:items-center sm:space-y-0 sm:mb-6">
                    <h2 class="text-lg font-bold sm:text-xl text-slate-800">
                        Recent Enrollments
                    </h2>
                    <a href="{{ route('admin.enrollments.index') }}"
                       class="text-sm font-medium text-blue-700 transition-colors duration-300 hover:text-blue-900">
                        View All →
                    </a>
                </div>
                <div class="space-y-3 overflow-y-auto sm:space-y-4 max-h-64">
                    @foreach($recentEnrollments as $enrollment)
                    <div class="flex items-start p-3 space-x-3 transition-all duration-300 rounded-xl bg-gradient-to-r from-blue-50 to-sky-50 hover:from-blue-100 hover:to-sky-100">
                        <div class="flex items-center justify-center w-8 h-8 text-xs font-bold text-white bg-blue-600 rounded-full sm:w-10 sm:h-10 sm:text-sm">
                            {{ substr($enrollment->childProfile->first_name ?? 'N/A', 0, 2) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-slate-900">
                                {{ $enrollment->childProfile->first_name ?? 'N/A' }} {{ $enrollment->childProfile->last_name ?? '' }}
                            </p>
                            <p class="text-xs text-slate-700 sm:text-sm">{{ $enrollment->curriculum->name ?? 'N/A' }}</p>
                            <p class="mt-1 text-xs text-slate-600">{{ $enrollment->created_at->diffForHumans() }}</p>
                        </div>
                        <span class="px-2 py-1 text-xs font-medium rounded-full {{ $enrollment->status === 'Active' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                            {{ $enrollment->status }}
                        </span>
                    </div>
                    @endforeach
                </div>
            </div>

            <!-- Recent Payments -->
            <div class="p-4 transition-all duration-500 border shadow-xl sm:p-6 rounded-2xl bg-gradient-to-br from-emerald-100 to-green-100 border-emerald-200 hover:shadow-2xl">
                <div class="flex flex-col items-start justify-between mb-4 space-y-2 sm:flex-row sm:items-center sm:space-y-0 sm:mb-6">
                    <h2 class="text-lg font-bold sm:text-xl text-slate-800">
                        Recent Payments
                    </h2>
                    <a href="{{ route('admin.invoices.index') }}"
                       class="text-sm font-medium transition-colors duration-300 text-emerald-700 hover:text-emerald-900">
                        View All →
                    </a>
                </div>
                <div class="space-y-3 overflow-y-auto sm:space-y-4 max-h-64">
                    @foreach($recentPayments as $payment)
                    <div class="flex items-start p-3 space-x-3 transition-all duration-300 rounded-xl bg-gradient-to-r from-emerald-50 to-green-50 hover:from-emerald-100 hover:to-green-100">
                        <div class="flex items-center justify-center w-8 h-8 text-xs font-bold text-white rounded-full sm:w-10 sm:h-10 sm:text-sm bg-emerald-600">
                            $
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-slate-900">
                                ${{ number_format($payment->amount, 2) }}
                            </p>
                            <p class="text-xs text-slate-700 sm:text-sm">{{ $payment->student->name ?? 'N/A' }}</p>
                            <p class="mt-1 text-xs text-slate-600">{{ $payment->created_at->diffForHumans() }}</p>
                        </div>
                        <span class="px-2 py-1 text-xs font-medium rounded-full {{
                            $payment->status === 'completed' ? 'bg-green-100 text-green-800' :
                            ($payment->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800')
                        }}">
                            {{ ucfirst($payment->status) }}
                        </span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Mobile Navigation Helper -->
        <div class="block p-4 border border-gray-200 shadow-lg sm:hidden rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200">
            <h3 class="mb-3 text-sm font-bold text-gray-800">Quick Navigation</h3>
            <div class="grid grid-cols-2 gap-2">
                <a href="{{ route('admin.users.index') }}" class="flex items-center justify-center p-2 text-xs font-medium text-blue-800 transition-colors duration-300 bg-blue-100 rounded-lg hover:bg-blue-200">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                    </svg>
                    Users
                </a>
                <a href="{{ route('admin.enrollments.index') }}" class="flex items-center justify-center p-2 text-xs font-medium transition-colors duration-300 rounded-lg text-emerald-800 bg-emerald-100 hover:bg-emerald-200">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Enrollments
                </a>
                <a href="{{ route('admin.invoices.index') }}" class="flex items-center justify-center p-2 text-xs font-medium text-purple-800 transition-colors duration-300 bg-purple-100 rounded-lg hover:bg-purple-200">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                    Invoices
                </a>
                <a href="{{ route('admin.reports.finances') }}" class="flex items-center justify-center p-2 text-xs font-medium transition-colors duration-300 rounded-lg text-amber-800 bg-amber-100 hover:bg-amber-200">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    Reports
                </a>
            </div>
        </div>

        <!-- Responsive Footer Summary -->
        <div class="p-4 transition-all duration-500 border border-indigo-200 shadow-xl sm:p-6 rounded-2xl bg-gradient-to-br from-indigo-50 to-purple-50 hover:shadow-2xl">
            <div class="text-center">
                <h3 class="mb-2 text-lg font-bold sm:text-xl text-slate-800">System Summary</h3>
                <p class="text-sm text-slate-600 sm:text-base">
                    Managing <strong>{{ number_format($userStats['total_students']) }}</strong> students,
                    <strong>{{ number_format($userStats['total_teachers']) }}</strong> teachers, and
                    <strong>{{ number_format($enrollmentStats['active_enrollments']) }}</strong> active enrollments
                    with <strong>${{ number_format($financialStats['this_month_revenue'], 0) }}</strong> in monthly revenue.
                </p>
                <div class="flex flex-wrap justify-center gap-2 mt-4 sm:gap-4">
                    <span class="px-2 py-1 text-xs font-medium text-blue-800 bg-blue-100 rounded-full sm:px-3 sm:text-sm">
                        {{ $activityStats['today_activities'] }} activities today
                    </span>
                    <span class="px-2 py-1 text-xs font-medium rounded-full sm:px-3 sm:text-sm text-emerald-800 bg-emerald-100">
                        {{ $userStats['active_users'] }} active users
                    </span>
                    @if($overduePayments->count() > 0)
                    <span class="px-2 py-1 text-xs font-medium text-red-800 bg-red-100 rounded-full sm:px-3 sm:text-sm">
                        {{ $overduePayments->count() }} overdue payments
                    </span>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
