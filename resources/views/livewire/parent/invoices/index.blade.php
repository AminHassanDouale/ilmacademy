<?php

use App\Models\Invoice;
use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Invoices & Payments')] class extends Component {
    use WithPagination;
    use Toast;

    // Search and filters
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $childFilter = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Stats
    public array $stats = [];

    // Filter options
    public array $statusOptions = [];
    public array $childOptions = [];

    public function mount(): void
    {
        // Set default date range (last 12 months)
        if (!$this->dateFrom) {
            $this->dateFrom = now()->subYear()->format('Y-m-d');
        }
        if (!$this->dateTo) {
            $this->dateTo = now()->addMonths(6)->format('Y-m-d'); // Include future invoices
        }

        // Pre-select child if provided in query
        if (request()->has('child')) {
            $childId = request()->get('child');
            $child = ChildProfile::where('id', $childId)
                ->where('parent_id', Auth::id())
                ->first();

            if ($child) {
                $this->childFilter = (string) $child->id;
            }
        }

        // Pre-select status if provided in query
        if (request()->has('status')) {
            $this->statusFilter = request()->get('status');
        }

        // Log activity
        ActivityLog::logActivity(
            Auth::id(),
            'access',
            'Accessed invoices and payments page'
        );

        $this->loadFilterOptions();
        $this->loadStats();
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

        // Child options - only children of the authenticated parent
        try {
            $children = ChildProfile::where('parent_id', Auth::id())
                ->orderBy('first_name')
                ->get();

            $this->childOptions = [
                ['id' => '', 'name' => 'All Children'],
                ...$children->map(fn($child) => [
                    'id' => $child->id,
                    'name' => $child->full_name
                ])->toArray()
            ];
        } catch (\Exception $e) {
            $this->childOptions = [['id' => '', 'name' => 'All Children']];
        }
    }

    protected function loadStats(): void
    {
        try {
            $invoicesQuery = Invoice::query()
                ->whereHas('student', function ($query) {
                    $query->where('parent_id', Auth::id());
                })
                ->when($this->dateFrom, function ($query) {
                    $query->whereDate('invoice_date', '>=', $this->dateFrom);
                })
                ->when($this->dateTo, function ($query) {
                    $query->whereDate('invoice_date', '<=', $this->dateTo);
                });

            $totalInvoices = $invoicesQuery->count();
            $totalAmount = $invoicesQuery->sum('amount');

            // Status counts
            $pendingInvoices = $invoicesQuery->where('status', 'pending')->count();
            $paidInvoices = $invoicesQuery->where('status', 'paid')->count();
            $overdueInvoices = $invoicesQuery->where('status', 'overdue')->count();

            // Amount calculations
            $pendingAmount = Invoice::whereHas('student', function ($query) {
                $query->where('parent_id', Auth::id());
            })->where('status', 'pending')->sum('amount');

            $paidAmount = Invoice::whereHas('student', function ($query) {
                $query->where('parent_id', Auth::id());
            })->where('status', 'paid')->sum('amount');

            $overdueAmount = Invoice::whereHas('student', function ($query) {
                $query->where('parent_id', Auth::id());
            })->where('status', 'overdue')->sum('amount');

            // Get children with invoices
            $childrenWithInvoices = $invoicesQuery->distinct('child_profile_id')->count('child_profile_id');

            $this->stats = [
                'total_invoices' => $totalInvoices,
                'total_amount' => $totalAmount,
                'pending_invoices' => $pendingInvoices,
                'paid_invoices' => $paidInvoices,
                'overdue_invoices' => $overdueInvoices,
                'pending_amount' => $pendingAmount,
                'paid_amount' => $paidAmount,
                'overdue_amount' => $overdueAmount,
                'children_with_invoices' => $childrenWithInvoices,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_invoices' => 0,
                'total_amount' => 0,
                'pending_invoices' => 0,
                'paid_invoices' => 0,
                'overdue_invoices' => 0,
                'pending_amount' => 0,
                'paid_amount' => 0,
                'overdue_amount' => 0,
                'children_with_invoices' => 0,
            ];
        }
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

    // Navigation methods
    public function redirectToShow(int $invoiceId): void
    {
        $this->redirect(route('parent.invoices.show', $invoiceId));
    }

    public function redirectToPay(int $invoiceId): void
    {
        $this->redirect(route('parent.invoices.pay', $invoiceId));
    }

    // Update methods
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedChildFilter(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->childFilter = '';
        $this->dateFrom = now()->subYear()->format('Y-m-d');
        $this->dateTo = now()->addMonths(6)->format('Y-m-d');
        $this->resetPage();
        $this->loadStats();
    }

    // Get filtered and paginated invoices
    public function invoices(): LengthAwarePaginator
    {
        return Invoice::query()
            ->whereHas('student', function ($query) {
                $query->where('parent_id', Auth::id());
            })
            ->with(['student', 'academicYear', 'curriculum', 'programEnrollment', 'payments'])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('invoice_number', 'like', "%{$this->search}%")
                      ->orWhere('description', 'like', "%{$this->search}%")
                      ->orWhereHas('student', function ($studentQuery) {
                          $studentQuery->where('first_name', 'like', "%{$this->search}%")
                                      ->orWhere('last_name', 'like', "%{$this->search}%");
                      })
                      ->orWhereHas('curriculum', function ($curriculumQuery) {
                          $curriculumQuery->where('name', 'like', "%{$this->search}%");
                      });
                });
            })
            ->when($this->statusFilter, function (Builder $query) {
                $query->where('status', $this->statusFilter);
            })
            ->when($this->childFilter, function (Builder $query) {
                $query->where('child_profile_id', $this->childFilter);
            })
            ->when($this->dateFrom, function (Builder $query) {
                $query->whereDate('invoice_date', '>=', $this->dateFrom);
            })
            ->when($this->dateTo, function (Builder $query) {
                $query->whereDate('invoice_date', '<=', $this->dateTo);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Helper function to get status color
    private function getStatusColor(string $status): string
    {
        return match($status) {
            'draft' => 'bg-gray-100 text-gray-600',
            'sent' => 'bg-blue-100 text-blue-800',
            'pending' => 'bg-yellow-100 text-yellow-800',
            'partially_paid' => 'bg-orange-100 text-orange-800',
            'paid' => 'bg-green-100 text-green-800',
            'overdue' => 'bg-red-100 text-red-800',
            'cancelled' => 'bg-gray-100 text-gray-600',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to get status icon
    private function getStatusIcon(string $status): string
    {
        return match($status) {
            'draft' => 'o-document',
            'sent' => 'o-paper-airplane',
            'pending' => 'o-clock',
            'partially_paid' => 'o-banknotes',
            'paid' => 'o-check-circle',
            'overdue' => 'o-exclamation-triangle',
            'cancelled' => 'o-x-circle',
            default => 'o-document'
        };
    }

    // Format date for display
    public function formatDate($date): string
    {
        if (!$date) {
            return 'Not set';
        }

        if (is_string($date)) {
            $date = \Carbon\Carbon::parse($date);
        }

        return $date->format('M d, Y');
    }

    // Check if invoice is payable
    private function isPayable(object $invoice): bool
    {
        return in_array($invoice->status, ['pending', 'partially_paid', 'overdue']);
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
    <x-header title="Invoices & Payments" subtitle="Manage your children's tuition invoices and payments" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search invoices..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>
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
                    <div class="p-3 mr-4 bg-yellow-100 rounded-full">
                        <x-icon name="o-clock" class="w-8 h-8 text-yellow-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-yellow-600">{{ number_format($stats['pending_invoices']) }}</div>
                        <div class="text-sm text-gray-500">Pending</div>
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
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['paid_invoices']) }}</div>
                        <div class="text-sm text-gray-500">Paid</div>
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
                        <div class="text-2xl font-bold text-red-600">{{ number_format($stats['overdue_invoices']) }}</div>
                        <div class="text-sm text-gray-500">Overdue</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Amount Stats -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-3">
        <x-card class="border-yellow-200 bg-yellow-50">
            <div class="p-6 text-center">
                <div class="text-2xl font-bold text-yellow-600">${{ number_format($stats['pending_amount'], 2) }}</div>
                <div class="text-sm text-yellow-600">Amount Due</div>
            </div>
        </x-card>

        <x-card class="border-green-200 bg-green-50">
            <div class="p-6 text-center">
                <div class="text-2xl font-bold text-green-600">${{ number_format($stats['paid_amount'], 2) }}</div>
                <div class="text-sm text-green-600">Amount Paid</div>
            </div>
        </x-card>

        <x-card class="border-red-200 bg-red-50">
            <div class="p-6 text-center">
                <div class="text-2xl font-bold text-red-600">${{ number_format($stats['overdue_amount'], 2) }}</div>
                <div class="text-sm text-red-600">Overdue Amount</div>
            </div>
        </x-card>
    </div>

    <!-- Urgent Actions -->
    @if($stats['overdue_invoices'] > 0 || $stats['pending_invoices'] > 0)
        <div class="mb-6">
            <x-card class="border-orange-200 bg-orange-50">
                <div class="p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <x-icon name="o-exclamation-triangle" class="w-6 h-6 mr-3 text-orange-600" />
                            <div>
                                <div class="font-semibold text-orange-800">Payment Required</div>
                                <div class="text-sm text-orange-700">
                                    You have {{ $stats['pending_invoices'] + $stats['overdue_invoices'] }} unpaid invoice(s)
                                    totaling ${{ number_format($stats['pending_amount'] + $stats['overdue_amount'], 2) }}
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            @if($stats['overdue_invoices'] > 0)
                                <x-button
                                    label="Pay Overdue"
                                    icon="o-credit-card"
                                    link="{{ route('parent.invoices.index', ['status' => 'overdue']) }}"
                                    class="btn-sm btn-error"
                                />
                            @endif
                            @if($stats['pending_invoices'] > 0)
                                <x-button
                                    label="Pay Pending"
                                    icon="o-banknotes"
                                    link="{{ route('parent.invoices.index', ['status' => 'pending']) }}"
                                    class="btn-sm btn-warning"
                                />
                            @endif
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    @endif

    <!-- Filters Row -->
    <div class="mb-6">
        <x-card>
            <div class="p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
                    <div>
                        <x-select
                            label="Status"
                            :options="$statusOptions"
                            wire:model.live="statusFilter"
                            option-value="id"
                            option-label="name"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Child"
                            :options="$childOptions"
                            wire:model.live="childFilter"
                            option-value="id"
                            option-label="name"
                        />
                    </div>

                    <div>
                        <x-input
                            label="From Date"
                            wire:model.live="dateFrom"
                            type="date"
                        />
                    </div>

                    <div>
                        <x-input
                            label="To Date"
                            wire:model.live="dateTo"
                            type="date"
                        />
                    </div>

                    <div>
                        <x-select
                            label="Per Page"
                            :options="[
                                ['id' => 10, 'name' => '10 per page'],
                                ['id' => 15, 'name' => '15 per page'],
                                ['id' => 25, 'name' => '25 per page'],
                                ['id' => 50, 'name' => '50 per page']
                            ]"
                            wire:model.live="perPage"
                            option-value="id"
                            option-label="name"
                        />
                    </div>

                    <div class="flex items-end">
                        <x-button
                            label="Clear Filters"
                            icon="o-x-mark"
                            wire:click="clearFilters"
                            class="w-full btn-outline"
                        />
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Invoices List -->
    @if($invoices->count() > 0)
        <div class="space-y-4">
            @foreach($invoices as $invoice)
                <x-card class="hover:shadow-lg transition-shadow duration-200 {{ $invoice->status === 'overdue' ? 'border-red-200' : ($invoice->status === 'pending' ? 'border-yellow-200' : '') }}">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-start space-x-4">
                                <!-- Status Icon -->
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center w-12 h-12 rounded-full {{ str_replace('text-', 'bg-', $this->getStatusColor($invoice->status)) }} bg-opacity-20">
                                        <x-icon name="{{ $this->getStatusIcon($invoice->status) }}" class="w-6 h-6 {{ str_replace('bg-', 'text-', explode(' ', $this->getStatusColor($invoice->status))[1]) }}" />
                                    </div>
                                </div>

                                <!-- Invoice Details -->
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <button
                                            wire:click="redirectToShow({{ $invoice->id }})"
                                            class="text-lg font-semibold text-blue-600 underline hover:text-blue-800"
                                        >
                                            {{ $invoice->invoice_number }}
                                        </button>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($invoice->status) }}">
                                            {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}
                                        </span>
                                        @if($invoice->status === 'overdue')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                URGENT
                                            </span>
                                        @endif
                                    </div>

                                    <div class="grid grid-cols-1 gap-2 mb-3 md:grid-cols-4">
                                        <div class="flex items-center text-sm text-gray-600">
                                            <x-icon name="o-user" class="w-4 h-4 mr-2" />
                                            {{ $invoice->student->full_name ?? 'Unknown Child' }}
                                        </div>

                                        <div class="flex items-center text-sm text-gray-600">
                                            <x-icon name="o-calendar" class="w-4 h-4 mr-2" />
                                            Due: {{ $this->formatDate($invoice->due_date) }}
                                        </div>

                                        @if($invoice->curriculum)
                                            <div class="flex items-center text-sm text-gray-600">
                                                <x-icon name="o-academic-cap" class="w-4 h-4 mr-2" />
                                                {{ $invoice->curriculum->name }}
                                            </div>
                                        @endif

                                        @if($invoice->academicYear)
                                            <div class="flex items-center text-sm text-gray-600">
                                                <x-icon name="o-book-open" class="w-4 h-4 mr-2" />
                                                {{ $invoice->academicYear->name }}
                                            </div>
                                        @endif
                                    </div>

                                    @if($invoice->description)
                                        <div class="mb-2 text-sm text-gray-600">
                                            {{ $invoice->description }}
                                        </div>
                                    @endif

                                    <div class="text-sm text-gray-500">
                                        Issued: {{ $this->formatDate($invoice->invoice_date) }}
                                        @if($invoice->paid_date)
                                            • Paid: {{ $this->formatDate($invoice->paid_date) }}
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <!-- Amount and Actions -->
                            <div class="text-right">
                                <div class="text-2xl font-bold {{ $invoice->status === 'paid' ? 'text-green-600' : ($invoice->status === 'overdue' ? 'text-red-600' : 'text-gray-900') }}">
                                    ${{ number_format($invoice->amount, 2) }}
                                </div>

                                @if($invoice->payments && $invoice->payments->count() > 0)
                                    <div class="text-sm text-gray-500">
                                        Paid: ${{ number_format($invoice->payments->sum('amount'), 2) }}
                                    </div>
                                @endif

                                <div class="flex gap-2 mt-3">
                                    <x-button
                                        label="View"
                                        icon="o-eye"
                                        wire:click="redirectToShow({{ $invoice->id }})"
                                        class="btn-xs btn-outline"
                                    />

                                    @if($this->isPayable($invoice))
                                        <x-button
                                            label="Pay Now"
                                            icon="o-credit-card"
                                            wire:click="redirectToPay({{ $invoice->id }})"
                                            class="btn-xs {{ $invoice->status === 'overdue' ? 'btn-error' : 'btn-primary' }}"
                                        />
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="mt-6">
            {{ $invoices->links() }}
        </div>

        <!-- Results summary -->
        <div class="mt-4 text-sm text-gray-600">
            Showing {{ $invoices->firstItem() ?? 0 }} to {{ $invoices->lastItem() ?? 0 }}
            of {{ $invoices->total() }} invoices
            @if($search || $statusFilter || $childFilter || $dateFrom || $dateTo)
                (filtered)
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
                            @if($search || $statusFilter || $childFilter || $dateFrom || $dateTo)
                                No invoices match your current filters.
                            @else
                                Invoices will appear here once your children are enrolled in programs.
                            @endif
                        </p>
                    </div>
                    @if($search || $statusFilter || $childFilter)
                        <x-button
                            label="Clear Filters"
                            wire:click="clearFilters"
                            class="btn-secondary"
                        />
                    @endif
                </div>
            </div>
        </x-card>
    @endif
</div>
