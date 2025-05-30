<?php

use App\Models\Invoice;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Carbon\Carbon;
use Livewire\WithPagination;

new #[Title('My Invoices')] class extends Component {
    use Toast, WithPagination;

    public $statusFilter = 'all';
    public $yearFilter = 'all';
    public $searchTerm = '';
    public $selectedChildId = null;
    public $childProfiles = [];
    public $academicYears = [];

    // Filter options
    public $statusOptions = [
        'all' => 'All Statuses',
        'pending' => 'Pending',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'cancelled' => 'Cancelled',
        'partially_paid' => 'Partially Paid'
    ];

    public function mount(): void
    {
        // Get the client profile associated with this user
        $clientProfile = Auth::user()->clientProfile;

        if (!$clientProfile) {
            $this->error("You don't have a client profile.");
            return redirect()->route('dashboard');
        }

        // Get child profiles associated with this parent
        $this->childProfiles = \App\Models\ChildProfile::where('parent_profile_id', $clientProfile->id)->get();

        if ($this->childProfiles->isEmpty()) {
            $this->error("No children profiles found.");
            return redirect()->route('dashboard');
        }

        // Set default selected child to first child
        $this->selectedChildId = $this->childProfiles->first()->id;

        // Get academic years for filter
        $this->academicYears = \App\Models\AcademicYear::orderBy('start_date', 'desc')->get();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            'Student viewed invoices list',
            null,
            null,
            ['ip' => request()->ip()]
        );
    }

    public function updatedStatusFilter()
    {
        $this->resetPage();
    }

    public function updatedYearFilter()
    {
        $this->resetPage();
    }

    public function updatedSearchTerm()
    {
        $this->resetPage();
    }

    public function updatedSelectedChildId()
    {
        $this->resetPage();
    }

    public function getInvoicesQuery()
    {
        $query = Invoice::with(['academicYear', 'curriculum', 'items', 'payments'])
            ->where('child_profile_id', $this->selectedChildId);

        // Apply status filter
        if ($this->statusFilter !== 'all') {
            if ($this->statusFilter === 'overdue') {
                $query->overdue();
            } elseif ($this->statusFilter === 'partially_paid') {
                $query->whereHas('payments', function($q) {
                    $q->where('status', 'completed');
                })->whereNotIn('status', ['paid', 'cancelled']);
            } else {
                $query->where('status', $this->statusFilter);
            }
        }

        // Apply academic year filter
        if ($this->yearFilter !== 'all') {
            $query->where('academic_year_id', $this->yearFilter);
        }

        // Apply search
        if (!empty($this->searchTerm)) {
            $query->where(function($q) {
                $q->where('invoice_number', 'like', '%' . $this->searchTerm . '%')
                  ->orWhere('description', 'like', '%' . $this->searchTerm . '%');
            });
        }

        return $query->orderBy('invoice_date', 'desc');
    }

    public function calculateTotals()
    {
        $childId = $this->selectedChildId;

        $totalPending = Invoice::where('child_profile_id', $childId)
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->sum('amount');

        $totalPaid = Invoice::where('child_profile_id', $childId)
            ->where('status', 'paid')
            ->sum('amount');

        $overdue = Invoice::where('child_profile_id', $childId)
            ->overdue()
            ->sum('amount');

        return [
            'pending' => $totalPending,
            'paid' => $totalPaid,
            'overdue' => $overdue
        ];
    }

    public function changeChild($childId): void
    {
        $this->selectedChildId = $childId;
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'invoices' => $this->getInvoicesQuery()->paginate(10),
            'totals' => $this->calculateTotals(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="My Invoices" separator>
        <x-slot:subtitle>
            View and manage your invoices
        </x-slot:subtitle>
    </x-header>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-3">
        <!-- Pending Amount Card -->
        <x-card>
            <div class="flex items-center gap-2">
                <div class="p-2 rounded-lg bg-warning/20">
                    <x-icon name="o-clock" class="w-8 h-8 text-warning" />
                </div>
                <div>
                    <div class="text-sm text-gray-500">Pending Amount</div>
                    <div class="text-2xl font-bold">{{ number_format($totals['pending'], 2) }}</div>
                </div>
            </div>
        </x-card>

        <!-- Paid Amount Card -->
        <x-card>
            <div class="flex items-center gap-2">
                <div class="p-2 rounded-lg bg-success/20">
                    <x-icon name="o-check-circle" class="w-8 h-8 text-success" />
                </div>
                <div>
                    <div class="text-sm text-gray-500">Paid Amount</div>
                    <div class="text-2xl font-bold">{{ number_format($totals['paid'], 2) }}</div>
                </div>
            </div>
        </x-card>

        <!-- Overdue Amount Card -->
        <x-card>
            <div class="flex items-center gap-2">
                <div class="p-2 rounded-lg bg-error/20">
                    <x-icon name="o-exclamation-circle" class="w-8 h-8 text-error" />
                </div>
                <div>
                    <div class="text-sm text-gray-500">Overdue Amount</div>
                    <div class="text-2xl font-bold">{{ number_format($totals['overdue'], 2) }}</div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Filters and Child Selection -->
    <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-4">
        <!-- Search -->
        <div>
            <x-input placeholder="Search invoice number or description..." wire:model.live.debounce.300ms="searchTerm">
                <x-slot:prefix>
                    <x-icon name="o-magnifying-glass" class="w-5 h-5 text-gray-400" />
                </x-slot:prefix>
            </x-input>
        </div>

        <!-- Status Filter -->
        <div>
            <x-select placeholder="Filter by status" wire:model.live="statusFilter">
                @foreach($statusOptions as $value => $label)
                    <x-select.option :value="$value" :label="$label" />
                @endforeach
            </x-select>
        </div>

        <!-- Academic Year Filter -->
        <div>
            <x-select placeholder="Filter by academic year" wire:model.live="yearFilter">
                <x-select.option value="all" label="All Academic Years" />
                @foreach($academicYears as $year)
                    <x-select.option :value="$year->id" :label="$year->name" />
                @endforeach
            </x-select>
        </div>

        <!-- Child Selection -->
        @if(count($childProfiles) > 1)
            <div>
                <x-select placeholder="Select child" wire:model.live="selectedChildId">
                    @foreach($childProfiles as $child)
                        <x-select.option :value="$child->id" :label="$child->user->name" />
                    @endforeach
                </x-select>
            </div>
        @endif
    </div>

    <!-- Invoices List -->
    <x-card>
        <div class="overflow-x-auto">
            @if($invoices->isNotEmpty())
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Due Date</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($invoices as $invoice)
                            <tr @class([
                                'bg-base-200/50' => $loop->even,
                                'bg-error/10' => $invoice->isOverdue(),
                            ])>
                                <td>{{ $invoice->invoice_number }}</td>
                                <td>{{ $invoice->invoice_date->format('M d, Y') }}</td>
                                <td>
                                    {{ $invoice->due_date ? $invoice->due_date->format('M d, Y') : 'N/A' }}
                                    @if($invoice->isOverdue())
                                        <div class="text-xs text-error">
                                            Overdue by {{ $invoice->daysOverdue() }} days
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="max-w-xs truncate">
                                        {{ $invoice->description }}
                                    </div>
                                </td>
                                <td>
                                    <div class="font-bold">{{ number_format($invoice->amount, 2) }}</div>
                                    @if(!$invoice->isPaid() && $invoice->amountPaid() > 0)
                                        <div class="text-xs text-gray-500">
                                            Paid: {{ number_format($invoice->amountPaid(), 2) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if($invoice->status === 'paid')
                                        <x-badge label="Paid" color="success" />
                                    @elseif($invoice->status === 'pending')
                                        @if($invoice->isOverdue())
                                            <x-badge label="Overdue" color="error" />
                                        @else
                                            <x-badge label="Pending" color="warning" />
                                        @endif
                                    @elseif($invoice->status === 'cancelled')
                                        <x-badge label="Cancelled" color="neutral" />
                                    @elseif($invoice->status === 'partially_paid')
                                        <x-badge label="Partially Paid" color="info" />
                                    @else
                                        <x-badge label="{{ ucfirst($invoice->status) }}" color="neutral" />
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center space-x-2">
                                        <x-button
                                            icon="o-eye"
                                            color="secondary"
                                            size="sm"
                                            tooltip="View Invoice"
                                            href="{{ route('student.invoices.show', $invoice->id) }}"
                                        />

                                        @if(!$invoice->isPaid() && !in_array($invoice->status, ['cancelled']))
                                            <x-button
                                                icon="o-credit-card"
                                                color="primary"
                                                size="sm"
                                                tooltip="Make Payment"
                                                href="{{ route('student.payments.create', ['invoice' => $invoice->id]) }}"
                                            />
                                        @endif

                                        @if($invoice->payments->isNotEmpty())
                                            <x-button
                                                icon="o-list-bullet"
                                                color="info"
                                                size="sm"
                                                tooltip="View Payments"
                                                href="{{ route('student.payments.index', ['invoice' => $invoice->id]) }}"
                                            />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="mt-4">
                    {{ $invoices->links() }}
                </div>
            @else
                <div class="p-6 text-center">
                    <div class="flex justify-center mb-4">
                        <x-icon name="o-document-text" class="w-16 h-16 text-gray-400" />
                    </div>
                    <h3 class="text-lg font-medium text-gray-500">No invoices found</h3>
                    <p class="mt-2 text-sm text-gray-500">
                        @if(!empty($searchTerm) || $statusFilter !== 'all' || $yearFilter !== 'all')
                            Try adjusting your filters to see more results.
                        @else
                            There are no invoices available for this student.
                        @endif
                    </p>
                </div>
            @endif
        </div>
    </x-card>
</div>
