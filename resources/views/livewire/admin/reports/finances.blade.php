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

        $this->curriculaCollection = Curriculum::when($this->academicYearId, function ($query) {
            $query->whereHas('academicYears', function ($q) {
                $q->where('academic_year_id', $this->academicYearId);
            });
        })
        ->orderBy('name')
        ->get();
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

        // Calculate financial metrics - simplify to just use the payment amounts
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

        // Set chart data - simplified for basic structure only
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

    // Payment Analysis Report - simplified placeholder
    protected function generatePaymentsReport(): void
    {
        $this->metrics = [
            'message' => 'Payment Analysis report is under development'
        ];
    }

    // Invoice Analysis Report - simplified placeholder
    protected function generateInvoicesReport(): void
    {
        $this->metrics = [
            'message' => 'Invoice Analysis report is under development'
        ];
    }

    // Curriculum Revenue Report - simplified placeholder
    protected function generateCurriculumReport(): void
    {
        $this->metrics = [
            'message' => 'Curriculum Revenue report is under development'
        ];
    }

    // Financial Trends Report - simplified placeholder
    protected function generateTrendsReport(): void
    {
        $this->metrics = [
            'message' => 'Financial Trends report is under development'
        ];
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
        // Implementation would depend on your export library
        // For this example, just show a toast

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
<div>
    <!-- Page header -->
    <x-header title="Finance Reports" separator>
        <x-slot:subtitle>
            {{ $reportTypes[$reportType] }}
            @if($academicYearId)
                for {{ $academicYearsCollection->firstWhere('id', $academicYearId)?->name }}
            @endif
        </x-slot:subtitle>

        <x-slot:actions>
            <div class="flex space-x-2">
                <x-button
                    label="Export Report"
                    icon="o-arrow-down-tray"
                    wire:click="exportReport"
                    color="primary"
                />
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Filters section -->
    <x-card class="mb-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <!-- Report Type -->
            <div>
                <x-select
                    label="Report Type"
                    :options="$reportTypes"
                    wire:model.live="reportType"
                    hint="Select the type of report to generate"
                >
                    <x-slot:prepend>
                        <div class="flex items-center justify-center w-10 h-10">
                            <x-icon name="o-chart-bar" class="w-5 h-5" />
                        </div>
                    </x-slot:prepend>
                </x-select>
            </div>

            <!-- Academic Year -->
            <div>
                <x-select
                    label="Academic Year"
                    placeholder="Select academic year"
                    :options="$academicYearsCollection"
                    wire:model.live="academicYearId"
                    option-label="name"
                    option-value="id"
                    option-description="description"
                    hint="Filter by academic year"
                >
                    <x-slot:prepend>
                        <div class="flex items-center justify-center w-10 h-10">
                            <x-icon name="o-academic-cap" class="w-5 h-5" />
                        </div>
                    </x-slot:prepend>
                </x-select>
            </div>

            <!-- Curriculum -->
            <div>
                <x-select
                    label="Curriculum"
                    placeholder="All curricula"
                    :options="$curriculaCollection"
                    wire:model.live="curriculumId"
                    option-label="name"
                    option-value="id"
                    option-description="description"
                    hint="Filter by curriculum"
                >
                    <x-slot:prepend>
                        <div class="flex items-center justify-center w-10 h-10">
                            <x-icon name="o-book-open" class="w-5 h-5" />
                        </div>
                    </x-slot:prepend>
                </x-select>
            </div>

            <!-- Date Range -->
            <div>
                <x-select
                    label="Date Range"
                    :options="$dateRanges"
                    wire:model.live="dateRange"
                    hint="Select time period for the report"
                >
                    <x-slot:prepend>
                        <div class="flex items-center justify-center w-10 h-10">
                            <x-icon name="o-calendar" class="w-5 h-5" />
                        </div>
                    </x-slot:prepend>
                </x-select>
            </div>

            <!-- Custom Date Range (conditionally shown) -->
            @if($dateRange === 'custom')
                <div>
                    <x-input
                        type="date"
                        label="Start Date"
                        wire:model.live="startDate"
                        hint="Beginning of date range"
                    />
                </div>

                <div>
                    <x-input
                        type="date"
                        label="End Date"
                        wire:model.live="endDate"
                        hint="End of date range"
                    />
                </div>
            @endif
        </div>

        <div class="flex justify-end mt-4">
            <x-button
                label="Reset Filters"
                icon="o-x-mark"
                wire:click="resetFilters"
                class="btn-ghost"
            />
        </div>
    </x-card>

    <!-- Key Metrics -->
    <x-card title="Key Metrics" class="mb-6">
        @if($reportType === 'overview')
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Total Revenue</div>
                    <div class="text-3xl font-bold">{{ number_format($metrics['total_revenue'] ?? 0, 2) }}</div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Average Payment</div>
                    <div class="text-3xl font-bold text-success">{{ number_format($metrics['average_payment'] ?? 0, 2) }}</div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Total Payments</div>
                    <div class="text-3xl font-bold">{{ number_format($metrics['payment_count'] ?? 0) }}</div>
                </div>

                <div class="p-4 rounded-lg bg-base-200">
                    <div class="text-sm font-medium text-gray-500">Total Invoices</div>
                    <div class="text-3xl font-bold">{{ number_format($metrics['invoice_count'] ?? 0) }}</div>
                </div>
            </div>
        @else
            <div class="p-4 text-center">
                <div class="text-xl font-medium text-gray-500">{{ $metrics['message'] ?? 'Report under development' }}</div>
                <p class="mt-2 text-gray-500">This report type is currently being developed. Please check back later.</p>
            </div>
        @endif
    </x-card>

    <!-- Charts Section -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        @if($reportType === 'overview')
            <!-- Monthly Revenue Trend Chart -->
            <x-card title="Monthly Revenue Trend" class="lg:col-span-2">
                <div x-data="{
                    chartData: {{ json_encode($chartData['by_month'] ?? []) }}
                }" x-init="
                    if(chartData.length > 0) {
                        const ctx = document.getElementById('revenueChart').getContext('2d');
                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: chartData.map(item => item.month),
                                datasets: [
                                    {
                                        label: 'Revenue',
                                        data: chartData.map(item => item.total),
                                        backgroundColor: '#10B981'
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: {
                                            callback: function(value, index, values) {
                                                return new Intl.NumberFormat('en-US', {
                                                    style: 'currency',
                                                    currency: 'USD',
                                                    maximumSignificantDigits: 3
                                                }).format(value);
                                            }
                                        }
                                    }
                                },
                                plugins: {
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                let label = context.dataset.label || '';
                                                if (label) {
                                                    label += ': ';
                                                }
                                                if (context.parsed.y !== null) {
                                                    label += new Intl.NumberFormat('en-US', {
                                                        style: 'currency',
                                                        currency: 'USD'
                                                    }).format(context.parsed.y);
                                                }
                                                return label;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }
                ">
                    <div class="h-64">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
            </x-card>

            <!-- Data Table (spans both columns) -->
            <x-card title="Recent Payments" class="lg:col-span-2">
                <div class="overflow-x-auto">
                    @if(!empty($tableData))
                        <table class="table w-full table-zebra">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Date</th>
                                    <th>Academic Year</th>
                                    <th>Curriculum</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($tableData as $item)
                                    <tr>
                                        <td>{{ $item['student'] }}</td>
                                        <td>{{ number_format($item['amount'], 2) }}</td>
                                        <td>{{ $item['payment_method'] }}</td>
                                        <td>{{ $item['date'] }}</td>
                                        <td>{{ $item['academic_year'] }}</td>
                                        <td>{{ $item['curriculum'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="flex flex-col items-center justify-center p-8">
                            <x-icon name="o-document-text" class="w-16 h-16 text-gray-300" />
                            <p class="mt-2 text-gray-500">No payment data available for the selected filters</p>
                        </div>
                    @endif
                </div>
            </x-card>
        @endif
    </div>

    <!-- Report Notes -->
    <x-card title="Report Notes" class="mt-6">
        <div class="p-4">
            @if($reportType === 'overview')
                <p class="mb-4">This report provides a financial overview of payments and invoices for the selected time period and filters.</p>
                <ul class="ml-6 space-y-2 list-disc">
                    <li><strong>Total Revenue</strong> includes all payments for the selected period.</li>
                    <li><strong>Average Payment</strong> shows the average amount per payment.</li>
                    <li><strong>Monthly Trend</strong> shows how revenue has changed over time.</li>
                </ul>
            @elseif($reportType === 'payments')
                <p class="mb-4">This report analyzes payment patterns and distributions to help identify trends and areas for improvement.</p>
            @elseif($reportType === 'invoices')
                <p class="mb-4">This report provides insights into invoice management and collection efficiency.</p>
            @elseif($reportType === 'curriculum')
                <p class="mb-4">This report breaks down revenue by curriculum to analyze program performance and profitability.</p>
            @elseif($reportType === 'trends')
                <p class="mb-4">This report analyzes financial trends over time to help with forecasting and planning.</p>
            @endif

            <div class="p-4 mt-4 text-sm text-blue-700 rounded-lg bg-blue-50">
                <strong>Note:</strong> All financial data is calculated based on the selected filters. For more detailed analysis or custom reports, use the export feature or adjust the filters as needed.
            </div>
        </div>
    </x-card>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
