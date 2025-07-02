<?php

use App\Models\Invoice;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Invoices Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $academicYearFilter = '';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Bulk actions
    public array $selectedInvoices = [];
    public bool $selectAll = false;

    // Stats
    public array $stats = [];

    // Filter options
    public array $statusOptions = [];
    public array $academicYearOptions = [];

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed invoices management page',
            Invoice::class,
            null,
            ['ip' => request()->ip()]
        );

        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        // Status options
        $this->statusOptions = [
            ['id' => '', 'name' => 'All Statuses'],
            ['id' => Invoice::STATUS_DRAFT, 'name' => 'Draft'],
            ['id' => Invoice::STATUS_SENT, 'name' => 'Sent'],
            ['id' => Invoice::STATUS_PENDING, 'name' => 'Pending'],
            ['id' => Invoice::STATUS_PARTIALLY_PAID, 'name' => 'Partially Paid'],
            ['id' => Invoice::STATUS_PAID, 'name' => 'Paid'],
            ['id' => Invoice::STATUS_OVERDUE, 'name' => 'Overdue'],
            ['id' => Invoice::STATUS_CANCELLED, 'name' => 'Cancelled'],
        ];

        // Academic year options
        try {
            $academicYears = AcademicYear::orderByDesc('is_current')
                ->orderByDesc('start_date')
                ->get();

            $this->academicYearOptions = [
                ['id' => '', 'name' => 'All Academic Years'],
                ...$academicYears->map(fn($year) => [
                    'id' => $year->id,
                    'name' => $year->name . ($year->is_current ? ' (Current)' : '')
                ])->toArray()
            ];
        } catch (\Exception $e) {
            $this->academicYearOptions = [['id' => '', 'name' => 'All Academic Years']];
        }
    }

    protected function loadStats(): void
    {
        try {
            $totalInvoices = Invoice::count();
            $totalAmount = Invoice::sum('amount') ?? 0;
            $paidAmount = Invoice::where('status', Invoice::STATUS_PAID)->sum('amount') ?? 0;
            $overdueCount = Invoice::where('status', Invoice::STATUS_OVERDUE)->count();
            $pendingCount = Invoice::whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_SENT])->count();

            $this->stats = [
                'total_invoices' => $totalInvoices,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'overdue_count' => $overdueCount,
                'pending_count' => $pendingCount,
                'collection_rate' => $totalAmount > 0 ? round(($paidAmount / $totalAmount) * 100, 1) : 0,
            ];
        } catch (\Exception $e) {
            $this->stats = [
                'total_invoices' => 0,
                'total_amount' => 0,
                'paid_amount' => 0,
                'overdue_count' => 0,
                'pending_count' => 0,
                'collection_rate' => 0,
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

    // Redirect to create page
    public function redirectToCreate(): void
    {
        $this->redirect(route('admin.invoices.create'));
    }

    // Redirect to show page
    public function redirectToShow(int $invoiceId): void
    {
        $this->redirect(route('admin.invoices.show', $invoiceId));
    }

    // Redirect to edit page
    public function redirectToEdit(int $invoiceId): void
    {
        $this->redirect(route('admin.invoices.edit', $invoiceId));
    }

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

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->academicYearFilter = '';
        $this->resetPage();
    }

    public function bulkMarkAsSent(): void
    {
        if (empty($this->selectedInvoices)) {
            $this->error('Please select invoices to mark as sent.');
            return;
        }

        try {
            $updated = Invoice::whereIn('id', $this->selectedInvoices)
                ->where('status', Invoice::STATUS_DRAFT)
                ->update(['status' => Invoice::STATUS_SENT]);

            $this->success("Marked {$updated} invoice(s) as sent.");
            $this->selectedInvoices = [];
            $this->selectAll = false;
            $this->loadStats();
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    public function bulkMarkAsPaid(): void
    {
        if (empty($this->selectedInvoices)) {
            $this->error('Please select invoices to mark as paid.');
            return;
        }

        try {
            $updated = Invoice::whereIn('id', $this->selectedInvoices)
                ->whereIn('status', [Invoice::STATUS_SENT, Invoice::STATUS_PENDING, Invoice::STATUS_OVERDUE])
                ->update([
                    'status' => Invoice::STATUS_PAID,
                    'paid_date' => now()
                ]);

            $this->success("Marked {$updated} invoice(s) as paid.");
            $this->selectedInvoices = [];
            $this->selectAll = false;
            $this->loadStats();
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Get filtered and paginated invoices
    public function invoices(): LengthAwarePaginator
    {
        return Invoice::query()
            ->with(['student', 'academicYear', 'curriculum', 'programEnrollment'])
            ->when($this->search, function (Builder $query) {
                $query->where(function ($q) {
                    $q->where('invoice_number', 'like', "%{$this->search}%")
                      ->orWhere('description', 'like', "%{$this->search}%")
                      ->orWhereHas('student', function ($studentQuery) {
                          $studentQuery->where('first_name', 'like', "%{$this->search}%")
                                      ->orWhere('last_name', 'like', "%{$this->search}%");
                      });
                });
            })
            ->when($this->statusFilter, function (Builder $query) {
                $query->where('status', $this->statusFilter);
            })
            ->when($this->academicYearFilter, function (Builder $query) {
                $query->where('academic_year_id', $this->academicYearFilter);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->academicYearFilter = '';
        $this->resetPage();
    }

    // Helper function to get status color
    private function getStatusColor(string $status): string
    {
        return match($status) {
            Invoice::STATUS_DRAFT => 'bg-gray-100 text-gray-600',
            Invoice::STATUS_SENT => 'bg-blue-100 text-blue-800',
            Invoice::STATUS_PENDING => 'bg-yellow-100 text-yellow-800',
            Invoice::STATUS_PARTIALLY_PAID => 'bg-orange-100 text-orange-800',
            Invoice::STATUS_PAID => 'bg-green-100 text-green-800',
            Invoice::STATUS_OVERDUE => 'bg-red-100 text-red-800',
            Invoice::STATUS_CANCELLED => 'bg-gray-100 text-gray-600',
            default => 'bg-gray-100 text-gray-600'
        };
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
    <x-header title="Invoices Management" subtitle="Manage student invoices and payments" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search invoices..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$statusFilter, $academicYearFilter]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="Create Invoice"
                icon="o-plus"
                wire:click="redirectToCreate"
                class="btn-primary"
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
                        <x-icon name="o-currency-dollar" class="w-8 h-8 text-green-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-green-600">${{ number_format($stats['total_amount'], 2) }}</div>
                        <div class="text-sm text-gray-500">Total Amount</div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6">
                <div class="flex items-center">
                    <div class="p-3 mr-4 bg-purple-100 rounded-full">
                        <x-icon name="o-check-circle" class="w-8 h-8 text-purple-600" />
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-purple-600">{{ $stats['collection_rate'] }}%</div>
                        <div class="text-sm text-gray-500">Collection Rate</div>
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
                        <div class="text-2xl font-bold text-red-600">{{ $stats['overdue_count'] }}</div>
                        <div class="text-sm text-gray-500">Overdue</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Bulk Actions -->
    @if(count($selectedInvoices) > 0)
        <x-card class="mb-6">
            <div class="p-4 bg-blue-50">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-blue-800">
                        {{ count($selectedInvoices) }} invoice(s) selected
                    </span>
                    <div class="flex gap-2">
                        <x-button
                            label="Mark as Sent"
                            icon="o-paper-airplane"
                            wire:click="bulkMarkAsSent"
                            class="btn-sm btn-primary"
                        />
                        <x-button
                            label="Mark as Paid"
                            icon="o-check"
                            wire:click="bulkMarkAsPaid"
                            class="btn-sm btn-success"
                        />
                    </div>
                </div>
            </div>
        </x-card>
    @endif

    <!-- Invoices Table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th>
                            <x-checkbox wire:model.live="selectAll" />
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('invoice_number')">
                            <div class="flex items-center">
                                Invoice #
                                @if ($sortBy['column'] === 'invoice_number')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Student</th>
                        <th class="cursor-pointer" wire:click="sortBy('amount')">
                            <div class="flex items-center">
                                Amount
                                @if ($sortBy['column'] === 'amount')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('due_date')">
                            <div class="flex items-center">
                                Due Date
                                @if ($sortBy['column'] === 'due_date')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Status</th>
                        <th>Academic Year</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($invoices as $invoice)
                        <tr class="hover">
                            <td>
                                <x-checkbox wire:model.live="selectedInvoices" value="{{ $invoice->id }}" />
                            </td>
                            <td>
                                <button wire:click="redirectToShow({{ $invoice->id }})" class="font-mono font-semibold text-blue-600 hover:text-blue-800 underline">
                                    {{ $invoice->invoice_number }}
                                </button>
                            </td>
                            <td>
                                @if($invoice->student)
                                    <div>
                                        <div class="font-medium">{{ $invoice->student->full_name }}</div>
                                        @if($invoice->curriculum)
                                            <div class="text-sm text-gray-500">{{ $invoice->curriculum->name }}</div>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-gray-500">Unknown Student</span>
                                @endif
                            </td>
                            <td class="font-mono font-bold">${{ number_format($invoice->amount, 2) }}</td>
                            <td>
                                <div class="{{ $invoice->due_date < now() && !in_array($invoice->status, [Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED]) ? 'text-red-600 font-medium' : '' }}">
                                    {{ $invoice->due_date->format('M d, Y') }}
                                    @if($invoice->due_date < now() && !in_array($invoice->status, [Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED]))
                                        <div class="text-xs text-red-500">Overdue</div>
                                    @endif
                                </div>
                            </td>
                            <td class="py-2">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($invoice->status) }}">
                                    {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}
                                </span>
                            </td>
                            <td>
                                @if($invoice->academicYear)
                                    <div class="font-medium">{{ $invoice->academicYear->name }}</div>
                                    @if($invoice->academicYear->is_current)
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 mt-1">
                                            Current
                                        </span>
                                    @endif
                                @else
                                    <span class="text-gray-500">Unknown</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <button
                                        wire:click="redirectToShow({{ $invoice->id }})"
                                        class="p-2 text-gray-600 bg-gray-100 rounded-md hover:text-gray-900 hover:bg-gray-200"
                                        title="View"
                                    >
                                        👁️
                                    </button>
                                    <button
                                        wire:click="redirectToEdit({{ $invoice->id }})"
                                        class="p-2 text-blue-600 bg-blue-100 rounded-md hover:text-blue-900 hover:bg-blue-200"
                                        title="Edit"
                                    >
                                        ✏️
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center gap-4">
                                    <x-icon name="o-document-text" class="w-20 h-20 text-gray-300" />
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-600">No invoices found</h3>
                                        <p class="text-gray-500 mt-1">
                                            @if($search || $statusFilter || $academicYearFilter)
                                                No invoices match your current filters.
                                            @else
                                                Get started by creating your first invoice.
                                            @endif
                                        </p>
                                    </div>
                                    @if($search || $statusFilter || $academicYearFilter)
                                        <x-button
                                            label="Clear Filters"
                                            wire:click="resetFilters"
                                            color="secondary"
                                            size="sm"
                                        />
                                    @else
                                        <x-button
                                            label="Create First Invoice"
                                            icon="o-plus"
                                            wire:click="redirectToCreate"
                                            color="primary"
                                        />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $invoices->links() }}
        </div>

        <!-- Results summary -->
        @if($invoices->count() > 0)
        <div class="mt-4 text-sm text-gray-600 border-t pt-3">
            Showing {{ $invoices->firstItem() ?? 0 }} to {{ $invoices->lastItem() ?? 0 }}
            of {{ $invoices->total() }} invoices
            @if($search || $statusFilter || $academicYearFilter)
                (filtered from total)
            @endif
        </div>
        @endif
    </x-card>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search invoices"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search..."
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
                    label="Items per page"
                    :options="[
                        ['id' => 10, 'name' => '10 per page'],
                        ['id' => 15, 'name' => '15 per page'],
                        ['id' => 25, 'name' => '25 per page'],
                        ['id' => 50, 'name' => '50 per page']
                    ]"
                    option-value="id"
                    option-label="name"
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
