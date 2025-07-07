<?php

use App\Models\PaymentPlan;
use App\Models\Curriculum;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Payment Plans Management')] class extends Component {
    use WithPagination, Toast;

    public string $search = '';
    public string $curriculumFilter = '';
    public string $statusFilter = '';
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    public function mount(): void
    {
        // Initialize component
    }

    public function getPaymentPlansProperty()
    {
        return PaymentPlan::with(['curriculum'])
            ->when($this->search, function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%')
                      ->orWhere('description', 'like', '%' . $this->search . '%');
            })
            ->when($this->curriculumFilter, function ($query) {
                $query->where('curriculum_id', $this->curriculumFilter);
            })
            ->when($this->statusFilter, function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate(15);
    }

    public function getCurriculaProperty()
    {
        return Curriculum::orderBy('name')->get();
    }

    // Safe method to get curriculum route
    public function getCurriculumRoute($curriculum): ?string
    {
        if ($curriculum && isset($curriculum->id)) {
            try {
                return route('admin.curricula.show', $curriculum->id);
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

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

    public function deletePaymentPlan($paymentPlanId): void
    {
        $paymentPlan = PaymentPlan::find($paymentPlanId);

        if ($paymentPlan) {
            $paymentPlan->delete();
            $this->success('Payment plan deleted successfully.');
        } else {
            $this->error('Payment plan not found.');
        }
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->curriculumFilter = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    // Update methods for reactive properties
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedCurriculumFilter(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Payment Plans Management" separator>
        <x-slot:middle>
            <div class="flex flex-col space-y-3 lg:flex-row lg:items-center lg:space-y-0 lg:space-x-4">
                <!-- Search -->
                <div class="flex-1 max-w-md">
                    <x-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search payment plans..."
                        icon="o-magnifying-glass"
                        clearable
                    />
                </div>

                <!-- Mobile Filter Toggle (visible only on mobile) -->
                <div class="flex gap-2 lg:hidden">
                    <button
                        @click="showMobileFilters = !showMobileFilters"
                        class="flex-1 px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                        x-data="{ showMobileFilters: false }"
                    >
                        <svg class="inline w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.207A1 1 0 013 6.5V4z"></path>
                        </svg>
                        Filters
                    </button>
                </div>

                <!-- Desktop Filters (hidden on mobile) -->
                <div class="hidden lg:flex lg:items-center lg:space-x-4 lg:flex-1">
                    <!-- Curriculum Filter -->
                    <div class="flex-1 max-w-xs">
                        <select wire:model.live="curriculumFilter" class="w-full select select-bordered select-sm">
                            <option value="">All Curricula</option>
                            @foreach($this->curricula as $curriculum)
                                <option value="{{ $curriculum->id }}">{{ $curriculum->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div class="flex-1 max-w-xs">
                        <select wire:model.live="statusFilter" class="w-full select select-bordered select-sm">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="draft">Draft</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Mobile Filters Dropdown -->
            <div class="mt-3 lg:hidden" x-data="{ showMobileFilters: false }" x-show="showMobileFilters" x-transition>
                <div class="grid grid-cols-1 gap-3 p-4 rounded-lg sm:grid-cols-2 bg-gray-50">
                    <div>
                        <label class="block mb-1 text-sm font-medium text-gray-700">Curriculum</label>
                        <select wire:model.live="curriculumFilter" class="w-full select select-bordered select-sm">
                            <option value="">All Curricula</option>
                            @foreach($this->curricula as $curriculum)
                                <option value="{{ $curriculum->id }}">{{ $curriculum->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block mb-1 text-sm font-medium text-gray-700">Status</label>
                        <select wire:model.live="statusFilter" class="w-full select select-bordered select-sm">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="draft">Draft</option>
                        </select>
                    </div>
                </div>
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Reset Filters"
                icon="o-x-mark"
                wire:click="resetFilters"
                class="btn-ghost btn-sm"
                responsive
            />
            <x-button
                label="Create Plan"
                icon="o-plus"
                link="{{ route('admin.payment-plans.create') }}"
                class="btn-primary"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Payment Plans -->
    <div class="overflow-hidden bg-white rounded-lg shadow">
        <!-- Mobile/Tablet Card View (hidden on desktop) -->
        <div class="block lg:hidden">
            <div class="divide-y divide-gray-200">
                @forelse($this->paymentPlans as $plan)
                    <div class="p-4 transition-colors hover:bg-gray-50">
                        <!-- Plan Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900 truncate">{{ $plan->name }}</h3>
                                @if($plan->description)
                                    <p class="mt-1 text-sm text-gray-600 line-clamp-2">
                                        {{ Str::limit($plan->description, 80) }}
                                    </p>
                                @endif
                            </div>
                            <!-- Status Badge -->
                            <div class="ml-3">
                                @if(isset($plan->status))
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        @if($plan->status === 'active') text-green-800 bg-green-100
                                        @elseif($plan->status === 'inactive') text-red-800 bg-red-100
                                        @elseif($plan->status === 'draft') text-yellow-800 bg-yellow-100
                                        @else text-gray-800 bg-gray-100 @endif">
                                        {{ ucfirst($plan->status) }}
                                    </span>
                                @else
                                    <span class="text-sm text-gray-500">Unknown</span>
                                @endif
                            </div>
                        </div>

                        <!-- Amount - Prominent Display -->
                        <div class="p-3 mb-4 border border-green-200 rounded-lg bg-green-50">
                            <div class="flex items-center justify-between">
                                <div>
                                    <label class="block mb-1 text-xs font-medium tracking-wide text-green-700 uppercase">Plan Amount</label>
                                    <div class="text-2xl font-bold text-green-900">
                                        @if(isset($plan->amount))
                                            ${{ number_format($plan->amount, 2) }}
                                        @else
                                            <span class="text-lg text-gray-500">N/A</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="text-green-600">
                                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- Curriculum and Created Date -->
                        <div class="grid grid-cols-1 gap-3 mb-4 sm:grid-cols-2">
                            <div>
                                <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Curriculum</label>
                                @if($plan->curriculum)
                                    @php
                                        $curriculumRoute = $this->getCurriculumRoute($plan->curriculum);
                                    @endphp
                                    @if($curriculumRoute)
                                        <a href="{{ $curriculumRoute }}" class="text-blue-600 hover:text-blue-800 hover:underline">
                                            <div class="text-sm font-medium">{{ $plan->curriculum->name }}</div>
                                            @if($plan->curriculum->code)
                                                <div class="text-xs text-gray-500">{{ $plan->curriculum->code }}</div>
                                            @endif
                                        </a>
                                    @else
                                        <div class="text-sm font-medium text-gray-900">{{ $plan->curriculum->name }}</div>
                                        @if($plan->curriculum->code)
                                            <div class="text-xs text-gray-500">{{ $plan->curriculum->code }}</div>
                                        @endif
                                    @endif
                                @else
                                    <span class="text-sm italic text-gray-500">No curriculum assigned</span>
                                @endif
                            </div>
                            <div>
                                <label class="block mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">Created</label>
                                <div class="text-sm text-gray-900">
                                    {{ $plan->created_at ? $plan->created_at->format('M d, Y') : 'N/A' }}
                                </div>
                                @if($plan->created_at)
                                    <div class="text-xs text-gray-500">{{ $plan->created_at->diffForHumans() }}</div>
                                @endif
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex gap-2">
                            <x-button
                                icon="o-eye"
                                link="{{ route('admin.payment-plans.show', $plan) }}"
                                tooltip="View Details"
                                class="flex-1 btn-sm btn-ghost"
                                label="View"
                            />
                            <x-button
                                icon="o-pencil"
                                link="{{ route('admin.payment-plans.edit', $plan) }}"
                                tooltip="Edit"
                                class="flex-1 btn-sm btn-ghost"
                                label="Edit"
                            />
                            <x-button
                                icon="o-trash"
                                wire:click="deletePaymentPlan({{ $plan->id }})"
                                wire:confirm="Are you sure you want to delete this payment plan?"
                                tooltip="Delete"
                                class="flex-1 text-red-600 btn-sm btn-ghost"
                                label="Delete"
                            />
                        </div>
                    </div>
                @empty
                    <div class="px-6 py-12 text-center">
                        <div class="text-center">
                            <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">No payment plans found</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                @if($search || $curriculumFilter || $statusFilter)
                                    Try adjusting your search or filter criteria.
                                @else
                                    Get started by creating a new payment plan.
                                @endif
                            </p>
                            @if(!$search && !$curriculumFilter && !$statusFilter)
                                <div class="mt-6">
                                    <x-button
                                        label="Create Payment Plan"
                                        icon="o-plus"
                                        link="{{ route('admin.payment-plans.create') }}"
                                        class="btn-primary"
                                    />
                                </div>
                            @endif
                        </div>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Desktop Table View (hidden on mobile/tablet) -->
        <div class="hidden lg:block">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase cursor-pointer hover:bg-gray-100"
                                wire:click="sortBy('name')">
                                Plan Name
                                @if($sortBy['column'] === 'name')
                                    <span class="ml-1">{{ $sortBy['direction'] === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                Curriculum
                            </th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase cursor-pointer hover:bg-gray-100"
                                wire:click="sortBy('amount')">
                                Amount
                                @if($sortBy['column'] === 'amount')
                                    <span class="ml-1">{{ $sortBy['direction'] === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                Status
                            </th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase cursor-pointer hover:bg-gray-100"
                                wire:click="sortBy('created_at')">
                                Created
                                @if($sortBy['column'] === 'created_at')
                                    <span class="ml-1">{{ $sortBy['direction'] === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($this->paymentPlans as $plan)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ $plan->name }}
                                    </div>
                                    @if($plan->description)
                                        <div class="text-sm text-gray-500">
                                            {{ Str::limit($plan->description, 50) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($plan->curriculum)
                                        @php
                                            $curriculumRoute = $this->getCurriculumRoute($plan->curriculum);
                                        @endphp
                                        @if($curriculumRoute)
                                            <a href="{{ $curriculumRoute }}" class="text-blue-600 hover:text-blue-800 hover:underline">
                                                <div class="text-sm font-medium">{{ $plan->curriculum->name }}</div>
                                                @if($plan->curriculum->code)
                                                    <div class="text-sm text-gray-500">{{ $plan->curriculum->code }}</div>
                                                @endif
                                            </a>
                                        @else
                                            <div class="text-sm font-medium text-gray-900">{{ $plan->curriculum->name }}</div>
                                            @if($plan->curriculum->code)
                                                <div class="text-sm text-gray-500">{{ $plan->curriculum->code }}</div>
                                            @endif
                                        @endif
                                    @else
                                        <span class="text-sm text-gray-500">No curriculum assigned</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900 whitespace-nowrap">
                                    @if(isset($plan->amount))
                                        ${{ number_format($plan->amount, 2) }}
                                    @else
                                        <span class="text-gray-500">N/A</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if(isset($plan->status))
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            @if($plan->status === 'active') text-green-800 bg-green-100
                                            @elseif($plan->status === 'inactive') text-red-800 bg-red-100
                                            @elseif($plan->status === 'draft') text-yellow-800 bg-yellow-100
                                            @else text-gray-800 bg-gray-100 @endif">
                                            {{ ucfirst($plan->status) }}
                                        </span>
                                    @else
                                        <span class="text-gray-500">Unknown</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                    {{ $plan->created_at ? $plan->created_at->format('M d, Y') : 'N/A' }}
                                </td>
                                <td class="px-6 py-4 text-sm font-medium whitespace-nowrap">
                                    <div class="flex items-center space-x-2">
                                        <x-button
                                            icon="o-eye"
                                            link="{{ route('admin.payment-plans.show', $plan) }}"
                                            tooltip="View Details"
                                            class="btn-xs btn-ghost"
                                        />
                                        <x-button
                                            icon="o-pencil"
                                            link="{{ route('admin.payment-plans.edit', $plan) }}"
                                            tooltip="Edit"
                                            class="btn-xs btn-ghost"
                                        />
                                        <x-button
                                            icon="o-trash"
                                            wire:click="deletePaymentPlan({{ $plan->id }})"
                                            wire:confirm="Are you sure you want to delete this payment plan?"
                                            tooltip="Delete"
                                            class="text-red-600 btn-xs btn-ghost"
                                        />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center">
                                    <div class="text-center">
                                        <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                                        </svg>
                                        <h3 class="mt-2 text-sm font-medium text-gray-900">No payment plans found</h3>
                                        <p class="mt-1 text-sm text-gray-500">
                                            @if($search || $curriculumFilter || $statusFilter)
                                                Try adjusting your search or filter criteria.
                                            @else
                                                Get started by creating a new payment plan.
                                            @endif
                                        </p>
                                        @if(!$search && !$curriculumFilter && !$statusFilter)
                                            <div class="mt-6">
                                                <x-button
                                                    label="Create Payment Plan"
                                                    icon="o-plus"
                                                    link="{{ route('admin.payment-plans.create') }}"
                                                    class="btn-primary"
                                                />
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        @if($this->paymentPlans->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $this->paymentPlans->links() }}
            </div>
        @endif
    </div>
</div>
