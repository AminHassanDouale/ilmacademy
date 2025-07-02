<?php

use App\Models\Payment;
use App\Models\Invoice;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Finance Reports')] class extends Component {
    use Toast;

    // Filters
    #[Url]
    public ?int $academicYearId = null;

    #[Url]
    public ?int $curriculumId = null;

    #[Url]
    public string $reportType = 'overview';

    #[Url]
    public ?string $dateRange = 'current_term';

    // Custom date range
    #[Url]
    public ?string $startDate = null;

    #[Url]
    public ?string $endDate = null;

    // Chart data and metrics
    public array $chartData = [];
    public array $metrics = [];
    public array $tableData = [];

    // Collections for dropdowns
    public $academicYearsCollection;
    public $curriculaCollection;

    // Report options
    public array $reportTypes = [
        'overview' => 'Financial Overview',
        'payments' => 'Payment Analysis',
        'invoices' => 'Invoice Analysis',
        'curriculum' => 'Curriculum Revenue',
        'trends' => 'Financial Trends'
    ];

    public array $dateRanges = [
        'current_term' => 'Current Term',
        'previous_term' => 'Previous Term',
        'current_month' => 'Current Month',
        'previous_month' => 'Previous Month',
        'current_year' => 'Current Year',
        'previous_year' => 'Previous Year',
        'custom' => 'Custom Range'
    ];

    // Mount the component
    public function mount(): void
    {
        // Set default academic year to current one
        if (!$this->academicYearId) {
            $currentAcademicYear = AcademicYear::where('is_current', true)->first();
            if ($currentAcademicYear) {
                $this->academicYearId = $currentAcademicYear->id;
            }
        }

        // Load collections for dropdowns
        $this->loadCollections();

        // Generate the report data
        $this->generateReport();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed finance reports page - {$this->reportTypes[$this->reportType]}",
            Payment::class,
            null,
            [
                'report_type' => $this->reportType,
                'filters' => [
                    'academic_year_id' => $this->academicYearId,
                    'curriculum_id' => $this->curriculumId,
                    'date_range' => $this->dateRange,
                ],
                'ip' => request()->ip()
            ]
        );
    }

    // Load collections for dropdowns
    protected function loadCollections(): void
    {
        $this->academicYearsCollection = AcademicYear::orderByDesc('start_date')->get();

        // Load all curricula without filtering until relationship is fixed
        $this->curriculaCollection = Curriculum::orderBy('name')->get();

        // Alternative: If you want to filter by academic year through payments
        // $this->curriculaCollection = Curriculum::when($this->academicYearId, function ($query) {
        //     $query->whereHas('payments', function ($q) {
        //         $q->where('academic_year_id', $this->academicYearId);
        //     });
        // })
        // ->orderBy('name')
        // ->get();
    }

    // Generate the report based on the selected type and filters
    public function generateReport(): void
    {
        // Reset data
        $this->chartData = [];
        $this->metrics = [];
        $this->tableData = [];

        // Based on report type, call the specific report generator
        switch ($this->reportType) {
            case 'overview':
                $this->generateOverviewReport();
                break;
            case 'payments':
                $this->generatePaymentsReport();
                break;
            case 'invoices':
                $this->generateInvoicesReport();
                break;
            case 'curriculum':
                $this->generateCurriculumReport();
                break;
            case 'trends':
                $this->generateTrendsReport();
                break;
        }
    }

    // Financial Overview Report
    protected function generateOverviewReport(): void
    {
        // Get date constraints
        $dateConstraints = $this->getDateConstraints();

        // Base query for payments with filters
        $paymentsQuery = Payment::query()
            ->when($this->academicYearId, function ($q) {
                $q->where('academic_year_id', $this->academicYearId);
            })
            ->when($this->curriculumId, function ($q) {
                $q->where('curriculum_id', $this->curriculumId);
            })
            ->when($dateConstraints['start'], function ($q, $start) {
                $q->where('payment_date', '>=', $start);
            })
            ->when($dateConstraints['end'], function ($q, $end) {
                $q->where('payment_date', '<=', $end);
            });

        // Base query for invoices with filters
        $invoicesQuery = Invoice::query()
            ->when($this->academicYearId, function ($q) {
                $q->where('academic_year_id', $this->academicYearId);
            })
            ->when($this->curriculumId, function ($q) {
                $q->where('curriculum_id', $this->curriculumId);
            })
            ->when($dateConstraints['start'], function ($q, $start) {
                $q->where('invoice_date', '>=', $start);
            })
            ->when($dateConstraints['end'], function ($q, $end) {
                $q->where('invoice_date', '<=', $end);
            });

        // Calculate financial metrics
        $totalRevenue = $paymentsQuery->clone()->sum('amount');

        // Get payments by month for trend
        $paymentsByMonth = $paymentsQuery->clone()
            ->select(
                DB::raw('DATE_FORMAT(payment_date, "%Y-%m") as month'),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(function ($item) {
                return [
                    'month' => date('M Y', strtotime($item->month . '-01')),
                    'total' => round($item->total, 2)
                ];
            })
            ->toArray();

        // Set metrics
        $this->metrics = [
            'total_revenue' => round($totalRevenue, 2),
            'payment_count' => $paymentsQuery->count(),
            'invoice_count' => $invoicesQuery->count(),
            'average_payment' => $paymentsQuery->clone()->count() > 0 ?
                round($totalRevenue / $paymentsQuery->clone()->count(), 2) : 0
        ];

        // Set chart data
        $this->chartData = [
            'by_month' => $paymentsByMonth
        ];

        // Set table data - recent payments
        $this->tableData = $paymentsQuery->clone()
            ->with(['student', 'academicYear', 'curriculum'])
            ->orderBy('payment_date', 'desc')
            ->take(10)
            ->get()
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'student' => $payment->student ? $payment->student->first_name . ' ' . $payment->student->last_name : 'N/A',
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method ?? 'N/A',
                    'date' => $payment->payment_date->format('M d, Y'),
                    'academic_year' => $payment->academicYear->name ?? 'N/A',
                    'curriculum' => $payment->curriculum->name ?? 'N/A'
                ];
            })
            ->toArray();
    }

    // Payment Analysis Report
    protected function generatePaymentsReport(): void
    {
        // Get date constraints
        $dateConstraints = $this->getDateConstraints();

        // Base query for payments with filters
        $paymentsQuery = Payment::query()
            ->when($this->academicYearId, function ($q) {
                $q->where('academic_year_id', $this->academicYearId);
            })
            ->when($this->curriculumId, function ($q) {
                $q->where('curriculum_id', $this->curriculumId);
            })
            ->when($dateConstraints['start'], function ($q, $start) {
                $q->where('payment_date', '>=', $start);
            })
            ->when($dateConstraints['end'], function ($q, $end) {
                $q->where('payment_date', '<=', $end);
            });

        // Payment method analysis
        $paymentsByMethod = $paymentsQuery->clone()
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->get()
            ->map(function ($item) {
                return [
                    'method' => $item->payment_method ?? 'Unknown',
                    'count' => $item->count,
                    'total' => round($item->total, 2),
                    'percentage' => 0 // Will calculate below
                ];
            });

        $totalPayments = $paymentsByMethod->sum('count');
        $paymentsByMethod = $paymentsByMethod->map(function ($item) use ($totalPayments) {
            $item['percentage'] = $totalPayments > 0 ? round(($item['count'] / $totalPayments) * 100, 1) : 0;
            return $item;
        })->toArray();

        // Payment statistics
        $totalAmount = $paymentsQuery->clone()->sum('amount');
        $averageAmount = $paymentsQuery->clone()->avg('amount');
        $maxAmount = $paymentsQuery->clone()->max('amount');
        $minAmount = $paymentsQuery->clone()->min('amount');

        $this->metrics = [
            'total_payments' => $paymentsQuery->clone()->count(),
            'total_amount' => round($totalAmount, 2),
            'average_amount' => round($averageAmount ?? 0, 2),
            'max_amount' => round($maxAmount ?? 0, 2),
            'min_amount' => round($minAmount ?? 0, 2)
        ];

        $this->chartData = [
            'by_method' => $paymentsByMethod
        ];

        // Recent payments table
        $this->tableData = $paymentsQuery->clone()
            ->with(['student', 'academicYear', 'curriculum'])
            ->orderBy('payment_date', 'desc')
            ->take(15)
            ->get()
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'student' => $payment->student ? $payment->student->first_name . ' ' . $payment->student->last_name : 'N/A',
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method ?? 'N/A',
                    'date' => $payment->payment_date->format('M d, Y'),
                    'academic_year' => $payment->academicYear->name ?? 'N/A',
                    'curriculum' => $payment->curriculum->name ?? 'N/A'
                ];
            })
            ->toArray();
    }

    // Invoice Analysis Report
    protected function generateInvoicesReport(): void
    {
        // Get date constraints
        $dateConstraints = $this->getDateConstraints();

        // Base query for invoices with filters
        $invoicesQuery = Invoice::query()
            ->when($this->academicYearId, function ($q) {
                $q->where('academic_year_id', $this->academicYearId);
            })
            ->when($this->curriculumId, function ($q) {
                $q->where('curriculum_id', $this->curriculumId);
            })
            ->when($dateConstraints['start'], function ($q, $start) {
                $q->where('invoice_date', '>=', $start);
            })
            ->when($dateConstraints['end'], function ($q, $end) {
                $q->where('invoice_date', '<=', $end);
            });

        // Invoice status analysis
        $invoicesByStatus = $invoicesQuery->clone()
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                return [
                    'status' => $item->status ?? 'Unknown',
                    'count' => $item->count,
                    'total' => round($item->total, 2)
                ];
            })
            ->toArray();

        $totalInvoices = $invoicesQuery->clone()->count();
        $totalAmount = $invoicesQuery->clone()->sum('amount');
        $paidInvoices = $invoicesQuery->clone()->where('status', 'paid')->count();
        $pendingInvoices = $invoicesQuery->clone()->where('status', 'pending')->count();

        $this->metrics = [
            'total_invoices' => $totalInvoices,
            'total_amount' => round($totalAmount, 2),
            'paid_invoices' => $paidInvoices,
            'pending_invoices' => $pendingInvoices,
            'collection_rate' => $totalInvoices > 0 ? round(($paidInvoices / $totalInvoices) * 100, 1) : 0
        ];

        $this->chartData = [
            'by_status' => $invoicesByStatus
        ];

        // Recent invoices table
        $this->tableData = $invoicesQuery->clone()
            ->with(['student', 'academicYear', 'curriculum'])
            ->orderBy('invoice_date', 'desc')
            ->take(15)
            ->get()
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'student' => $invoice->student ? $invoice->student->first_name . ' ' . $invoice->student->last_name : 'N/A',
                    'amount' => $invoice->amount,
                    'status' => $invoice->status ?? 'N/A',
                    'date' => $invoice->invoice_date->format('M d, Y'),
                    'due_date' => $invoice->due_date ? $invoice->due_date->format('M d, Y') : 'N/A',
                    'academic_year' => $invoice->academicYear->name ?? 'N/A',
                    'curriculum' => $invoice->curriculum->name ?? 'N/A'
                ];
            })
            ->toArray();
    }

    // Curriculum Revenue Report
    protected function generateCurriculumReport(): void
    {
        // Get date constraints
        $dateConstraints = $this->getDateConstraints();

        // Base query for payments with filters
        $paymentsQuery = Payment::query()
            ->when($this->academicYearId, function ($q) {
                $q->where('academic_year_id', $this->academicYearId);
            })
            ->when($dateConstraints['start'], function ($q, $start) {
                $q->where('payment_date', '>=', $start);
            })
            ->when($dateConstraints['end'], function ($q, $end) {
                $q->where('payment_date', '<=', $end);
            });

        // Revenue by curriculum
        $revenueByCurriculum = $paymentsQuery->clone()
            ->with('curriculum')
            ->select('curriculum_id', DB::raw('COUNT(*) as payment_count'), DB::raw('SUM(amount) as total_revenue'))
            ->groupBy('curriculum_id')
            ->get()
            ->map(function ($item) {
                return [
                    'curriculum' => $item->curriculum->name ?? 'Unknown',
                    'payment_count' => $item->payment_count,
                    'total_revenue' => round($item->total_revenue, 2),
                    'average_payment' => round($item->total_revenue / $item->payment_count, 2)
                ];
            })
            ->sortByDesc('total_revenue')
            ->values()
            ->toArray();

        $totalRevenue = collect($revenueByCurriculum)->sum('total_revenue');
        $totalPayments = collect($revenueByCurriculum)->sum('payment_count');

        $this->metrics = [
            'total_curricula' => count($revenueByCurriculum),
            'total_revenue' => $totalRevenue,
            'total_payments' => $totalPayments,
            'average_per_curriculum' => count($revenueByCurriculum) > 0 ? round($totalRevenue / count($revenueByCurriculum), 2) : 0
        ];

        $this->chartData = [
            'by_curriculum' => $revenueByCurriculum
        ];

        $this->tableData = $revenueByCurriculum;
    }

    // Financial Trends Report
    protected function generateTrendsReport(): void
    {
        // Get date constraints
        $dateConstraints = $this->getDateConstraints();

        // Base query for payments with filters
        $paymentsQuery = Payment::query()
            ->when($this->academicYearId, function ($q) {
                $q->where('academic_year_id', $this->academicYearId);
            })
            ->when($this->curriculumId, function ($q) {
                $q->where('curriculum_id', $this->curriculumId);
            })
            ->when($dateConstraints['start'], function ($q, $start) {
                $q->where('payment_date', '>=', $start);
            })
            ->when($dateConstraints['end'], function ($q, $end) {
                $q->where('payment_date', '<=', $end);
            });

        // Monthly trends
        $monthlyTrends = $paymentsQuery->clone()
            ->select(
                DB::raw('DATE_FORMAT(payment_date, "%Y-%m") as month'),
                DB::raw('COUNT(*) as payment_count'),
                DB::raw('SUM(amount) as total_amount'),
                DB::raw('AVG(amount) as average_amount')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(function ($item) {
                return [
                    'month' => date('M Y', strtotime($item->month . '-01')),
                    'payment_count' => $item->payment_count,
                    'total_amount' => round($item->total_amount, 2),
                    'average_amount' => round($item->average_amount, 2)
                ];
            })
            ->toArray();

        // Calculate growth rates
        $currentTotal = collect($monthlyTrends)->sum('total_amount');
        $previousPeriodEnd = $dateConstraints['start'] ? date('Y-m-d', strtotime($dateConstraints['start'] . ' -1 year')) : null;
        $previousPeriodStart = $previousPeriodEnd ? date('Y-m-d', strtotime($previousPeriodEnd . ' -1 year')) : null;

        $previousTotal = 0;
        if ($previousPeriodStart && $previousPeriodEnd) {
            $previousTotal = Payment::query()
                ->when($this->curriculumId, function ($q) {
                    $q->where('curriculum_id', $this->curriculumId);
                })
                ->where('payment_date', '>=', $previousPeriodStart)
                ->where('payment_date', '<=', $previousPeriodEnd)
                ->sum('amount');
        }

        $growthRate = $previousTotal > 0 ? round((($currentTotal - $previousTotal) / $previousTotal) * 100, 1) : 0;

        $this->metrics = [
            'current_period_revenue' => round($currentTotal, 2),
            'previous_period_revenue' => round($previousTotal, 2),
            'growth_rate' => $growthRate,
            'trend_direction' => $growthRate > 0 ? 'up' : ($growthRate < 0 ? 'down' : 'stable')
        ];

        $this->chartData = [
            'monthly_trends' => $monthlyTrends
        ];

        $this->tableData = $monthlyTrends;
    }

    // Helper to get date constraints based on date range selection
    protected function getDateConstraints(): array
    {
        $constraints = [
            'start' => null,
            'end' => null
        ];

        switch ($this->dateRange) {
            case 'current_term':
                $currentYear = AcademicYear::where('is_current', true)->first();
                if ($currentYear) {
                    $constraints['start'] = $currentYear->start_date;
                    $constraints['end'] = $currentYear->end_date;
                }
                break;

            case 'previous_term':
                $previousYear = AcademicYear::where('is_current', false)
                    ->orderByDesc('end_date')
                    ->first();
                if ($previousYear) {
                    $constraints['start'] = $previousYear->start_date;
                    $constraints['end'] = $previousYear->end_date;
                }
                break;

            case 'current_month':
                $constraints['start'] = date('Y-m-01');
                $constraints['end'] = date('Y-m-t');
                break;

            case 'previous_month':
                $constraints['start'] = date('Y-m-01', strtotime('first day of last month'));
                $constraints['end'] = date('Y-m-t', strtotime('last day of last month'));
                break;

            case 'current_year':
                $constraints['start'] = date('Y-01-01');
                $constraints['end'] = date('Y-12-31');
                break;

            case 'previous_year':
                $year = date('Y') - 1;
                $constraints['start'] = "{$year}-01-01";
                $constraints['end'] = "{$year}-12-31";
                break;

            case 'custom':
                if ($this->startDate && $this->endDate) {
                    $constraints['start'] = $this->startDate;
                    $constraints['end'] = $this->endDate;
                }
                break;
        }

        return $constraints;
    }

    // Handle change in report type
    public function updatedReportType(): void
    {
        $this->generateReport();
    }

    // Handle change in academic year
    public function updatedAcademicYearId(): void
    {
        $this->loadCollections();
        $this->generateReport();
    }

    // Handle change in curriculum
    public function updatedCurriculumId(): void
    {
        $this->generateReport();
    }

    // Handle change in date range
    public function updatedDateRange(): void
    {
        $this->generateReport();
    }

    // Handle change in custom date range
    public function updatedStartDate(): void
    {
        if ($this->dateRange === 'custom' && $this->startDate && $this->endDate) {
            $this->generateReport();
        }
    }

    public function updatedEndDate(): void
    {
        if ($this->dateRange === 'custom' && $this->startDate && $this->endDate) {
            $this->generateReport();
        }
    }

    // Reset all filters
    public function resetFilters(): void
    {
        $this->academicYearId = AcademicYear::where('is_current', true)->first()?->id;
        $this->curriculumId = null;
        $this->dateRange = 'current_term';
        $this->startDate = null;
        $this->endDate = null;

        $this->loadCollections();
        $this->generateReport();
    }

    // Export report data
    public function exportReport(): void
    {
        ActivityLog::log(
            Auth::id(),
            'export',
            "Exported {$this->reportTypes[$this->reportType]} finance report",
            Payment::class,
            null,
            [
                'report_type' => $this->reportType,
                'filters' => [
                    'academic_year_id' => $this->academicYearId,
                    'curriculum_id' => $this->curriculumId,
                    'date_range' => $this->dateRange,
                ],
                'ip' => request()->ip()
            ]
        );

        $this->success("Report has been exported successfully.");
    }
};?>

<!-- BLADE TEMPLATE STARTS HERE -->
<div>
    <!-- Page header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold">Finance Reports</h1>
        <p class="text-gray-600">{{ $reportTypes[$reportType] ?? 'Financial Overview' }}</p>
    </div>

    <!-- Filters section -->
    <div class="bg-white p-6 rounded-lg shadow mb-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
            <!-- Report Type -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Report Type</label>
                <select wire:model.live="reportType" class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    @foreach($reportTypes as $key => $label)
                        <option value="{{ $key }}" {{ $reportType === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Date Range -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date Range</label>
                <select wire:model.live="dateRange" class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    @foreach($dateRanges as $key => $label)
                        <option value="{{ $key }}" {{ $dateRange === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Academic Year -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Academic Year</label>
                <select wire:model.live="academicYearId" class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">Select academic year</option>
                    @foreach($academicYearsCollection ?? [] as $year)
                        <option value="{{ $year->id }}" {{ $academicYearId == $year->id ? 'selected' : '' }}>{{ $year->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Curriculum -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Curriculum</label>
                <select wire:model.live="curriculumId" class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All curricula</option>
                    @foreach($curriculaCollection ?? [] as $curriculum)
                        <option value="{{ $curriculum->id }}" {{ $curriculumId == $curriculum->id ? 'selected' : '' }}>{{ $curriculum->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <!-- Custom Date Range (conditionally shown) -->
        @if($dateRange === 'custom')
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 mt-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                    <input type="date" wire:model.live="startDate" class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                    <input type="date" wire:model.live="endDate" class="w-full p-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
        @endif

        <div class="flex justify-end mt-4">
            <button wire:click="resetFilters" class="px-4 py-2 text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200">
                Reset Filters
            </button>
        </div>
    </div>

    <!-- Key Metrics -->
    <div class="bg-white p-6 rounded-lg shadow mb-6">
        <h2 class="text-xl font-semibold mb-4">Key Metrics</h2>

        @if($reportType === 'overview')
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Total Invoices</div>
                    <div class="text-3xl font-bold">{{ number_format($metrics['invoice_count'] ?? 0) }}</div>
                </div>
            </div>
        @elseif($reportType === 'payments')
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-5">
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Total Payments</div>
                    <div class="text-3xl font-bold">{{ number_format($metrics['total_payments'] ?? 0) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Total Amount</div>
                    <div class="text-3xl font-bold">${{ number_format($metrics['total_amount'] ?? 0, 2) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Average Payment</div>
                    <div class="text-3xl font-bold text-green-600">${{ number_format($metrics['average_amount'] ?? 0, 2) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Highest Payment</div>
                    <div class="text-3xl font-bold text-blue-600">${{ number_format($metrics['max_amount'] ?? 0, 2) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Lowest Payment</div>
                    <div class="text-3xl font-bold text-orange-600">${{ number_format($metrics['min_amount'] ?? 0, 2) }}</div>
                </div>
            </div>
        @elseif($reportType === 'invoices')
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-5">
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Total Invoices</div>
                    <div class="text-3xl font-bold">{{ number_format($metrics['total_invoices'] ?? 0) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Total Amount</div>
                    <div class="text-3xl font-bold">${{ number_format($metrics['total_amount'] ?? 0, 2) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Paid Invoices</div>
                    <div class="text-3xl font-bold text-green-600">{{ number_format($metrics['paid_invoices'] ?? 0) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Pending Invoices</div>
                    <div class="text-3xl font-bold text-yellow-600">{{ number_format($metrics['pending_invoices'] ?? 0) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Collection Rate</div>
                    <div class="text-3xl font-bold text-blue-600">{{ number_format($metrics['collection_rate'] ?? 0, 1) }}%</div>
                </div>
            </div>
        @elseif($reportType === 'curriculum')
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Total Curricula</div>
                    <div class="text-3xl font-bold">{{ number_format($metrics['total_curricula'] ?? 0) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Total Revenue</div>
                    <div class="text-3xl font-bold">${{ number_format($metrics['total_revenue'] ?? 0, 2) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Total Payments</div>
                    <div class="text-3xl font-bold">{{ number_format($metrics['total_payments'] ?? 0) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Avg per Curriculum</div>
                    <div class="text-3xl font-bold text-green-600">${{ number_format($metrics['average_per_curriculum'] ?? 0, 2) }}</div>
                </div>
            </div>
        @elseif($reportType === 'trends')
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Current Period</div>
                    <div class="text-3xl font-bold">${{ number_format($metrics['current_period_revenue'] ?? 0, 2) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Previous Period</div>
                    <div class="text-3xl font-bold">${{ number_format($metrics['previous_period_revenue'] ?? 0, 2) }}</div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Growth Rate</div>
                    <div class="text-3xl font-bold {{ ($metrics['growth_rate'] ?? 0) > 0 ? 'text-green-600' : (($metrics['growth_rate'] ?? 0) < 0 ? 'text-red-600' : 'text-gray-600') }}">
                        {{ number_format($metrics['growth_rate'] ?? 0, 1) }}%
                    </div>
                </div>
                <div class="p-4 rounded-lg bg-gray-50">
                    <div class="text-sm font-medium text-gray-500">Trend</div>
                    <div class="text-3xl font-bold {{ ($metrics['trend_direction'] ?? '') === 'up' ? 'text-green-600' : (($metrics['trend_direction'] ?? '') === 'down' ? 'text-red-600' : 'text-gray-600') }}">
                        {{ ucfirst($metrics['trend_direction'] ?? 'stable') }}
                    </div>
                </div>
            </div>
        @endif
    </div>

    <!-- Data Tables -->
    @if(in_array($reportType, ['overview', 'payments', 'invoices']))
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">
                @if($reportType === 'overview') Recent Payments
                @elseif($reportType === 'payments') Payment Details
                @elseif($reportType === 'invoices') Recent Invoices
                @endif
            </h2>

            @if(!empty($tableData))
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                @if($reportType === 'invoices')
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Due Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Academic Year</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Curriculum</th>
                                @else
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Academic Year</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Curriculum</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($tableData as $item)
                                <tr>
                                    @if($reportType === 'invoices')
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $item['student'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${{ number_format($item['amount'], 2) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                                {{ $item['status'] === 'paid' ? 'bg-green-100 text-green-800' :
                                                   ($item['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800') }}">
                                                {{ ucfirst($item['status']) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $item['date'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $item['due_date'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $item['academic_year'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $item['curriculum'] }}</td>
                                    @else
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $item['student'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${{ number_format($item['amount'], 2) }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $item['payment_method'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $item['date'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $item['academic_year'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $item['curriculum'] }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-8">
                    <p class="text-gray-500">No data available for the selected filters</p>
                </div>
            @endif
        </div>
    @elseif($reportType === 'curriculum')
        <!-- Curriculum Revenue Table -->
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">Revenue by Curriculum</h2>
            @if(!empty($tableData))
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Curriculum</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Revenue</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Count</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Average Payment</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($tableData as $item)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $item['curriculum'] }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${{ number_format($item['total_revenue'], 2) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ number_format($item['payment_count']) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${{ number_format($item['average_payment'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-8">
                    <p class="text-gray-500">No curriculum data available for the selected filters</p>
                </div>
            @endif
        </div>
    @elseif($reportType === 'trends')
        <!-- Monthly Trends Table -->
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">Monthly Financial Trends</h2>
            @if(!empty($tableData))
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Count</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Average Payment</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($tableData as $item)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $item['month'] }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${{ number_format($item['total_amount'], 2) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ number_format($item['payment_count']) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${{ number_format($item['average_amount'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-8">
                    <p class="text-gray-500">No trend data available for the selected filters</p>
                </div>
            @endif
        </div>
    @endif

    <!-- Charts Section for Payments Report -->
    @if($reportType === 'payments' && !empty($chartData['by_method']))
        <div class="bg-white p-6 rounded-lg shadow mt-6">
            <h2 class="text-xl font-semibold mb-4">Payment Methods Distribution</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Method</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Count</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Percentage</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($chartData['by_method'] as $method)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $method['method'] }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ number_format($method['count']) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${{ number_format($method['total'], 2) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $method['percentage'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Charts Section for Invoices Report -->
    @if($reportType === 'invoices' && !empty($chartData['by_status']))
        <div class="bg-white p-6 rounded-lg shadow mt-6">
            <h2 class="text-xl font-semibold mb-4">Invoice Status Distribution</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Count</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($chartData['by_status'] as $status)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        {{ $status['status'] === 'paid' ? 'bg-green-100 text-green-800' :
                                           ($status['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800') }}">
                                        {{ ucfirst($status['status']) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ number_format($status['count']) }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${{ number_format($status['total'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Export Button -->
    <div class="mt-6 flex justify-end">
        <button wire:click="exportReport" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            Export Report
        </button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
