<?php
// File: resources/views/livewire/admin/invoices/index.blade.php

use App\Models\Invoice;
use App\Models\AcademicYear;
use App\Models\ChildProfile;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Invoices')] class extends Component {
    use Toast, WithPagination;

    // Filters
    public string $search = '';
    public string $statusFilter = '';
    public ?int $academicYearFilter = null;
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';

    // Bulk actions
    public array $selectedInvoices = [];
    public bool $selectAll = false;

    // Filter options
    public array $statusOptions = [];
    public array $academicYearOptions = [];

    // Stats
    public array $stats = [];

    public function mount(): void
    {
        $this->loadFilterOptions();
        $this->loadStats();
    }

    protected function loadFilterOptions(): void
    {
        // Status options
        $this->statusOptions = [
            ['value' => '', 'label' => 'All Statuses'],
            ['value' => Invoice::STATUS_DRAFT, 'label' => 'Draft'],
            ['value' => Invoice::STATUS_SENT, 'label' => 'Sent'],
            ['value' => Invoice::STATUS_PENDING, 'label' => 'Pending'],
            ['value' => Invoice::STATUS_PARTIALLY_PAID, 'label' => 'Partially Paid'],
            ['value' => Invoice::STATUS_PAID, 'label' => 'Paid'],
            ['value' => Invoice::STATUS_OVERDUE, 'label' => 'Overdue'],
            ['value' => Invoice::STATUS_CANCELLED, 'label' => 'Cancelled'],
        ];

        // Academic year options
        try {
            $academicYears = AcademicYear::orderByDesc('is_current')
                ->orderByDesc('start_date')
                ->get();

            $this->academicYearOptions = [
                ['value' => null, 'label' => 'All Academic Years'],
                ...$academicYears->map(fn($year) => [
                    'value' => $year->id,
                    'label' => $year->name . ($year->is_current ? ' (Current)' : '')
                ])->toArray()
            ];
        } catch (\Exception $e) {
            $this->academicYearOptions = [['value' => null, 'label' => 'All Academic Years']];
        }
    }

    protected function loadStats(): void
    {
        try {
            $totalInvoices = Invoice::count();
            $totalAmount = Invoice::sum('amount');
            $paidAmount = Invoice::where('status', Invoice::STATUS_PAID)->sum('amount');
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

    public function sortBy(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->academicYearFilter = null;
        $this->resetPage();
    }

    public function bulkMarkAsSent(): void
    {
        if (empty($this->selectedInvoices)) {
            $this->error('Please select invoices to mark as sent.');
            return;
        }

        $updated = Invoice::whereIn('id', $this->selectedInvoices)
            ->where('status', Invoice::STATUS_DRAFT)
            ->update(['status' => Invoice::STATUS_SENT]);

        $this->success("Marked {$updated} invoice(s) as sent.");
        $this->selectedInvoices = [];
        $this->selectAll = false;
        $this->loadStats();
    }

    public function bulkMarkAsPaid(): void
    {
        if (empty($this->selectedInvoices)) {
            $this->error('Please select invoices to mark as paid.');
            return;
        }

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
    }

    public function with(): array
    {
        $query = Invoice::with(['student', 'academicYear', 'curriculum', 'programEnrollment']);

        // Apply search
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('invoice_number', 'like', "%{$this->search}%")
                  ->orWhere('description', 'like', "%{$this->search}%")
                  ->orWhereHas('student', function ($studentQuery) {
                      $studentQuery->where('first_name', 'like', "%{$this->search}%")
                                  ->orWhere('last_name', 'like', "%{$this->search}%");
                  });
            });
        }

        // Apply status filter
        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        // Apply academic year filter
        if ($this->academicYearFilter) {
            $query->where('academic_year_id', $this->academicYearFilter);
        }

        // Apply sorting
        $query->orderBy($this->sortBy, $this->sortDirection);

        $invoices = $query->paginate(15);

        return [
            'invoices' => $invoices,
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Invoices" subtitle="Manage student invoices and payments" separator>
        <x-slot:actions>
            <x-button
                label="Create Invoice"
                icon="o-plus"
                link="{{ route('admin.invoices.create') }}"
                class="btn-primary"
            />
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
                        <div class="text-2xl font-bold">{{ number_format($stats['total_invoices']) }}</div>
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
                        <div class="text-2xl font-bold">${{ number_format($stats['total_amount'], 2) }}</div>
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
                        <div class="text-2xl font-bold">{{ $stats['collection_rate'] }}%</div>
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
                        <div class="text-2xl font-bold">{{ $stats['overdue_count'] }}</div>
                        <div class="text-sm text-gray-500">Overdue</div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Filters -->
    <x-card class="mb-6">
        <div class="p-6">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <x-input
                    label="Search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search invoices..."
                    icon="o-magnifying-glass"
                />

                <x-select
                    label="Status"
                    :options="$statusOptions"
                    wire:model.live="statusFilter"
                />

                <x-select
                    label="Academic Year"
                    :options="$academicYearOptions"
                    wire:model.live="academicYearFilter"
                />

                <div class="flex items-end">
                    <x-button
                        label="Clear Filters"
                        icon="o-x-mark"
                        wire:click="clearFilters"
                        class="btn-ghost"
                    />
                </div>
            </div>
        </div>
    </x-card>

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
            <table class="table w-full">
                <thead>
                    <tr>
                        <th>
                            <x-checkbox wire:model.live="selectAll" />
                        </th>
                        <th>
                            <button class="flex items-center gap-1" wire:click="sortBy('invoice_number')">
                                Invoice #
                                @if($sortBy === 'invoice_number')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4" />
                                @endif
                            </button>
                        </th>
                        <th>Student</th>
                        <th>
                            <button class="flex items-center gap-1" wire:click="sortBy('amount')">
                                Amount
                                @if($sortBy === 'amount')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4" />
                                @endif
                            </button>
                        </th>
                        <th>
                            <button class="flex items-center gap-1" wire:click="sortBy('due_date')">
                                Due Date
                                @if($sortBy === 'due_date')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4" />
                                @endif
                            </button>
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
                                <a href="{{ route('admin.invoices.show', $invoice) }}" class="font-mono font-semibold link link-hover">
                                    {{ $invoice->invoice_number }}
                                </a>
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
                                <div class="{{ $invoice->due_date < now() && !in_array($invoice->status, ['paid', 'cancelled']) ? 'text-red-600' : '' }}">
                                    {{ $invoice->due_date->format('M d, Y') }}
                                </div>
                            </td>
                            <td>
                                <x-badge
                                    label="{{ ucfirst($invoice->status) }}"
                                    color="{{ match($invoice->status) {
                                        'draft' => 'ghost',
                                        'sent' => 'info',
                                        'pending' => 'warning',
                                        'partially_paid' => 'warning',
                                        'paid' => 'success',
                                        'overdue' => 'error',
                                        'cancelled' => 'ghost',
                                        default => 'ghost'
                                    } }}"
                                />
                            </td>
                            <td>
                                @if($invoice->academicYear)
                                    {{ $invoice->academicYear->name }}
                                    @if($invoice->academicYear->is_current)
                                        <x-badge label="Current" color="success" class="ml-1 badge-xs" />
                                    @endif
                                @else
                                    <span class="text-gray-500">Unknown</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button
                                        icon="o-eye"
                                        link="{{ route('admin.invoices.show', $invoice) }}"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View"
                                    />
                                    <x-button
                                        icon="o-pencil"
                                        link="{{ route('admin.invoices.edit', $invoice) }}"
                                        color="info"
                                        size="sm"
                                        tooltip="Edit"
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <x-icon name="o-document-text" class="w-12 h-12 text-gray-400" />
                                    <p class="mt-2 text-gray-500">No invoices found.</p>
                                    <x-button
                                        label="Create First Invoice"
                                        icon="o-plus"
                                        link="{{ route('admin.invoices.create') }}"
                                        class="mt-2 btn-primary btn-sm"
                                    />
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($invoices->hasPages())
            <div class="p-4">
                {{ $invoices->links() }}
            </div>
        @endif
    </x-card>
</div>
