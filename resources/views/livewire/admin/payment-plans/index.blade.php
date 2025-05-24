<?php

use App\Models\PaymentPlan;
use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Payment Plans Management')] class extends Component {
    use WithPagination;
    use Toast;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $curriculum = '';

    #[Url]
    public string $type = '';

    #[Url]
    public int $perPage = 10;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public array $sortBy = ['column' => 'id', 'direction' => 'desc'];

    // Modal state
    public bool $showDeleteModal = false;
    public ?int $paymentPlanToDelete = null;

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed payment plans management page',
            PaymentPlan::class,
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

    // Confirm deletion
    public function confirmDelete(int $paymentPlanId): void
    {
        $this->paymentPlanToDelete = $paymentPlanId;
        $this->showDeleteModal = true;
    }

    // Cancel deletion
    public function cancelDelete(): void
    {
        $this->paymentPlanToDelete = null;
        $this->showDeleteModal = false;
    }

    // Delete a payment plan
    public function deletePaymentPlan(): void
    {
        if ($this->paymentPlanToDelete) {
            $paymentPlan = PaymentPlan::find($this->paymentPlanToDelete);

            if ($paymentPlan) {
                // Get data for logging before deletion
                $paymentPlanDetails = [
                    'id' => $paymentPlan->id,
                    'type' => $paymentPlan->type,
                    'amount' => $paymentPlan->amount,
                    'curriculum_name' => $paymentPlan->curriculum->name ?? 'Unknown',
                    'curriculum_id' => $paymentPlan->curriculum_id
                ];

                try {
                    DB::beginTransaction();

                    // Check if payment plan has related records
                    $hasProgramEnrollments = $paymentPlan->programEnrollments()->exists();
                    $hasInvoices = $paymentPlan->invoices()->exists();

                    if ($hasProgramEnrollments || $hasInvoices) {
                        $this->error("Cannot delete payment plan. It has associated program enrollments or invoices.");
                        DB::rollBack();
                        $this->showDeleteModal = false;
                        $this->paymentPlanToDelete = null;
                        return;
                    }

                    // Delete payment plan
                    $paymentPlan->delete();

                    // Log the action
                    ActivityLog::log(
                        Auth::id(),
                        'delete',
                        "Deleted payment plan: {$paymentPlanDetails['type']} (\${$paymentPlanDetails['amount']})",
                        PaymentPlan::class,
                        $this->paymentPlanToDelete,
                        [
                            'payment_plan_type' => $paymentPlanDetails['type'],
                            'payment_plan_amount' => $paymentPlanDetails['amount'],
                            'curriculum_name' => $paymentPlanDetails['curriculum_name'],
                            'curriculum_id' => $paymentPlanDetails['curriculum_id']
                        ]
                    );

                    DB::commit();

                    // Show toast notification
                    $this->success("Payment plan {$paymentPlanDetails['type']} has been successfully deleted.");
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("An error occurred during deletion: {$e->getMessage()}");
                }
            } else {
                $this->error("Payment plan not found!");
            }
        }

        $this->showDeleteModal = false;
        $this->paymentPlanToDelete = null;
    }

    // Get filtered and paginated payment plans
    public function paymentPlans(): LengthAwarePaginator
    {
        return PaymentPlan::query()
            ->with(['curriculum']) // Eager load relationships
            ->withCount(['programEnrollments', 'invoices'])
            ->when($this->search, function (Builder $query) {
                $query->where('type', 'like', '%' . $this->search . '%')
                    ->orWhereHas('curriculum', function (Builder $q) {
                        $q->where('name', 'like', '%' . $this->search . '%')
                            ->orWhere('code', 'like', '%' . $this->search . '%');
                    });
            })
            ->when($this->curriculum, function (Builder $query) {
                $query->where('curriculum_id', $this->curriculum);
            })
            ->when($this->type, function (Builder $query) {
                $query->where('type', $this->type);
            })
            ->when($this->sortBy['column'] === 'curriculum', function (Builder $query) {
                $query->join('curricula', 'payment_plans.curriculum_id', '=', 'curricula.id')
                    ->orderBy('curricula.name', $this->sortBy['direction'])
                    ->select('payment_plans.*');
            }, function (Builder $query) {
                $query->orderBy($this->sortBy['column'], $this->sortBy['direction']);
            })
            ->paginate($this->perPage);
    }

    // Get curricula for filter
    public function curricula(): Collection
    {
        return Curriculum::query()
            ->orderBy('name')
            ->get();
    }

    // Get unique payment plan types for filter
    public function planTypes(): array
    {
        return PaymentPlan::query()
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->filter()
            ->toArray();
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->curriculum = '';
        $this->type = '';
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'paymentPlans' => $this->paymentPlans(),
            'curricula' => $this->curricula(),
            'planTypes' => $this->planTypes(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Payment Plans Management" separator progress-indicator>
        <!-- SEARCH -->
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search by type or curriculum..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <!-- ACTIONS -->
        <x-slot:actions>
            <x-button
                label="Filters"
                icon="o-funnel"
                :badge="count(array_filter([$curriculum, $type]))"
                badge-classes="font-mono"
                @click="$wire.showFilters = true"
                class="bg-base-300"
                responsive />

            <x-button
                label="New Payment Plan"
                icon="o-plus"
                link="{{ route('admin.payment-plans.create') }}"
                class="btn-primary"
                responsive />
        </x-slot:actions>
    </x-header>

    <!-- Payment Plans table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('id')">
                            <div class="flex items-center">
                                ID
                                @if ($sortBy['column'] === 'id')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('type')">
                            <div class="flex items-center">
                                Type
                                @if ($sortBy['column'] === 'type')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('curriculum')">
                            <div class="flex items-center">
                                Curriculum
                                @if ($sortBy['column'] === 'curriculum')
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
                        <th class="cursor-pointer" wire:click="sortBy('due_day')">
                            <div class="flex items-center">
                                Due Day
                                @if ($sortBy['column'] === 'due_day')
                                    <x-icon name="{{ $sortBy['direction'] === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Stats</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($paymentPlans as $plan)
                        <tr class="hover">
                            <td>{{ $plan->id }}</td>
                            <td>
                                <div class="font-bold">
                                    <x-badge
                                        label="{{ $plan->type }}"
                                        color="{{ match(strtolower($plan->type ?? '')) {
                                            'monthly' => 'success',
                                            'quarterly' => 'info',
                                            'annual' => 'warning',
                                            'one-time' => 'error',
                                            default => 'ghost'
                                        } }}"
                                    />
                                </div>
                            </td>
                            <td>
                                <a href="{{ route('admin.curricula.show', $plan->curriculum_id) }}" class="link link-hover">
                                    {{ $plan->curriculum->name ?? 'Unknown curriculum' }}
                                </a>
                            </td>
                            <td>
                                <div class="font-mono font-bold">${{ number_format($plan->amount, 2) }}</div>
                            </td>
                            <td>
                                @if ($plan->due_day)
                                    {{ $plan->due_day }}<sup>{{ $plan->due_day == 1 ? 'st' : ($plan->due_day == 2 ? 'nd' : ($plan->due_day == 3 ? 'rd' : 'th')) }}</sup> of month
                                @else
                                    <span class="text-gray-400">N/A</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex gap-2">
                                    <div class="tooltip" data-tip="Program Enrollments">
                                        <x-badge label="{{ $plan->program_enrollments_count }}" icon="o-user-group" />
                                    </div>
                                    <div class="tooltip" data-tip="Invoices">
                                        <x-badge label="{{ $plan->invoices_count }}" icon="o-document-text" />
                                    </div>
                                </div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button
                                        icon="o-eye"
                                        link="{{ route('admin.payment-plans.show', $plan->id) }}"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View"
                                    />

                                    <x-button
                                        icon="o-pencil"
                                        link="{{ route('admin.payment-plans.edit', $plan->id) }}"
                                        color="info"
                                        size="sm"
                                        tooltip="Edit"
                                    />

                                    <x-button
                                        icon="o-trash"
                                        wire:click="confirmDelete({{ $plan->id }})"
                                        color="error"
                                        size="sm"
                                        tooltip="Delete"
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-face-frown" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No payment plans found</h3>
                                    <p class="text-gray-500">Try modifying your filters or create a new payment plan</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $paymentPlans->links() }}
        </div>
    </x-card>

    <!-- Delete confirmation modal -->
    <x-modal wire:model="showDeleteModal" title="Delete Confirmation">
        <div class="p-4">
            <div class="flex items-center gap-4">
                <div class="p-3 rounded-full bg-error/20">
                    <x-icon name="o-exclamation-triangle" class="w-8 h-8 text-error" />
                </div>
                <div>
                    <h3 class="text-lg font-semibold">Are you sure you want to delete this payment plan?</h3>
                    <p class="text-gray-600">This action is irreversible. Payment plans with associated program enrollments or invoices cannot be deleted.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancelDelete" />
            <x-button label="Delete" icon="o-trash" wire:click="deletePaymentPlan" color="error" />
        </x-slot:actions>
    </x-modal>

    <!-- Filters drawer -->
    <x-drawer wire:model="showFilters" title="Advanced Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search by type or curriculum"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by curriculum"
                    placeholder="All curricula"
                    :options="$curricula"
                    wire:model.live="curriculum"
                    option-label="name"
                    option-value="id"
                    empty-message="No curricula found"
                />
            </div>

            <div>
                <x-select
                    label="Filter by payment plan type"
                    placeholder="All types"
                    :options="array_combine($planTypes, $planTypes)"
                    wire:model.live="type"
                    empty-message="No plan types found"
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
