<?php
// resources/views/livewire/admin/payments/index.blade.php

use App\Models\Payment;
use App\Models\Student;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Spatie\Activitylog\Models\Activity;

new #[Title('Payment Management')] class extends Component {
    use Toast, WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $typeFilter = '';
    public string $studentFilter = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';

    public function mount(): void
    {
        // Set default date range to current month
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo = now()->endOfMonth()->format('Y-m-d');
    }

    public function getPaymentsProperty()
    {
        $query = Payment::with(['student.user', 'student.curriculum'])
            ->when($this->search, function($q) {
                $q->where('reference_number', 'like', '%' . $this->search . '%')
                  ->orWhere('description', 'like', '%' . $this->search . '%')
                  ->orWhereHas('student.user', function($userQuery) {
                      $userQuery->where('name', 'like', '%' . $this->search . '%')
                                ->orWhere('email', 'like', '%' . $this->search . '%');
                  });
            })
            ->when($this->statusFilter, fn($q) => $q->where('status', $this->statusFilter))
            ->when($this->typeFilter, fn($q) => $q->where('type', $this->typeFilter))
            ->when($this->studentFilter, fn($q) => $q->where('student_id', $this->studentFilter))
            ->when($this->dateFrom, fn($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn($q) => $q->whereDate('created_at', '<=', $this->dateTo));

        // Apply sorting
        if ($this->sortBy === 'student_name') {
            $query->join('students', 'payments.student_id', '=', 'students.id')
                  ->join('users', 'students.user_id', '=', 'users.id')
                  ->orderBy('users.name', $this->sortDirection)
                  ->select('payments.*');
        } else {
            $query->orderBy($this->sortBy, $this->sortDirection);
        }

        return $query->paginate(15);
    }

    public function getStudentsProperty(): Collection
    {
        return Student::with('user')
            ->get()
            ->map(fn($student) => [
                'id' => $student->id,
                'name' => $student->user->name ?? 'Unknown',
                'student_id' => $student->student_id
            ])
            ->sortBy('name');
    }

    public function getPaymentStatsProperty(): array
    {
        $baseQuery = Payment::query();

        if ($this->dateFrom) {
            $baseQuery->whereDate('created_at', '>=', $this->dateFrom);
        }
        if ($this->dateTo) {
            $baseQuery->whereDate('created_at', '<=', $this->dateTo);
        }

        return [
            'total_payments' => $baseQuery->count(),
            'total_amount' => $baseQuery->sum('amount'),
            'pending_payments' => $baseQuery->where('status', 'pending')->count(),
            'completed_payments' => $baseQuery->where('status', 'completed')->count(),
            'failed_payments' => $baseQuery->where('status', 'failed')->count(),
            'refunded_payments' => $baseQuery->where('status', 'refunded')->count(),
        ];
    }

    public function sortBy($field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    public function deletePayment($paymentId): void
    {
        $payment = Payment::find($paymentId);

        if ($payment) {
            // Log activity before deletion
            activity()
                ->performedOn($payment)
                ->causedBy(auth()->user())
                ->withProperties([
                    'payment_data' => $payment->toArray(),
                    'student_name' => $payment->student->user->name ?? 'Unknown'
                ])
                ->log('Payment deleted');

            $payment->delete();
            $this->success('Payment deleted successfully.');
        } else {
            $this->error('Payment not found.');
        }
    }

    public function updatePaymentStatus($paymentId, $status): void
    {
        $payment = Payment::find($paymentId);

        if ($payment) {
            $oldStatus = $payment->status;
            $payment->update(['status' => $status]);

            // Log activity
            activity()
                ->performedOn($payment)
                ->causedBy(auth()->user())
                ->withProperties([
                    'old_status' => $oldStatus,
                    'new_status' => $status,
                    'student_name' => $payment->student->user->name ?? 'Unknown'
                ])
                ->log('Payment status updated');

            $this->success("Payment status updated to {$status}.");
        } else {
            $this->error('Payment not found.');
        }
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->typeFilter = '';
        $this->studentFilter = '';
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo = now()->endOfMonth()->format('Y-m-d');
        $this->resetPage();
    }

    public function getStatusColor(string $status): string
    {
        return match($status) {
            'pending' => 'text-yellow-800 bg-yellow-100',
            'completed' => 'text-green-800 bg-green-100',
            'failed' => 'text-red-800 bg-red-100',
            'refunded' => 'text-blue-800 bg-blue-100',
            'cancelled' => 'text-gray-800 bg-gray-100',
            default => 'text-gray-800 bg-gray-100'
        };
    }

    public function getTypeColor(string $type): string
    {
        return match($type) {
            'tuition' => 'text-blue-800 bg-blue-100',
            'registration' => 'text-green-800 bg-green-100',
            'exam_fee' => 'text-purple-800 bg-purple-100',
            'library_fee' => 'text-orange-800 bg-orange-100',
            'activity_fee' => 'text-pink-800 bg-pink-100',
            'other' => 'text-gray-800 bg-gray-100',
            default => 'text-gray-800 bg-gray-100'
        };
    }

    // Pagination and filter updates
    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedTypeFilter(): void { $this->resetPage(); }
    public function updatedStudentFilter(): void { $this->resetPage(); }
    public function updatedDateFrom(): void { $this->resetPage(); }
    public function updatedDateTo(): void { $this->resetPage(); }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Payment Management" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-4">
                <!-- Search -->
                <div class="flex-1 max-w-md">
                    <x-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search payments, students..."
                        icon="o-magnifying-glass"
                        clearable
                    />
                </div>

                <!-- Status Filter -->
                <div class="flex-1 max-w-xs">
                    <select wire:model.live="statusFilter" class="w-full select select-bordered select-sm">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="completed">Completed</option>
                        <option value="failed">Failed</option>
                        <option value="refunded">Refunded</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <!-- Type Filter -->
                <div class="flex-1 max-w-xs">
                    <select wire:model.live="typeFilter" class="w-full select select-bordered select-sm">
                        <option value="">All Types</option>
                        <option value="tuition">Tuition</option>
                        <option value="registration">Registration</option>
                        <option value="exam_fee">Exam Fee</option>
                        <option value="library_fee">Library Fee</option>
                        <option value="activity_fee">Activity Fee</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Reset Filters"
                icon="o-x-mark"
                wire:click="resetFilters"
                class="btn-ghost btn-sm"
            />
            <x-button
                label="Add Payment"
                icon="o-plus"
                link="{{ route('admin.payments.create') }}"
                class="btn-primary"
            />
        </x-slot:actions>
    </x-header>

    <!-- Date Range and Student Filter -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center space-x-4">
            <!-- Date Range -->
            <div class="flex items-center space-x-2">
                <x-input
                    wire:model.live="dateFrom"
                    type="date"
                    class="input-sm"
                />
                <span class="text-gray-500">to</span>
                <x-input
                    wire:model.live="dateTo"
                    type="date"
                    class="input-sm"
                />
            </div>

            <!-- Student Filter -->
            <div class="max-w-xs">
                <select wire:model.live="studentFilter" class="w-full select select-bordered select-sm">
                    <option value="">All Students</option>
                    @foreach($this->students as $student)
                        <option value="{{ $student['id'] }}">{{ $student['name'] }} ({{ $student['student_id'] }})</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <!-- Payment Statistics -->
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-6">
        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-blue-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Total</p>
                    <p class="text-lg font-semibold text-gray-900">${{ number_format($this->paymentStats['total_amount'], 2) }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-green-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Completed</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->paymentStats['completed_payments'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-yellow-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Pending</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->paymentStats['pending_payments'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-red-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Failed</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->paymentStats['failed_payments'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-blue-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Refunded</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->paymentStats['refunded_payments'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-purple-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Count</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->paymentStats['total_payments'] }}</p>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Payments Table -->
    <div class="overflow-hidden bg-white rounded-lg shadow">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase cursor-pointer hover:bg-gray-100"
                            wire:click="sortBy('reference_number')">
                            Reference #
                            @if($sortBy === 'reference_number')
                                <span class="ml-1">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase cursor-pointer hover:bg-gray-100"
                            wire:click="sortBy('student_name')">
                            Student
                            @if($sortBy === 'student_name')
                                <span class="ml-1">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Type
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase cursor-pointer hover:bg-gray-100"
                            wire:click="sortBy('amount')">
                            Amount
                            @if($sortBy === 'amount')
                                <span class="ml-1">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Status
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase cursor-pointer hover:bg-gray-100"
                            wire:click="sortBy('created_at')">
                            Date
                            @if($sortBy === 'created_at')
                                <span class="ml-1">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($this->payments as $payment)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ $payment->reference_number }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 w-8 h-8">
                                        <div class="flex items-center justify-center w-8 h-8 bg-gray-100 rounded-full">
                                            <span class="text-xs font-medium text-gray-600">
                                                {{ strtoupper(substr($payment->student->user->name ?? 'U', 0, 2)) }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="ml-3">
                                        <div class="text-sm font-medium text-gray-900">
                                            {{ $payment->student->user->name ?? 'Unknown' }}
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            {{ $payment->student->student_id ?? 'N/A' }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getTypeColor($payment->type) }}">
                                    {{ ucfirst(str_replace('_', ' ', $payment->type)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900 whitespace-nowrap">
                                ${{ number_format($payment->amount, 2) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($payment->status) }}">
                                    {{ ucfirst($payment->status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                {{ $payment->created_at->format('M d, Y') }}
                            </td>
                            <td class="px-6 py-4 text-sm font-medium whitespace-nowrap">
                                <div class="flex items-center space-x-2">
                                    <x-button
                                        icon="o-eye"
                                        link="{{ route('admin.payments.show', $payment) }}"
                                        tooltip="View Details"
                                        class="btn-xs btn-ghost"
                                    />
                                    <x-button
                                        icon="o-pencil"
                                        link="{{ route('admin.payments.edit', $payment) }}"
                                        tooltip="Edit"
                                        class="btn-xs btn-ghost"
                                    />

                                    <!-- Status Quick Actions -->
                                    @if($payment->status === 'pending')
                                        <x-button
                                            icon="o-check"
                                            wire:click="updatePaymentStatus({{ $payment->id }}, 'completed')"
                                            tooltip="Mark as Completed"
                                            class="text-green-600 btn-xs btn-ghost"
                                        />
                                        <x-button
                                            icon="o-x-mark"
                                            wire:click="updatePaymentStatus({{ $payment->id }}, 'failed')"
                                            tooltip="Mark as Failed"
                                            class="text-red-600 btn-xs btn-ghost"
                                        />
                                    @endif

                                    <x-button
                                        icon="o-trash"
                                        wire:click="deletePayment({{ $payment->id }})"
                                        wire:confirm="Are you sure you want to delete this payment?"
                                        tooltip="Delete"
                                        class="text-red-600 btn-xs btn-ghost"
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <div class="text-center">
                                    <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                                    </svg>
                                    <h3 class="mt-2 text-sm font-medium text-gray-900">No payments found</h3>
                                    <p class="mt-1 text-sm text-gray-500">
                                        @if($search || $statusFilter || $typeFilter || $studentFilter)
                                            Try adjusting your search or filter criteria.
                                        @else
                                            Get started by creating a new payment.
                                        @endif
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($this->payments->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $this->payments->links() }}
            </div>
        @endif
    </div>
</div>
