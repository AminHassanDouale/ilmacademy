<?php

use App\Models\Invoice;
use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Invoices')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $child = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $period = 'all';

    #[Url]
    public string $startDate = '';

    #[Url]
    public string $endDate = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'invoice_date', 'direction' => 'desc'];

    public function mount(): void
    {
        // Default dates if not set
        if (empty($this->startDate)) {
            $this->startDate = Carbon::now()->subMonths(12)->format('Y-m-d');
        }

        if (empty($this->endDate)) {
            $this->endDate = Carbon::now()->format('Y-m-d');
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Parent accessed invoices page',
            Invoice::class,
            null,
            ['ip' => request()->ip()]
        );
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
    }

    // Set time period
    public function setPeriod(string $period): void
    {
        $this->period = $period;

        switch ($period) {
            case 'month':
                $this->startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfMonth()->format('Y-m-d');
                break;
            case 'quarter':
                $this->startDate = Carbon::now()->startOfQuarter()->format('Y-m-d');
                $this->endDate = Carbon::now()->endOfQuarter()->format('Y-m-d');
                break;
            case 'semester':
                $this->startDate = Carbon::now()->subMonths(6)->format('Y-m-d');
                $this->endDate = Carbon::now()->format('Y-m-d');
                break;
            case 'year':
                $this->startDate = Carbon::now()->subYear()->format('Y-m-d');
                $this->endDate = Carbon::now()->format('Y-m-d');
                break;
            case 'all':
                $this->startDate = Carbon::now()->subYears(5)->format('Y-m-d');
                $this->endDate = Carbon::now()->format('Y-m-d');
                break;
            case 'custom':
                // Keep existing dates
                break;
        }
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->child = '';
        $this->status = '';
        $this->period = 'all';
        $this->startDate = Carbon::now()->subMonths(12)->format('Y-m-d');
        $this->endDate = Carbon::now()->format('Y-m-d');
        $this->resetPage();
    }

    // Get children for this parent
    public function children()
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return collect();
        }

        return ChildProfile::where('parent_profile_id', $parentProfile->id)
            ->with('user')
            ->get()
            ->map(function ($child) {
                return [
                    'id' => $child->id,
                    'name' => $child->user?->name ?? 'Unknown'
                ];
            });
    }

    // Get filtered invoices
    public function invoices(): LengthAwarePaginator
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        // Get all children IDs for this parent
        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        if (empty($childrenIds)) {
            return new LengthAwarePaginator([], 0, $this->perPage);
        }

        return Invoice::query()
            ->whereIn('child_profile_id', $childrenIds)
            ->with(['student.user', 'curriculum', 'academicYear'])
            ->whereBetween('due_date', [$this->startDate, $this->endDate])
            ->when($this->search, function (Builder $query) {
                $query->where(function($q) {
                    $q->where('invoice_number', 'like', '%' . $this->search . '%')
                      ->orWhere('description', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->child, function (Builder $query) {
                $query->where('child_profile_id', $this->child);
            })
            ->when($this->status, function (Builder $query) {
                $query->where('status', $this->status);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate($this->perPage);
    }

    // Get invoice statistics
    public function invoiceStats()
    {
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            return [
                'total' => 0,
                'paid' => 0,
                'pending' => 0,
                'overdue' => 0,
                'totalAmount' => 0,
                'paidAmount' => 0,
                'pendingAmount' => 0,
                'overdueAmount' => 0
            ];
        }

        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        if (empty($childrenIds)) {
            return [
                'total' => 0,
                'paid' => 0,
                'pending' => 0,
                'overdue' => 0,
                'totalAmount' => 0,
                'paidAmount' => 0,
                'pendingAmount' => 0,
                'overdueAmount' => 0
            ];
        }

        $invoices = Invoice::whereIn('child_profile_id', $childrenIds)
            ->whereBetween('due_date', [$this->startDate, $this->endDate])
            ->when($this->child, function (Builder $query) {
                $query->where('child_profile_id', $this->child);
            })
            ->get();

        $total = $invoices->count();
        $paid = $invoices->where('status', 'paid')->count();
        $pending = $invoices->where('status', 'pending')->count();
        $overdue = $invoices->where('status', 'overdue')->count();

        $totalAmount = $invoices->sum('amount');
        $paidAmount = $invoices->where('status', 'paid')->sum('amount');
        $pendingAmount = $invoices->where('status', 'pending')->sum('amount');
        $overdueAmount = $invoices->where('status', 'overdue')->sum('amount');

        return [
            'total' => $total,
            'paid' => $paid,
            'pending' => $pending,
            'overdue' => $overdue,
            'totalAmount' => $totalAmount,
            'paidAmount' => $paidAmount,
            'pendingAmount' => $pendingAmount,
            'overdueAmount' => $overdueAmount
        ];
    }

    // Download invoice
    public function downloadInvoice($invoiceId)
    {
        $this->redirect(route('parent.invoices.download', $invoiceId));
    }

    // Pay invoice
    public function payInvoice($invoiceId)
    {
        $this->redirect(route('parent.invoices.pay', $invoiceId));
    }

    public function with(): array
    {
        return [
            'invoices' => $this->invoices(),
            'children' => $this->children(),
            'invoiceStats' => $this->invoiceStats(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Invoices" separator progress-indicator>
        <x-slot:subtitle>
            View and manage your payment invoices
        </x-slot:subtitle>

        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search invoices..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$child, $status, $period !== 'all']))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- QUICK STATS PANEL -->
    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-4">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-document-text" class="w-8 h-8" />
            </div>
            <div class="stat-title">Total Invoices</div>
            <div class="stat-value">{{ $invoiceStats['total'] }}</div>
            <div class="stat-desc">{{ number_format($invoiceStats['totalAmount'], 2) }} Total Amount</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-success">
                <x-icon name="o-check-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Paid</div>
            <div class="stat-value text-success">{{ $invoiceStats['paid'] }}</div>
            <div class="stat-desc">{{ number_format($invoiceStats['paidAmount'], 2) }} Paid Amount</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-warning">
                <x-icon name="o-clock" class="w-8 h-8" />
            </div>
            <div class="stat-title">Pending</div>
            <div class="stat-value text-warning">{{ $invoiceStats['pending'] }}</div>
            <div class="stat-desc">{{ number_format($invoiceStats['pendingAmount'], 2) }} Pending Amount</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-error">
                <x-icon name="o-exclamation-circle" class="w-8 h-8" />
            </div>
            <div class="stat-title">Overdue</div>
            <div class="stat-value text-error">{{ $invoiceStats['overdue'] }}</div>
            <div class="stat-desc">{{ number_format($invoiceStats['overdueAmount'], 2) }} Overdue Amount</div>
        </div>
    </div>

    <!-- DATE RANGE SELECTOR -->
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div class="flex flex-wrap gap-2">
            <x-button
                label="This Month"
                @click="$wire.setPeriod('month')"
                class="{{ $period === 'month' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="This Quarter"
                @click="$wire.setPeriod('quarter')"
                class="{{ $period === 'quarter' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="Last 6 Months"
                @click="$wire.setPeriod('semester')"
                class="{{ $period === 'semester' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="Last Year"
                @click="$wire.setPeriod('year')"
                class="{{ $period === 'year' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
            <x-button
                label="All Time"
                @click="$wire.setPeriod('all')"
                class="{{ $period === 'all' ? 'btn-primary' : 'btn-outline' }}"
                size="sm"
            />
        </div>

        <div class="flex items-center gap-2">
            <x-input type="date" wire:model.live="startDate" />
            <span>to</span>
            <x-input type="date" wire:model.live="endDate" />
            <x-button
                label="Apply"
                icon="o-check"
                @click="$wire.setPeriod('custom')"
                class="btn-primary"
                size="sm"
            />
        </div>
    </div>

    <!-- INVOICES TABLE -->
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
                        <th>Child</th>
                        <th>Description</th>
                        <th class="cursor-pointer" wire:click="sortBy('due_date')">
                            <div class="flex items-center">
                                Due Date
                                @if ($sortBy['column'] === 'due_date')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('amount')">
                            <div class="flex items-center">
                                Amount
                                @if ($sortBy['column'] === 'amount')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('status')">
                            <div class="flex items-center">
                                Status
                                @if ($sortBy['column'] === 'status')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr class="hover">
                            <td>{{ $invoice->invoice_number }}</td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="avatar">
                                        <div class="w-10 h-10 mask mask-squircle">
                                            @if ($invoice->student->photo)
                                                <img src="{{ asset('storage/' . $invoice->student->photo) }}" alt="{{ $invoice->student->user?->name ?? 'Child' }}">
                                            @else
                                                <img src="{{ $invoice->student->user?->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Child&color=7F9CF5&background=EBF4FF' }}" alt="{{ $invoice->student->user?->name ?? 'Child' }}">
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        {{ $invoice->student->user?->name ?? 'Unknown Child' }}
                                    </div>
                                </div>
                            </td>
                            <td>{{ $invoice->description }}</td>
                            <td>{{ $invoice->due_date->format('d/m/y') }}</td>
                            <td>{{ number_format($invoice->amount, 2) }}</td>
                            <td>
                                <x-badge
                                    label="{{ ucfirst($invoice->status) }}"
                                    color="{{ match($invoice->status) {
                                        'paid' => 'success',
                                        'pending' => 'warning',
                                        'overdue' => 'error',
                                        default => 'ghost'
                                    } }}"
                                />
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button
                                        icon="o-arrow-down-tray"
                                        color="secondary"
                                        size="sm"
                                        tooltip="Download Invoice"
                                        wire:click="downloadInvoice({{ $invoice->id }})"
                                    />

                                    @if($invoice->status !== 'paid')
                                        <x-button
                                            icon="o-credit-card"
                                            color="primary"
                                            size="sm"
                                            tooltip="Pay Now"
                                            wire:click="payInvoice({{ $invoice->id }})"
                                        />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-document-text" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No invoices found</h3>
                                    <p class="text-gray-500">No records match your current filters for the selected time period</p>
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
    </x-card>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search invoices"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Invoice number or description..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by child"
                    placeholder="All children"
                    :options="$children"
                    wire:model.live="child"
                    option-label="name"
                    option-value="id"
                    empty-message="No children found"
                />
            </div>

            <div>
                <x-select
                    label="Filter by status"
                    placeholder="All statuses"
                    :options="[
                        ['label' => 'Paid', 'value' => 'paid'],
                        ['label' => 'Pending', 'value' => 'pending'],
                        ['label' => 'Overdue', 'value' => 'overdue']
                    ]"
                    wire:model.live="status"
                    option-label="label"
                    option-value="value"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[10, 25, 50, 100]"
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
