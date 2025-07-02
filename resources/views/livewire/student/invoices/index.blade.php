<?php

use App\Models\User;
use App\Models\ChildProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('My Invoices')] class extends Component {
    use WithPagination;
    use Toast;

    // Current user
    public User $user;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $academicYearFilter = '';

    #[Url]
    public string $curriculumFilter = '';

    #[Url]
    public string $dateFilter = '';

    #[Url]
    public int $perPage = 12;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'invoice_date', 'direction' => 'desc'];

    // View mode
    #[Url]
    public string $viewMode = 'grid'; // 'grid' or 'list'

    // Stats
    public array $stats = [];

    // Filter options
    public array $statusOptions = [];
    public array $academicYearOptions = [];
    public array $curriculumOptions = [];
    public array $dateOptions = [];

    // Child profiles for students with multiple children (if user is parent)
    public array $childProfiles = [];

    public function mount(): void
    {
        $this->user = Auth::user();

        // Log activity
        ActivityLog::log(
            $this->user->id,
            'access',
            'Accessed student invoices page',
            Invoice::class,
            null,
            ['ip' => request()->ip()]
        );

        $this->loadChildProfiles();
        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadChildProfiles(): void
    {
        try {
            if ($this->user->hasRole('student')) {
                // If user is a student, get their own child profile
                $childProfile = ChildProfile::where('user_id', $this->user->id)->first();
                $this->childProfiles = $childProfile ? [$childProfile] : [];
            } else {
                // If user is a parent, get all their children
                $this->childProfiles = ChildProfile::where('parent_id', $this->user->id)
                    ->orderBy('first_name')
                    ->get()
                    ->toArray();
            }
        } catch (\Exception $e) {
            $this->childProfiles = [];
        }
    }

    protected function loadFilterOptions(): void
    {
        // Status options
        $this->statusOptions = [
            ['id' => '', 'name' => 'All Statuses'],
            ['id' => 'draft', 'name' => 'Draft'],
            ['id' => 'sent', 'name' => 'Sent'],
            ['id' => 'pending', 'name' => 'Pending'],
            ['id' => 'partially_paid', 'name' => 'Partially Paid'],
            ['id' => 'paid', 'name' => 'Paid'],
            ['id' => 'overdue', 'name' => 'Overdue'],
            ['id' => 'cancelled', 'name' => 'Cancelled'],
        ];

        // Date filter options
        $this->dateOptions = [
            ['id' => '', 'name' => 'All Dates'],
            ['id' => 'this_month', 'name' => 'This Month'],
            ['id' => 'last_month', 'name' => 'Last Month'],
            ['id' => 'this_quarter', 'name' => 'This Quarter'],
            ['id' => 'this_year', 'name' => 'This Year'],
            ['id' => 'overdue_only', 'name' => 'Overdue Only'],
        ];

        try {
            // Get academic years with invoices for this user
            $academicYears = AcademicYear::whereHas('invoices', function ($query) {
                $query->whereHas('student', function ($studentQuery) {
                    if ($this->user->hasRole('student')) {
                        $studentQuery->where('user_id', $this->user->id);
                    } else {
                        $studentQuery->where('parent_id', $this->user->id);
                    }
                });
            })->orderBy('name')->get();

            $this->academicYearOptions = [
                ['id' => '', 'name' => 'All Academic Years'],
                ...$academicYears->map(fn($year) => [
                    'id' => $year->id,
                    'name' => $year->name
                ])->toArray()
            ];

            // Get curricula with invoices for this user
            $curricula = Curriculum::whereHas('invoices', function ($query) {
                $query->whereHas('student', function ($studentQuery) {
                    if ($this->user->hasRole('student')) {
                        $studentQuery->where('user_id', $this->user->id);
                    } else {
                        $studentQuery->where('parent_id', $this->user->id);
                    }
                });
            })->orderBy('name')->get();

            $this->curriculumOptions = [
                ['id' => '', 'name' => 'All Programs'],
                ...$curricula->map(fn($curriculum) => [
                    'id' => $curriculum->id,
                    'name' => $curriculum->name
                ])->toArray()
            ];

        } catch (\Exception $e) {
            $this->academicYearOptions = [['id' => '', 'name' => 'All Academic Years']];
            $this->curriculumOptions = [['id' => '', 'name' => 'All Programs']];
        }
    }

    protected function loadStats(): void
    {
        try {
            $baseQuery = $this->getBaseInvoicesQuery();

            $totalInvoices = (clone $baseQuery)->count();
            $pendingInvoices = (clone $baseQuery)->whereIn('status', ['pending', 'sent'])->count();
            $paidInvoices = (clone $baseQuery)->where('status', 'paid')->count();
            $overdueInvoices = (clone $baseQuery)->where('status', 'overdue')
                ->orWhere(function($q) {
                    $q->where('status', 'pending')->where('due_date', '<', now());
                })
                ->count();

            // Calculate financial totals
            $totalAmount = (clone $baseQuery)->sum('amount');
            $paidAmount = (clone $baseQuery)->where('status', 'paid')->sum('amount');
            $pendingAmount = (clone $baseQuery)->whereIn('status', ['pending', 'sent', 'partially_paid'])->sum('amount');
            $overdueAmount = (clone $baseQuery)->where('status', 'overdue')
                ->orWhere(function($q) {
                    $q->where('status', 'pending')->where('due_date', '<', now());
                })
                ->sum('amount');

            $this->stats = [
                'total_invoices' => $totalInvoices,
                'pending_invoices' => $pendingInvoices,
                'paid_invoices' => $paidInvoices,
                'overdue_invoices' => $overdueInvoices,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'pending_amount' => $pendingAmount,
                'overdue_amount' => $overdueAmount,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_invoices' => 0,
                'pending_invoices' => 0,
                'paid_invoices' => 0,
                'overdue_invoices' => 0,
                'total_amount' => 0,
                'paid_amount' => 0,
                'pending_amount' => 0,
                'overdue_amount' => 0,
            ];
        }
    }

    protected function getBaseInvoicesQuery(): Builder
    {
        return Invoice::query()
            ->with(['student', 'academicYear', 'curriculum', 'programEnrollment'])
            ->whereHas('student', function ($query) {
                if ($this->user->hasRole('student')) {
                    $query->where('user_id', $this->user->id);
                } else {
                    $query->where('parent_id', $this->user->id);
                }
            });
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

    // Toggle view mode
    public function setViewMode(string $mode): void
    {
        $this->viewMode = $mode;
    }

    // Redirect to invoice show page
    public function redirectToShow(int $invoiceId): void
    {
        $this->redirect(route('student.invoices.show', $invoiceId));
    }

    // Redirect to payment page
    public function redirectToPay(int $invoiceId): void
    {
        $this->redirect(route('student.invoices.pay', $invoiceId));
    }

    // Filter update methods
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAcademicYearFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCurriculumFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->academicYearFilter = '';
        $this->curriculumFilter = '';
        $this->dateFilter = '';
        $this->resetPage();
    }

    // Get filtered and paginated invoices
    public function invoices(): LengthAwarePaginator
    {
        return $this->getBaseInvoicesQuery()
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('invoice_number', 'like', "%{$this->search}%")
                      ->orWhere('description', 'like', "%{$this->search}%")
                      ->orWhereHas('student', function ($studentQuery) {
                          $studentQuery->where('first_name', 'like', "%{$this->search}%")
                                       ->orWhere('last_name', 'like', "%{$this->search}%")
                                       ->orWhere('email', 'like', "%{$this->search}%");
                      });
                });
            })
            ->when($this->statusFilter, function (Builder $query) {
                $query->where('status', $this->statusFilter);
            })
            ->when($this->academicYearFilter, function (Builder $query) {
                $query->where('academic_year_id', $this->academicYearFilter);
            })
            ->when($this->curriculumFilter, function (Builder $query) {
                $query->where('curriculum_id', $this->curriculumFilter);
            })
            ->when($this->dateFilter, function (Builder $query) {
                switch ($this->dateFilter) {
                    case 'this_month':
                        $query->whereMonth('invoice_date', now()->month)
                              ->whereYear('invoice_date', now()->year);
                        break;
                    case 'last_month':
                        $query->whereMonth('invoice_date', now()->subMonth()->month)
                              ->whereYear('invoice_date', now()->subMonth()->year);
                        break;
                    case 'this_quarter':
                        $startOfQuarter = now()->firstOfQuarter();
                        $endOfQuarter = now()->lastOfQuarter();
                        $query->whereBetween('invoice_date', [$startOfQuarter, $endOfQuarter]);
                        break;
                    case 'this_year':
                        $query->whereYear('invoice_date', now()->year);
                        break;
                    case 'overdue_only':
                        $query->where(function($q) {
                            $q->where('status', 'overdue')
                              ->orWhere(function($subQuery) {
                                  $subQuery->where('status', 'pending')
                                           ->where('due_date', '<', now());
                              });
                        });
                        break;
                }
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Helper functions
    public function getStatusColor(string $status): string
    {
        return match($status) {
            'draft' => 'bg-gray-100 text-gray-800',
            'sent' => 'bg-blue-100 text-blue-800',
            'pending' => 'bg-yellow-100 text-yellow-800',
            'partially_paid' => 'bg-orange-100 text-orange-800',
            'paid' => 'bg-green-100 text-green-800',
            'overdue' => 'bg-red-100 text-red-800',
            'cancelled' => 'bg-gray-100 text-gray-600',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function isOverdue($invoice): bool
    {
        if ($invoice->status === 'overdue') {
            return true;
        }

        if (in_array($invoice->status, ['pending', 'sent', 'partially_paid'])) {
            return \Carbon\Carbon::parse($invoice->due_date)->isPast();
        }

        return false;
    }

    public function getActualStatus($invoice): string
    {
        if ($this->isOverdue($invoice) && $invoice->status !== 'paid') {
            return 'overdue';
        }

        return $invoice->status;
    }

    public function formatCurrency(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }

    public function formatDate($date): string
    {
        try {
            return \Carbon\Carbon::parse($date)->format('M d, Y');
        } catch (\Exception $e) {
            return 'Date not available';
        }
    }

    public function getDaysUntilDue($dueDate): int
    {
        try {
            return \Carbon\Carbon::parse($dueDate)->diffInDays(now(), false);
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function canPayInvoice($invoice): bool
    {
        return in_array($invoice->status, ['pending', 'sent', 'partially_paid', 'overdue']);
    }

    public function getUrgencyLevel($invoice): string
    {
        if ($invoice->status === 'paid') {
            return 'none';
        }

        $daysUntilDue = $this->getDaysUntilDue($invoice->due_date);

        if ($daysUntilDue > 0) {
            return 'overdue';
        } elseif ($daysUntilDue > -7) {
            return 'urgent';
        } elseif ($daysUntilDue > -14) {
            return 'warning';
        }

        return 'normal';
    }

    public function getUrgencyColor($urgencyLevel): string
    {
        return match($urgencyLevel) {
            'overdue' => 'border-red-300 bg-red-50',
            'urgent' => 'border-orange-300 bg-orange-50',
            'warning' => 'border-yellow-300 bg-yellow-50',
            'normal' => 'border-gray-200 bg-white',
            'none' => 'border-gray-200 bg-white',
            default => 'border-gray-200 bg-white'
        };
    }

    public function getTotalPaidForInvoice($invoice): float
    {
        try {
            return Payment::where('invoice_id', $invoice->id)
                ->where('status', 'completed')
                ->sum('amount');
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getRemainingAmount($invoice): float
    {
        $totalPaid = $this->getTotalPaidForInvoice($invoice);
        return max(0, $invoice->amount - $totalPaid);
    }

    public function hasPayments($invoice): bool
    {
        try {
            return Payment::where('invoice_id', $invoice->id)->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function with(): array
    {
        return [
            'invoices' => $this->invoices(),
        ];
    }
};?>


<div>
    <!-- Page header -->
    <x-header title="My Invoices" subtitle="Manage your payments and billing information" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search invoices..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$statusFilter, $academicYearFilter, $curriculumFilter, $dateFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="{{ $viewMode === 'grid' ? 'List View' : 'Grid View' }}"
                icon="{{ $viewMode === 'grid' ? 'o-list-bullet' : 'o-squares-2x2' }}"
                wire:click="setViewMode('{{ $viewMode === 'grid' ? 'list' : 'grid' }}')"
                class="btn-ghost"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-4">
        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-blue-100 rounded-full">
                        <x-icon name="o-document-text" class="w-8 h-8 text-blue-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total_invoices']) }}</div>
                        <div class="text-sm text-gray-500">Total Invoices</div>
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
                        <div class="text-2xl font-bold text-green-600">{{ $this->formatCurrency($stats['paid_amount']) }}</div>
                        <div class="text-sm text-gray-500">Amount Paid</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-orange-100 rounded-full">
                        <x-icon name="o-clock" class="w-8 h-8 text-orange-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-orange-600">{{ $this->formatCurrency($stats['pending_amount']) }}</div>
                        <div class="text-sm text-gray-500">Pending</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-red-100 rounded-full">
                        <x-icon name="o-exclamation-triangle" class="w-8 h-8 text-red-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-red-600">{{ $this->formatCurrency($stats['overdue_amount']) }}</div>
                        <div class="text-sm text-gray-500">Overdue</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Overdue Alert -->
    @if($stats['overdue_invoices'] > 0)
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <x-icon name="o-exclamation-triangle" class="w-6 h-6 text-red-600 mr-3" />
                    <div>
                        <h3 class="font-medium text-red-800">Payment Required</h3>
                        <p class="text-sm text-red-700">
                            You have {{ $stats['overdue_invoices'] }} overdue invoice{{ $stats['overdue_invoices'] > 1 ? 's' : '' }}
                            totaling {{ $this->formatCurrency($stats['overdue_amount']) }}.
                        </p>
                    </div>
                </div>
                <x-button
                    label="View Overdue"
                    wire:click="$set('statusFilter', 'overdue')"
                    class="btn-sm btn-error"
                />
            </div>
        </div>
    @endif

    <!-- Invoices List -->
    @if($invoices->count() > 0)
        @if($viewMode === 'grid')
            <!-- Grid View -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                @foreach($invoices as $invoice)
                    @php
                        $actualStatus = $this->getActualStatus($invoice);
                        $urgencyLevel = $this->getUrgencyLevel($invoice);
                        $remainingAmount = $this->getRemainingAmount($invoice);
                    @endphp
                    <x-card class="hover:shadow-lg transition-shadow duration-200 {{ $this->getUrgencyColor($urgencyLevel) }}">
                        <div class="p-6">
                            <!-- Header with status -->
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex-1">
                                    <button
                                        wire:click="redirectToShow({{ $invoice->id }})"
                                        class="text-lg font-semibold text-blue-600 hover:text-blue-800 underline text-left"
                                    >
                                        {{ $invoice->invoice_number }}
                                    </button>
                                    <p class="text-sm text-gray-500">
                                        {{ $invoice->description ?: 'Invoice' }}
                                    </p>
                                </div>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($actualStatus) }}">
                                    {{ ucfirst(str_replace('_', ' ', $actualStatus)) }}
                                </span>
                            </div>

                            <!-- Student Info (for parents with multiple children) -->
                            @if(count($childProfiles) > 1 && $invoice->student)
                                <div class="flex items-center mb-4 p-3 bg-gray-50 rounded-lg">
                                    <div class="avatar mr-3">
                                        <div class="w-10 h-10 rounded-full">
                                            <img src="https://ui-avatars.com/api/?name={{ urlencode($invoice->student->full_name) }}&color=7F9CF5&background=EBF4FF" alt="{{ $invoice->student->full_name }}" />
                                        </div>
                                    </div>
                                    <div>
                                        <div class="font-medium text-sm">{{ $invoice->student->full_name }}</div>
                                        @if($invoice->curriculum)
                                            <div class="text-xs text-gray-500">{{ $invoice->curriculum->name }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <!-- Amount Info -->
                            <div class="mb-4">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-sm text-gray-600">Total Amount</span>
                                    <span class="font-bold text-lg">{{ $this->formatCurrency($invoice->amount) }}</span>
                                </div>
                                @if($remainingAmount > 0 && $remainingAmount < $invoice->amount)
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-sm text-gray-600">Remaining</span>
                                        <span class="font-medium text-orange-600">{{ $this->formatCurrency($remainingAmount) }}</span>
                                    </div>
                                @endif
                                @if($remainingAmount <= 0)
                                    <div class="text-center text-green-600 font-medium text-sm">
                                        ✅ Fully Paid
                                    </div>
                                @endif
                            </div>

                            <!-- Payment Progress -->
                            @if($this->hasPayments($invoice))
                                @php
                                    $paidAmount = $this->getTotalPaidForInvoice($invoice);
                                    $progress = ($paidAmount / $invoice->amount) * 100;
                                @endphp
                                <div class="mb-4">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-sm text-gray-600">Payment Progress</span>
                                        <span class="text-sm text-gray-500">{{ round($progress) }}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-green-600 h-2 rounded-full transition-all duration-300" style="width: {{ $progress }}%"></div>
                                    </div>
                                </div>
                            @endif

                            <!-- Quick Info -->
                            <div class="space-y-2 mb-4 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Invoice Date:</span>
                                    <span>{{ $this->formatDate($invoice->invoice_date) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Due Date:</span>
                                    <span class="{{ $this->isOverdue($invoice) ? 'text-red-600 font-medium' : '' }}">
                                        {{ $this->formatDate($invoice->due_date) }}
                                    </span>
                                </div>
                                @if($invoice->academicYear)
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Academic Year:</span>
                                        <span>{{ $invoice->academicYear->name }}</span>
                                    </div>
                                @endif
                            </div>

                            <!-- Urgency Indicator -->
                            @if($urgencyLevel === 'overdue')
                                <div class="flex items-center mb-4 p-2 bg-red-100 rounded text-red-800 text-sm">
                                    <x-icon name="o-exclamation-triangle" class="w-4 h-4 mr-2" />
                                    Overdue by {{ abs($this->getDaysUntilDue($invoice->due_date)) }} days
                                </div>
                            @elseif($urgencyLevel === 'urgent')
                                <div class="flex items-center mb-4 p-2 bg-orange-100 rounded text-orange-800 text-sm">
                                    <x-icon name="o-clock" class="w-4 h-4 mr-2" />
                                    Due in {{ abs($this->getDaysUntilDue($invoice->due_date)) }} days
                                </div>
                            @endif

                            <!-- Actions -->
                            <div class="flex justify-between items-center pt-4 border-t border-gray-100">
                                <x-button
                                    label="View Details"
                                    icon="o-eye"
                                    wire:click="redirectToShow({{ $invoice->id }})"
                                    class="btn-sm btn-outline"
                                />

                                @if($this->canPayInvoice($invoice))
                                    <x-button
                                        label="{{ $urgencyLevel === 'overdue' ? 'Pay Now' : 'Pay' }}"
                                        icon="o-credit-card"
                                        wire:click="redirectToPay({{ $invoice->id }})"
                                        class="btn-sm {{ $urgencyLevel === 'overdue' ? 'btn-error' : 'btn-primary' }}"
                                    />
                                @endif
                            </div>
                        </div>
                    </x-card>
                @endforeach
            </div>
        @else
            <!-- List View -->
            <x-card>
                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th class="cursor-pointer" wire:click="sortBy('invoice_number')">
                                    <div class="flex items-center">
                                        Invoice #
                                        @if ($sortBy['column'] === 'invoice_number')
                                            <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                        @endif
                                    </div>
                                </th>
                                @if(count($childProfiles) > 1)
                                    <th>Student</th>
                                @endif
                                <th>Description</th>
                                <th class="cursor-pointer" wire:click="sortBy('amount')">
                                    <div class="flex items-center">
                                        Amount
                                        @if ($sortBy['column'] === 'amount')
                                            <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                        @endif
                                    </div>
                                </th>
                                <th>Status</th>
                                <th class="cursor-pointer" wire:click="sortBy('due_date')">
                                    <div class="flex items-center">
                                        Due Date
                                        @if ($sortBy['column'] === 'due_date')
                                            <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                        @endif
                                    </div>
                                </th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoices as $invoice)
                                @php
                                    $actualStatus = $this->getActualStatus($invoice);
                                    $remainingAmount = $this->getRemainingAmount($invoice);
                                @endphp
                                <tr class="hover {{ $this->isOverdue($invoice) ? 'bg-red-50' : '' }}">
                                    <td>
                                        <button
                                            wire:click="redirectToShow({{ $invoice->id }})"
                                            class="font-mono text-sm text-blue-600 hover:text-blue-800 underline"
                                        >
                                            {{ $invoice->invoice_number }}
                                        </button>
                                        <div class="text-xs text-gray-500">{{ $this->formatDate($invoice->invoice_date) }}</div>
                                    </td>
                                    @if(count($childProfiles) > 1)
                                        <td>
                                            <div class="flex items-center">
                                                <div class="avatar mr-2">
                                                    <div class="w-8 h-8 rounded-full">
                                                        <img src="https://ui-avatars.com/api/?name={{ urlencode($invoice->student->full_name ?? 'Student') }}&color=7F9CF5&background=EBF4FF" alt="{{ $invoice->student->full_name ?? 'Student' }}" />
                                                    </div>
                                                </div>
                                                <div>
                                                    <div class="font-medium text-sm">{{ $invoice->student->full_name ?? 'Unknown' }}</div>
                                                </div>
                                            </div>
                                        </td>
                                    @endif
                                    <td>
                                        <div class="font-medium">{{ $invoice->description ?: 'Invoice' }}</div>
                                        @if($invoice->curriculum)
                                            <div class="text-xs text-gray-500">{{ $invoice->curriculum->name }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="font-medium">{{ $this->formatCurrency($invoice->amount) }}</div>
                                        @if($remainingAmount > 0 && $remainingAmount < $invoice->amount)
                                            <div class="text-xs text-orange-600">{{ $this->formatCurrency($remainingAmount) }} remaining</div>
                                        @elseif($remainingAmount <= 0)
                                            <div class="text-xs text-green-600">Fully paid</div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($actualStatus) }}">
                                            {{ ucfirst(str_replace('_', ' ', $actualStatus)) }}
                                        </span>
                                        @if($this->isOverdue($invoice))
                                            <div class="text-xs text-red-600 mt-1">{{ abs($this->getDaysUntilDue($invoice->due_date)) }} days overdue</div>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="text-sm">{{ $this->formatDate($invoice->due_date) }}</div>
                                        @if($invoice->academicYear)
                                            <div class="text-xs text-gray-500">{{ $invoice->academicYear->name }}</div>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <div class="flex justify-end gap-2">
                                            <button
                                                wire:click="redirectToShow({{ $invoice->id }})"
                                                class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                                title="View Details"
                                            >
                                                👁️
                                            </button>
                                            @if($this->canPayInvoice($invoice))
                                                <button
                                                    wire:click="redirectToPay({{ $invoice->id }})"
                                                    class="p-2 {{ $this->isOverdue($invoice) ? 'text-red-600 bg-red-100 hover:bg-red-200' : 'text-green-600 bg-green-100 hover:bg-green-200' }} rounded-md"
                                                    title="Make Payment"
                                                >
                                                    💳
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        @endif

        <!-- Pagination -->
        <div class="mt-8">
            {{ $invoices->links() }}
        </div>

        <!-- Results summary -->
        <div class="pt-3 mt-4 text-sm text-gray-600 border-t">
            Showing {{ $invoices->firstItem() ?? 0 }} to {{ $invoices->lastItem() ?? 0 }}
            of {{ $invoices->total() }} invoices
            @if($search || $statusFilter || $academicYearFilter || $curriculumFilter || $dateFilter)
                (filtered from total)
            @endif
        </div>

    @else
        <!-- Empty State -->
        <x-card>
            <div class="py-12 text-center">
                <div class="flex flex-col items-center justify-center gap-4">
                    <x-icon name="o-document-text" class="w-20 h-20 text-gray-300" />
                    <div>
                        <h3 class="text-lg font-semibold text-gray-600">No invoices found</h3>
                        <p class="mt-1 text-gray-500">
                            @if($search || $statusFilter || $academicYearFilter || $curriculumFilter || $dateFilter)
                                No invoices match your current filters.
                            @else
                                You don't have any invoices yet.
                            @endif
                        </p>
                    </div>
                    @if($search || $statusFilter || $academicYearFilter || $curriculumFilter || $dateFilter)
                        <x-button
                            label="Clear Filters"
                            wire:click="clearFilters"
                            color="secondary"
                            size="sm"
                        />
                    @endif
                </div>
            </div>
        </x-card>
    @endif

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Filter Invoices" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search invoices"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search by invoice number, description..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by status"
                    :options="$statusOptions"
                    wire:model.live="statusFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All statuses"
                />
            </div>

            <div>
                <x-select
                    label="Filter by academic year"
                    :options="$academicYearOptions"
                    wire:model.live="academicYearFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All academic years"
                />
            </div>

            <div>
                <x-select
                    label="Filter by program"
                    :options="$curriculumOptions"
                    wire:model.live="curriculumFilter"
                    option-value="id"
                    option-label="name"
                    placeholder="All programs"
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
                        ['id' => 6, 'name' => '6 per page'],
                        ['id' => 12, 'name' => '12 per page'],
                        ['id' => 18, 'name' => '18 per page'],
                        ['id' => 24, 'name' => '24 per page']
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

    <!-- Quick Actions Sidebar (if multiple children) -->
    @if(count($childProfiles) > 1)
        <div class="fixed bottom-4 right-4 z-50">
            <x-dropdown>
                <x-slot:trigger>
                    <x-button icon="o-user-group" class="btn-circle btn-primary shadow-lg">
                        {{ count($childProfiles) }}
                    </x-button>
                </x-slot:trigger>

                <x-menu-item title="Quick Filter" />
                <x-menu-separator />

                @foreach($childProfiles as $child)
                    <x-menu-item
                        title="{{ $child['full_name'] ?? 'Unknown Student' }}"
                        subtitle="View invoices"
                        link="{{ route('student.invoices.index') }}?child={{ $child['id'] ?? '' }}"
                    />
                @endforeach
            </x-dropdown>
        </div>
    @endif

    <!-- Floating Payment Button (for overdue invoices) -->
    @if($stats['overdue_invoices'] > 0)
        <div class="fixed bottom-6 left-6 z-50">
            <x-button
                icon="o-exclamation-triangle"
                wire:click="$set('statusFilter', 'overdue')"
                class="btn-circle btn-error shadow-lg animate-pulse"
                title="{{ $stats['overdue_invoices'] }} Overdue Invoice(s)"
            />
        </div>
    @endif
</div>
