<?php

use App\Models\PaymentPlan;
use App\Models\ActivityLog;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Payment Plan Details')] class extends Component {
    use WithPagination;
    use Toast;

    public PaymentPlan $paymentPlan;
    public int $perPage = 10;
    public string $activeTab = 'enrollments';

    // Initialize component
    public function mount(PaymentPlan $paymentPlan): void
    {
        $this->paymentPlan = $paymentPlan;

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Viewed payment plan details for {$paymentPlan->type}",
            PaymentPlan::class,
            $paymentPlan->id,
            ['ip' => request()->ip()]
        );
    }

    // Handle tab switching
    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    // Get program enrollments for this payment plan
    public function programEnrollments(): LengthAwarePaginator
    {
        return $this->paymentPlan->programEnrollments()
            ->with(['childProfile', 'academicYear', 'curriculum'])
            ->paginate($this->perPage);
    }

    // Get invoices for this payment plan
    public function invoices(): LengthAwarePaginator
    {
        return $this->paymentPlan->invoices()
            ->with(['programEnrollment', 'programEnrollment.childProfile'])
            ->paginate($this->perPage);
    }

    public function with(): array
    {
        return [
            'paymentPlan' => $this->paymentPlan,
            'enrollmentsCount' => $this->paymentPlan->programEnrollments()->count(),
            'invoicesCount' => $this->paymentPlan->invoices()->count(),
            'programEnrollments' => $this->activeTab === 'enrollments' ? $this->programEnrollments() : null,
            'invoices' => $this->activeTab === 'invoices' ? $this->invoices() : null,
            'curriculum' => $this->paymentPlan->curriculum,
        ];
    }
};?>


<div>
    <!-- Page header -->
    <x-header title="Payment Plan Details: {{ $paymentPlan->type }}" separator>
        <x-slot:actions>
            <x-button
                label="Back to Payment Plans"
                icon="o-arrow-left"
                link="{{ route('admin.payment-plans.index') }}"
                class="btn-ghost"
            />

            <x-button
                label="Edit"
                icon="o-pencil"
                link="{{ route('admin.payment-plans.edit', $paymentPlan->id) }}"
                class="btn-info"
            />
        </x-slot:actions>
    </x-header>

    <!-- Payment Plan Info Card -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-1">
            <div class="p-5">
                <h2 class="mb-4 text-xl font-semibold">Payment Plan Information</h2>

                <div class="space-y-4">
                    <div>
                        <span class="block text-sm font-medium text-gray-500">Type</span>
                        <div class="mt-1">
                            <x-badge
                                label="{{ $paymentPlan->type }}"
                                color="{{ match(strtolower($paymentPlan->type ?? '')) {
                                    'monthly' => 'success',
                                    'quarterly' => 'info',
                                    'annual' => 'warning',
                                    'one-time' => 'error',
                                    default => 'ghost'
                                } }}"
                            />
                        </div>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Amount</span>
                        <span class="block mt-1 font-mono text-lg font-bold">${{ number_format($paymentPlan->amount, 2) }}</span>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Due Day</span>
                        <span class="block mt-1">
                            @if ($paymentPlan->due_day)
                                {{ $paymentPlan->due_day }}<sup>{{ $paymentPlan->due_day == 1 ? 'st' : ($paymentPlan->due_day == 2 ? 'nd' : ($paymentPlan->due_day == 3 ? 'rd' : 'th')) }}</sup> of month
                            @else
                                <span class="text-gray-400">N/A</span>
                            @endif
                        </span>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Curriculum</span>
                        <div class="mt-1">
                            <a href="{{ route('admin.curricula.show', $curriculum->id) }}" class="link link-hover">
                                {{ $curriculum->name }}
                            </a>
                        </div>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Created At</span>
                        <span class="block mt-1">{{ $paymentPlan->created_at->format('F d, Y') }}</span>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Last Updated</span>
                        <span class="block mt-1">{{ $paymentPlan->updated_at->format('F d, Y') }}</span>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card class="lg:col-span-2">
            <div class="p-5">
                <h2 class="mb-4 text-xl font-semibold">Statistics</h2>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="p-4 rounded-lg bg-base-200">
                        <div class="flex items-center">
                            <div class="p-3 mr-4 rounded-full bg-primary/20">
                                <x-icon name="o-user-group" class="w-8 h-8 text-primary" />
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">Program Enrollments</h3>
                                <div class="text-2xl font-bold">{{ $enrollmentsCount }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="p-4 rounded-lg bg-base-200">
                        <div class="flex items-center">
                            <div class="p-3 mr-4 rounded-full bg-secondary/20">
                                <x-icon name="o-document-text" class="w-8 h-8 text-secondary" />
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">Invoices</h3>
                                <div class="text-2xl font-bold">{{ $invoicesCount }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <h3 class="font-semibold">Payment Frequency</h3>
                    <div class="mt-2">
                        <div class="text-sm">
                            @if(strtolower($paymentPlan->type) === 'monthly')
                                This payment plan is billed <span class="font-semibold">monthly</span> on the
                                @if($paymentPlan->due_day)
                                    {{ $paymentPlan->due_day }}<sup>{{ $paymentPlan->due_day == 1 ? 'st' : ($paymentPlan->due_day == 2 ? 'nd' : ($paymentPlan->due_day == 3 ? 'rd' : 'th')) }}</sup> day
                                @else
                                    <span class="font-semibold text-error">no due day specified</span>
                                @endif
                                of each month.
                            @elseif(strtolower($paymentPlan->type) === 'quarterly')
                                This payment plan is billed <span class="font-semibold">every 3 months</span> on the
                                @if($paymentPlan->due_day)
                                    {{ $paymentPlan->due_day }}<sup>{{ $paymentPlan->due_day == 1 ? 'st' : ($paymentPlan->due_day == 2 ? 'nd' : ($paymentPlan->due_day == 3 ? 'rd' : 'th')) }}</sup> day
                                @else
                                    <span class="font-semibold text-error">no due day specified</span>
                                @endif
                                of the month.
                            @elseif(strtolower($paymentPlan->type) === 'annual')
                                This payment plan is billed <span class="font-semibold">once per year</span> on the
                                @if($paymentPlan->due_day)
                                    {{ $paymentPlan->due_day }}<sup>{{ $paymentPlan->due_day == 1 ? 'st' : ($paymentPlan->due_day == 2 ? 'nd' : ($paymentPlan->due_day == 3 ? 'rd' : 'th')) }}</sup> day
                                @else
                                    <span class="font-semibold text-error">no due day specified</span>
                                @endif
                                of the month.
                            @elseif(strtolower($paymentPlan->type) === 'one-time')
                                This is a <span class="font-semibold">one-time payment</span> and is not recurring.
                            @else
                                This payment plan has a custom type: <span class="font-semibold">{{ $paymentPlan->type }}</span>.
                                @if($paymentPlan->due_day)
                                    Due on the {{ $paymentPlan->due_day }}<sup>{{ $paymentPlan->due_day == 1 ? 'st' : ($paymentPlan->due_day == 2 ? 'nd' : ($paymentPlan->due_day == 3 ? 'rd' : 'th')) }}</sup> day of the month.
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Tabs for related data -->
    <div class="mt-6">
        <div class="mb-4 tabs tabs-boxed">
            <button
                class="tab {{ $activeTab === 'enrollments' ? 'tab-active' : '' }}"
                wire:click="switchTab('enrollments')"
            >
                Program Enrollments ({{ $enrollmentsCount }})
            </button>

            <button
                class="tab {{ $activeTab === 'invoices' ? 'tab-active' : '' }}"
                wire:click="switchTab('invoices')"
            >
                Invoices ({{ $invoicesCount }})
            </button>
        </div>

        <!-- Tab Content -->
        <x-card>
            @if ($activeTab === 'enrollments')
                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Student</th>
                                <th>Curriculum</th>
                                <th>Academic Year</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($programEnrollments as $enrollment)
                                <tr class="hover">
                                    <td>{{ $enrollment->id }}</td>
                                    <td>
                                        <div class="font-bold">
                                            <a href="{{ route('admin.child-profiles.show', $enrollment->childProfile->id) }}" class="link link-hover">
                                                {{ $enrollment->childProfile->full_name ?? 'Unknown student' }}
                                            </a>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.curricula.show', $enrollment->curriculum->id) }}" class="link link-hover">
                                            {{ $enrollment->curriculum->name ?? 'Unknown curriculum' }}
                                        </a>
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.academic-years.show', $enrollment->academicYear->id) }}" class="link link-hover">
                                            {{ $enrollment->academicYear->name ?? 'Unknown academic year' }}
                                        </a>
                                    </td>
                                    <td>
                                        <x-badge
                                            label="{{ $enrollment->status }}"
                                            color="{{ match(strtolower($enrollment->status ?? '')) {
                                                'active' => 'success',
                                                'pending' => 'warning',
                                                'completed' => 'info',
                                                'cancelled' => 'error',
                                                default => 'ghost'
                                            } }}"
                                        />
                                    </td>
                                    <td>
                                        <x-button
                                            icon="o-eye"
                                            link="{{ route('admin.program-enrollments.show', $enrollment->id) }}"
                                            color="secondary"
                                            size="sm"
                                        />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-4 text-center">
                                        <div class="flex flex-col items-center justify-center">
                                            <x-icon name="o-information-circle" class="w-12 h-12 text-gray-400" />
                                            <p class="mt-2 text-gray-500">No program enrollments found for this payment plan.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($programEnrollments && count($programEnrollments))
                    <div class="p-4 mt-4">
                        {{ $programEnrollments->links() }}
                    </div>
                @endif

            @elseif ($activeTab === 'invoices')
                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Invoice Number</th>
                                <th>Student</th>
                                <th>Amount</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($invoices as $invoice)
                                <tr class="hover">
                                    <td>{{ $invoice->id }}</td>
                                    <td>
                                        <div class="font-mono font-semibold">{{ $invoice->invoice_number }}</div>
                                    </td>
                                    <td>
                                        @if($invoice->programEnrollment && $invoice->programEnrollment->childProfile)
                                            <a href="{{ route('admin.child-profiles.show', $invoice->programEnrollment->childProfile->id) }}" class="link link-hover">
                                                {{ $invoice->programEnrollment->childProfile->full_name ?? 'Unknown student' }}
                                            </a>
                                        @else
                                            <span class="text-gray-400">Unknown student</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="font-mono font-bold">${{ number_format($invoice->amount, 2) }}</div>
                                    </td>
                                    <td>{{ $invoice->due_date->format('M d, Y') }}</td>
                                    <td>
                                        <x-badge
                                            label="{{ $invoice->status }}"
                                            color="{{ match(strtolower($invoice->status ?? '')) {
                                                'paid' => 'success',
                                                'pending' => 'warning',
                                                'overdue' => 'error',
                                                'cancelled' => 'ghost',
                                                default => 'info'
                                            } }}"
                                        />
                                    </td>
                                    <td>
                                        <x-button
                                            icon="o-eye"
                                            link="{{ route('admin.invoices.show', $invoice->id) }}"
                                            color="secondary"
                                            size="sm"
                                        />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-4 text-center">
                                        <div class="flex flex-col items-center justify-center">
                                            <x-icon name="o-information-circle" class="w-12 h-12 text-gray-400" />
                                            <p class="mt-2 text-gray-500">No invoices found for this payment plan.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($invoices && count($invoices))
                    <div class="p-4 mt-4">
                        {{ $invoices->links() }}
                    </div>
                @endif
            @endif
        </x-card>
    </div>
</div>
