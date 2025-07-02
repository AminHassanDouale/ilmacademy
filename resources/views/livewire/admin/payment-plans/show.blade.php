<?php

use App\Models\PaymentPlan;
use App\Models\ProgramEnrollment;
use App\Models\Invoice;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Payment Plan Details')] class extends Component {
    use WithPagination, Toast;

    public PaymentPlan $paymentPlan;
    public string $activeTab = 'overview';

    public function mount(PaymentPlan $paymentPlan): void
    {
        $this->paymentPlan = $paymentPlan->load(['curriculum', 'programEnrollments.student.user', 'invoices.student.user']);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed payment plan details: {$paymentPlan->name}",
            PaymentPlan::class,
            $paymentPlan->id,
            [
                'payment_plan_name' => $paymentPlan->name,
                'payment_plan_type' => $paymentPlan->type,
                'ip' => request()->ip()
            ]
        );
    }

    public function toggleStatus(): void
    {
        try {
            $oldStatus = $this->paymentPlan->is_active;
            $this->paymentPlan->update([
                'is_active' => !$this->paymentPlan->is_active
            ]);

            $status = $this->paymentPlan->is_active ? 'activated' : 'deactivated';

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                "Payment plan {$status}: {$this->paymentPlan->name}",
                PaymentPlan::class,
                $this->paymentPlan->id,
                [
                    'payment_plan_name' => $this->paymentPlan->name,
                    'old_status' => $oldStatus,
                    'new_status' => $this->paymentPlan->is_active
                ]
            );

            $this->success("Payment plan has been {$status} successfully.");
            $this->paymentPlan->refresh();
        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    public function deletePaymentPlan(): void
    {
        try {
            // Check if payment plan is being used
            $enrollmentCount = $this->paymentPlan->programEnrollments()->count();
            $invoiceCount = $this->paymentPlan->invoices()->count();

            if ($enrollmentCount > 0 || $invoiceCount > 0) {
                $this->error("Cannot delete payment plan. It is currently being used by {$enrollmentCount} enrollment(s) and {$invoiceCount} invoice(s).");
                return;
            }

            // Log activity before deletion
            ActivityLog::log(
                Auth::id(),
                'delete',
                "Deleted payment plan: {$this->paymentPlan->name}",
                PaymentPlan::class,
                $this->paymentPlan->id,
                [
                    'payment_plan_data' => $this->paymentPlan->toArray(),
                    'curriculum_name' => $this->paymentPlan->curriculum?->name
                ]
            );

            $planName = $this->paymentPlan->name;
            $this->paymentPlan->delete();

            $this->success("Payment plan '{$planName}' has been deleted successfully.");
            $this->redirect(route('admin.payment-plans.index'));
        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    public function getStatsProperty(): array
    {
        return [
            'total_enrollments' => $this->paymentPlan->programEnrollments()->count(),
            'active_enrollments' => $this->paymentPlan->programEnrollments()->where('status', 'active')->count(),
            'total_invoices' => $this->paymentPlan->invoices()->count(),
            'paid_invoices' => $this->paymentPlan->invoices()->where('status', 'paid')->count(),
            'pending_invoices' => $this->paymentPlan->invoices()->where('status', 'pending')->count(),
            'total_revenue' => $this->paymentPlan->invoices()->where('status', 'paid')->sum('total_amount'),
            'pending_revenue' => $this->paymentPlan->invoices()->where('status', 'pending')->sum('total_amount'),
        ];
    }

    public function getRecentEnrollmentsProperty()
    {
        return $this->paymentPlan->programEnrollments()
            ->with(['student.user'])
            ->latest()
            ->take(5)
            ->get();
    }

    public function getRecentInvoicesProperty()
    {
        return $this->paymentPlan->invoices()
            ->with(['student.user'])
            ->latest()
            ->take(5)
            ->get();
    }

    public function getCurrencySymbolProperty(): string
    {
        return match($this->paymentPlan->currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => $this->paymentPlan->currency . ' '
        };
    }

    public function getStatusColorProperty(): string
    {
        return $this->paymentPlan->is_active ? 'text-green-800 bg-green-100' : 'text-red-800 bg-red-100';
    }

    public function getTypeColorProperty(): string
    {
        return match($this->paymentPlan->type) {
            'monthly' => 'text-blue-800 bg-blue-100',
            'quarterly' => 'text-purple-800 bg-purple-100',
            'semi-annual' => 'text-indigo-800 bg-indigo-100',
            'annual' => 'text-green-800 bg-green-100',
            'one-time' => 'text-orange-800 bg-orange-100',
            default => 'text-gray-800 bg-gray-100'
        };
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Payment Plan: {{ $paymentPlan->name }}" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-2">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->statusColor }}">
                    {{ $paymentPlan->is_active ? 'Active' : 'Inactive' }}
                </span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->typeColor }}">
                    {{ ucfirst($paymentPlan->type) }}
                </span>
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Back to Plans"
                icon="o-arrow-left"
                link="{{ route('admin.payment-plans.index') }}"
                class="btn-ghost"
            />
            <x-button
                label="{{ $paymentPlan->is_active ? 'Deactivate' : 'Activate' }}"
                icon="{{ $paymentPlan->is_active ? 'o-x-mark' : 'o-check' }}"
                wire:click="toggleStatus"
                wire:confirm="Are you sure you want to {{ $paymentPlan->is_active ? 'deactivate' : 'activate' }} this payment plan?"
                class="{{ $paymentPlan->is_active ? 'btn-warning' : 'btn-success' }}"
            />
            <x-button
                label="Edit"
                icon="o-pencil"
                link="{{ route('admin.payment-plans.edit', $paymentPlan) }}"
                class="btn-primary"
            />
            <x-dropdown>
                <x-slot:trigger>
                    <x-button icon="o-ellipsis-vertical" class="btn-ghost btn-sm" />
                </x-slot:trigger>
                <x-menu-item
                    title="Delete Payment Plan"
                    icon="o-trash"
                    wire:click="deletePaymentPlan"
                    wire:confirm="Are you sure you want to delete this payment plan? This action cannot be undone."
                    class="text-red-600"
                />
            </x-dropdown>
        </x-slot:actions>
    </x-header>

    <!-- Navigation Tabs -->
    <div class="mb-6">
        <nav class="flex space-x-8" aria-label="Tabs">
            <button
                wire:click="$set('activeTab', 'overview')"
                class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'overview' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
            >
                Overview
            </button>
            <button
                wire:click="$set('activeTab', 'enrollments')"
                class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'enrollments' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
            >
                Enrollments ({{ $this->stats['total_enrollments'] }})
            </button>
            <button
                wire:click="$set('activeTab', 'invoices')"
                class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'invoices' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
            >
                Invoices ({{ $this->stats['total_invoices'] }})
            </button>
            <button
                wire:click="$set('activeTab', 'analytics')"
                class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'analytics' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
            >
                Analytics
            </button>
        </nav>
    </div>

    <!-- Tab Content -->
    @if($activeTab === 'overview')
        <div class="space-y-6">
            <!-- Plan Details Card -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Main Details -->
                <div class="lg:col-span-2">
                    <x-card title="Payment Plan Details">
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Plan Name</label>
                                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $paymentPlan->name }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Type</label>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium {{ $this->typeColor }}">
                                    {{ ucfirst($paymentPlan->type) }}
                                </span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Amount per Payment</label>
                                <p class="mt-1 text-xl font-bold text-green-600">{{ $this->currencySymbol }}{{ number_format($paymentPlan->amount, 2) }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Currency</label>
                                <p class="mt-1 text-lg text-gray-900">{{ $paymentPlan->currency }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Installments</label>
                                <p class="mt-1 text-lg text-gray-900">{{ $paymentPlan->installments ?? 1 }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Total Amount</label>
                                <p class="mt-1 text-xl font-bold text-blue-600">{{ $this->currencySymbol }}{{ number_format($paymentPlan->amount * ($paymentPlan->installments ?? 1), 2) }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Frequency</label>
                                <p class="mt-1 text-lg text-gray-900">
                                    {{ match($paymentPlan->frequency) {
                                        'monthly' => 'Every month',
                                        'quarterly' => 'Every 3 months',
                                        'semi-annual' => 'Every 6 months',
                                        'annual' => 'Once per year',
                                        'one-time' => 'One-time payment',
                                        default => ucfirst($paymentPlan->frequency ?? 'Unknown')
                                    } }}
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Status</label>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium {{ $this->statusColor }}">
                                    {{ $paymentPlan->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                        </div>

                        @if($paymentPlan->description)
                            <div class="mt-6">
                                <label class="block text-sm font-medium text-gray-700">Description</label>
                                <p class="mt-1 text-gray-900">{{ $paymentPlan->description }}</p>
                            </div>
                        @endif

                        @if($paymentPlan->curriculum)
                            <div class="mt-6">
                                <label class="block text-sm font-medium text-gray-700">Associated Curriculum</label>
                                <div class="mt-2">
                                    <a href="{{ route('admin.curricula.show', $paymentPlan->curriculum) }}"
                                       class="inline-flex items-center px-3 py-2 text-sm font-medium text-blue-600 bg-blue-100 rounded-md hover:bg-blue-200">
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                        </svg>
                                        {{ $paymentPlan->curriculum->name }}
                                    </a>
                                </div>
                            </div>
                        @endif
                    </x-card>
                </div>

                <!-- Statistics -->
                <div>
                    <x-card title="Statistics">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Total Enrollments</span>
                                <span class="text-lg font-semibold">{{ $this->stats['total_enrollments'] }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Active Enrollments</span>
                                <span class="text-lg font-semibold text-green-600">{{ $this->stats['active_enrollments'] }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Total Invoices</span>
                                <span class="text-lg font-semibold">{{ $this->stats['total_invoices'] }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Paid Invoices</span>
                                <span class="text-lg font-semibold text-green-600">{{ $this->stats['paid_invoices'] }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Pending Invoices</span>
                                <span class="text-lg font-semibold text-yellow-600">{{ $this->stats['pending_invoices'] }}</span>
                            </div>
                            <hr class="my-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Total Revenue</span>
                                <span class="text-lg font-bold text-green-600">{{ $this->currencySymbol }}{{ number_format($this->stats['total_revenue'], 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Pending Revenue</span>
                                <span class="text-lg font-bold text-yellow-600">{{ $this->currencySymbol }}{{ number_format($this->stats['pending_revenue'], 2) }}</span>
                            </div>
                        </div>
                    </x-card>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Recent Enrollments -->
                <x-card title="Recent Enrollments">
                    @forelse($this->recentEnrollments as $enrollment)
                        <div class="flex items-center justify-between py-2">
                            <div>
                                <p class="font-medium text-gray-900">{{ $enrollment->student->user->name ?? 'Unknown Student' }}</p>
                                <p class="text-sm text-gray-500">{{ $enrollment->created_at->format('M d, Y') }}</p>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $enrollment->status === 'active' ? 'text-green-800 bg-green-100' : 'text-gray-800 bg-gray-100' }}">
                                {{ ucfirst($enrollment->status) }}
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No enrollments yet.</p>
                    @endforelse
                    @if($this->stats['total_enrollments'] > 5)
                        <div class="mt-4 text-center">
                            <x-button
                                label="View All Enrollments"
                                wire:click="$set('activeTab', 'enrollments')"
                                class="btn-sm btn-ghost"
                            />
                        </div>
                    @endif
                </x-card>

                <!-- Recent Invoices -->
                <x-card title="Recent Invoices">
                    @forelse($this->recentInvoices as $invoice)
                        <div class="flex items-center justify-between py-2">
                            <div>
                                <p class="font-medium text-gray-900">{{ $invoice->student->user->name ?? 'Unknown Student' }}</p>
                                <p class="text-sm text-gray-500">{{ $this->currencySymbol }}{{ number_format($invoice->total_amount, 2) }} • {{ $invoice->created_at->format('M d, Y') }}</p>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                {{ $invoice->status === 'paid' ? 'text-green-800 bg-green-100' : ($invoice->status === 'pending' ? 'text-yellow-800 bg-yellow-100' : 'text-red-800 bg-red-100') }}">
                                {{ ucfirst($invoice->status) }}
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No invoices yet.</p>
                    @endforelse
                    @if($this->stats['total_invoices'] > 5)
                        <div class="mt-4 text-center">
                            <x-button
                                label="View All Invoices"
                                wire:click="$set('activeTab', 'invoices')"
                                class="btn-sm btn-ghost"
                            />
                        </div>
                    @endif
                </x-card>
            </div>
        </div>

    @elseif($activeTab === 'enrollments')
        <x-card title="Program Enrollments">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Student</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Enrollment Date</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($paymentPlan->programEnrollments as $enrollment)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 w-8 h-8">
                                            <div class="flex items-center justify-center w-8 h-8 bg-gray-100 rounded-full">
                                                <span class="text-xs font-medium text-gray-600">
                                                    {{ strtoupper(substr($enrollment->student->user->name ?? 'U', 0, 2)) }}
                                                </span>
                                            </div>
                                        </div>
                                        <div class="ml-3">
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ $enrollment->student->user->name ?? 'Unknown Student' }}
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                {{ $enrollment->student->student_id ?? 'N/A' }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                    {{ $enrollment->created_at->format('M d, Y') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        {{ $enrollment->status === 'active' ? 'text-green-800 bg-green-100' : 'text-gray-800 bg-gray-100' }}">
                                        {{ ucfirst($enrollment->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm font-medium whitespace-nowrap">
                                    <x-button
                                        icon="o-eye"
                                        link="{{ route('admin.enrollments.show', $enrollment) }}"
                                        tooltip="View Enrollment"
                                        class="btn-xs btn-ghost"
                                    />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center">
                                    <div class="text-center">
                                        <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        <h3 class="mt-2 text-sm font-medium text-gray-900">No invoices</h3>
                                        <p class="mt-1 text-sm text-gray-500">No invoices have been generated for this payment plan yet.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

    @elseif($activeTab === 'analytics')
        <div class="space-y-6">
            <!-- Revenue Analytics -->
            <x-card title="Revenue Analytics">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-4">
                    <div class="text-center">
                        <div class="text-3xl font-bold text-green-600">{{ $this->currencySymbol }}{{ number_format($this->stats['total_revenue'], 2) }}</div>
                        <div class="text-sm text-gray-500">Total Revenue</div>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold text-yellow-600">{{ $this->currencySymbol }}{{ number_format($this->stats['pending_revenue'], 2) }}</div>
                        <div class="text-sm text-gray-500">Pending Revenue</div>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold text-blue-600">{{ number_format($this->stats['total_invoices']) }}</div>
                        <div class="text-sm text-gray-500">Total Invoices</div>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold text-purple-600">
                            {{ $this->stats['total_invoices'] > 0 ? round(($this->stats['paid_invoices'] / $this->stats['total_invoices']) * 100, 1) : 0 }}%
                        </div>
                        <div class="text-sm text-gray-500">Payment Success Rate</div>
                    </div>
                </div>
            </x-card>

            <!-- Enrollment Analytics -->
            <x-card title="Enrollment Analytics">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <div class="text-center">
                        <div class="text-3xl font-bold text-blue-600">{{ number_format($this->stats['total_enrollments']) }}</div>
                        <div class="text-sm text-gray-500">Total Enrollments</div>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold text-green-600">{{ number_format($this->stats['active_enrollments']) }}</div>
                        <div class="text-sm text-gray-500">Active Enrollments</div>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold text-orange-600">
                            {{ $this->stats['total_enrollments'] > 0 ? round(($this->stats['active_enrollments'] / $this->stats['total_enrollments']) * 100, 1) : 0 }}%
                        </div>
                        <div class="text-sm text-gray-500">Active Rate</div>
                    </div>
                </div>
            </x-card>

            <!-- Payment Plan Performance -->
            <x-card title="Payment Plan Performance">
                <div class="space-y-4">
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                        <div>
                            <h4 class="font-medium text-gray-900">Average Revenue per Student</h4>
                            <p class="text-sm text-gray-500">Based on paid invoices</p>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-bold text-green-600">
                                {{ $this->stats['active_enrollments'] > 0 ? $this->currencySymbol . number_format($this->stats['total_revenue'] / $this->stats['active_enrollments'], 2) : $this->currencySymbol . '0.00' }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                        <div>
                            <h4 class="font-medium text-gray-900">Expected Annual Revenue</h4>
                            <p class="text-sm text-gray-500">If all current enrollments complete payments</p>
                        </div>
                        <div class="text-right">
                            @php
                                $expectedRevenue = $this->stats['active_enrollments'] * $paymentPlan->amount * ($paymentPlan->installments ?? 1);
                            @endphp
                            <div class="text-2xl font-bold text-blue-600">
                                {{ $this->currencySymbol }}{{ number_format($expectedRevenue, 2) }}
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                        <div>
                            <h4 class="font-medium text-gray-900">Collection Efficiency</h4>
                            <p class="text-sm text-gray-500">Percentage of expected revenue collected</p>
                        </div>
                        <div class="text-right">
                            @php
                                $expectedRevenue = $this->stats['active_enrollments'] * $paymentPlan->amount * ($paymentPlan->installments ?? 1);
                                $collectionRate = $expectedRevenue > 0 ? round(($this->stats['total_revenue'] / $expectedRevenue) * 100, 1) : 0;
                            @endphp
                            <div class="text-2xl font-bold {{ $collectionRate >= 80 ? 'text-green-600' : ($collectionRate >= 50 ? 'text-yellow-600' : 'text-red-600') }}">
                                {{ $collectionRate }}%
                            </div>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Recommendations -->
            <x-card title="Recommendations">
                <div class="space-y-3">
                    @if($this->stats['pending_invoices'] > 0)
                        <div class="flex items-start p-3 bg-yellow-50 rounded-lg">
                            <svg class="w-5 h-5 mt-0.5 mr-3 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                            </svg>
                            <div>
                                <h4 class="text-sm font-medium text-yellow-800">Follow up on pending payments</h4>
                                <p class="text-sm text-yellow-700">You have {{ $this->stats['pending_invoices'] }} pending invoice(s) worth {{ $this->currencySymbol }}{{ number_format($this->stats['pending_revenue'], 2) }}.</p>
                            </div>
                        </div>
                    @endif

                    @if($this->stats['total_enrollments'] === 0)
                        <div class="flex items-start p-3 bg-blue-50 rounded-lg">
                            <svg class="w-5 h-5 mt-0.5 mr-3 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div>
                                <h4 class="text-sm font-medium text-blue-800">Promote this payment plan</h4>
                                <p class="text-sm text-blue-700">This payment plan has no enrollments yet. Consider promoting it to increase adoption.</p>
                            </div>
                        </div>
                    @endif

                    @php
                        $collectionRate = $this->stats['total_invoices'] > 0 ? round(($this->stats['paid_invoices'] / $this->stats['total_invoices']) * 100, 1) : 0;
                    @endphp
                    @if($collectionRate < 70 && $this->stats['total_invoices'] > 5)
                        <div class="flex items-start p-3 bg-red-50 rounded-lg">
                            <svg class="w-5 h-5 mt-0.5 mr-3 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div>
                                <h4 class="text-sm font-medium text-red-800">Low payment success rate</h4>
                                <p class="text-sm text-red-700">Only {{ $collectionRate }}% of invoices are paid. Consider reviewing payment terms or offering payment assistance.</p>
                            </div>
                        </div>
                    @endif

                    @if($this->stats['total_enrollments'] > 0 && $this->stats['pending_invoices'] === 0 && $collectionRate >= 90)
                        <div class="flex items-start p-3 bg-green-50 rounded-lg">
                            <svg class="w-5 h-5 mt-0.5 mr-3 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div>
                                <h4 class="text-sm font-medium text-green-800">Excellent performance</h4>
                                <p class="text-sm text-green-700">This payment plan is performing well with high enrollment and payment rates.</p>
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>
        </div>
    @endif

    <!-- Audit Trail -->
    <x-card title="Recent Activity" class="mt-6">
        <div class="space-y-3">
            <div class="flex items-center text-sm">
                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                </svg>
                <span class="text-gray-500">{{ $paymentPlan->created_at->format('M d, Y \a\t g:i A') }}</span>
                <span class="ml-2">Payment plan created</span>
            </div>

            @if($paymentPlan->updated_at != $paymentPlan->created_at)
                <div class="flex items-center text-sm">
                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                    <span class="text-gray-500">{{ $paymentPlan->updated_at->format('M d, Y \a\t g:i A') }}</span>
                    <span class="ml-2">Payment plan last updated</span>
                </div>
            @endif
        </div>
    </x-card>
</div>
