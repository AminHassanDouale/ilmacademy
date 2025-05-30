<?php

use App\Models\ProgramEnrollment;
use App\Models\SubjectEnrollment;
use App\Models\Invoice;
use App\Models\ActivityLog;
use App\Models\PaymentPlan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Enrollment Details')] class extends Component {
    use Toast;

    public ProgramEnrollment $programEnrollment;
    public $subjectEnrollments;
    public $invoices;
    public $paymentPlan;
    public $academicYear;
    public $curriculum;
    public $childProfile;

    // Tab management
    public string $activeTab = 'subjects';

    // Load data
    public function mount(ProgramEnrollment $programEnrollment): void
    {
        // Check if the enrollment belongs to one of the user's children
        $user = Auth::user();
        $childProfileIds = $user->childProfiles()->pluck('id')->toArray();

        if (!in_array($programEnrollment->child_profile_id, $childProfileIds)) {
            $this->error("You don't have permission to view this enrollment.");
            return redirect()->route('student.enrollments.index');
        }

        $this->programEnrollment = $programEnrollment;
        $this->loadRelationships();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            'Student viewed enrollment details',
            ProgramEnrollment::class,
            $programEnrollment->id,
            ['ip' => request()->ip()]
        );
    }

    // Load relationships
    public function loadRelationships(): void
    {
        $this->programEnrollment->load([
            'curriculum',
            'academicYear',
            'childProfile',
            'paymentPlan',
            'subjectEnrollments.subject',
            'subjectEnrollments.teacher',
            'invoices'
        ]);

        $this->subjectEnrollments = $this->programEnrollment->subjectEnrollments;
        $this->invoices = $this->programEnrollment->invoices;
        $this->paymentPlan = $this->programEnrollment->paymentPlan;
        $this->academicYear = $this->programEnrollment->academicYear;
        $this->curriculum = $this->programEnrollment->curriculum;
        $this->childProfile = $this->programEnrollment->childProfile;
    }

    // Change active tab
    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    // Get enrollment progress
    public function getEnrollmentProgress()
    {
        if ($this->subjectEnrollments->isEmpty()) {
            return 0;
        }

        $completedSubjects = $this->subjectEnrollments->where('status', 'completed')->count();
        $totalSubjects = $this->subjectEnrollments->count();

        return round(($completedSubjects / $totalSubjects) * 100);
    }

    // Get enrollment status color
    public function getStatusColor()
    {
        return match($this->programEnrollment->status) {
            'active' => 'success',
            'pending' => 'warning',
            'completed' => 'info',
            'withdrawn' => 'error',
            default => 'neutral'
        };
    }

    // Get total paid amount
    public function getTotalPaidAmount()
    {
        return $this->invoices->where('status', 'paid')->sum('amount');
    }

    // Get total due amount
    public function getTotalDueAmount()
    {
        return $this->invoices->where('status', 'unpaid')->sum('amount');
    }

    // View certificate if available
    public function viewCertificate()
    {
        if ($this->programEnrollment->status !== 'completed') {
            $this->error('Certificate is only available when the program is completed.');
            return;
        }

        return redirect()->route('student.enrollments.certificate', $this->programEnrollment->id);
    }

    // View subject details
    public function viewSubject($subjectEnrollmentId)
    {
        return redirect()->route('student.subjects.show', $subjectEnrollmentId);
    }

    // View invoice details
    public function viewInvoice($invoiceId)
    {
        return redirect()->route('student.invoices.show', $invoiceId);
    }

    // Go back to enrollments list
    public function backToList()
    {
        return redirect()->route('student.enrollments.index');
    }

    // Request withdrawal from program
    public function requestWithdrawal()
    {
        if ($this->programEnrollment->status === 'withdrawn') {
            $this->error('This enrollment is already withdrawn.');
            return;
        }

        if ($this->programEnrollment->status === 'completed') {
            $this->error('Cannot withdraw from a completed program.');
            return;
        }

        // Here you would typically show a confirmation modal
        // For now we'll just set a flag to show our custom confirmation
        $this->dispatch('confirm-withdrawal');
    }

    // Confirm withdrawal
    public function confirmWithdrawal()
    {
        try {
            $oldStatus = $this->programEnrollment->status;
            $this->programEnrollment->status = 'withdrawal_requested';
            $this->programEnrollment->save();

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'withdrawal_request',
                'Student requested withdrawal from program',
                ProgramEnrollment::class,
                $this->programEnrollment->id,
                [
                    'ip' => request()->ip(),
                    'old_status' => $oldStatus,
                    'new_status' => 'withdrawal_requested'
                ]
            );

            $this->success('Withdrawal request submitted successfully. An administrator will review your request.');
            $this->loadRelationships();
        } catch (\Exception $e) {
            $this->error('Failed to submit withdrawal request: ' . $e->getMessage());
        }
    }

    public function with(): array
    {
        return [
            'enrollmentProgress' => $this->getEnrollmentProgress(),
            'statusColor' => $this->getStatusColor(),
            'totalPaidAmount' => $this->getTotalPaidAmount(),
            'totalDueAmount' => $this->getTotalDueAmount(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Enrollment Details" separator back-button back-url="{{ route('student.enrollments.index') }}">
        <x-slot:subtitle>
            {{ $childProfile->first_name }} {{ $childProfile->last_name }} - {{ $curriculum->name }}
        </x-slot:subtitle>

        <x-slot:actions>
            @if($programEnrollment->status === 'completed')
                <x-button
                    label="Certificate"
                    icon="o-document-check"
                    color="secondary"
                    wire:click="viewCertificate"
                    responsive />
            @endif

            @if(in_array($programEnrollment->status, ['active', 'pending']))
                <x-button
                    label="Request Withdrawal"
                    icon="o-exclamation-circle"
                    color="error"
                    wire:click="requestWithdrawal"
                    responsive />
            @endif
        </x-slot:actions>
    </x-header>

    <!-- Program Enrollment Details -->
    <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-3">
        <!-- Left column - Enrollment metadata -->
        <div class="col-span-1">
            <x-card title="Enrollment Information">
                <div class="space-y-4">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Status</div>
                        <div class="mt-1">
                            <x-badge
                                :label="ucfirst($programEnrollment->status)"
                                :color="$statusColor"
                                size="lg" />
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Academic Year</div>
                        <div class="mt-1">{{ $academicYear->name }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Curriculum</div>
                        <div class="mt-1">{{ $curriculum->name }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Enrollment Date</div>
                        <div class="mt-1">{{ $programEnrollment->created_at->format('M d, Y') }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Payment Plan</div>
                        <div class="mt-1">{{ $paymentPlan->name ?? 'N/A' }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Progress</div>
                        <div class="mt-1">
                            <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                                <div class="bg-success h-2.5 rounded-full" style="width: {{ $enrollmentProgress }}%"></div>
                            </div>
                            <div class="mt-1 text-sm">{{ $enrollmentProgress }}% Complete</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Financial Summary -->
            <x-card title="Financial Summary" class="mt-4">
                <div class="space-y-4">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Total Amount</div>
                        <div class="mt-1 text-xl font-semibold">
                            ${{ number_format($totalPaidAmount + $totalDueAmount, 2) }}
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-success">Paid</div>
                        <div class="mt-1 font-semibold text-success">
                            ${{ number_format($totalPaidAmount, 2) }}
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-error">Outstanding</div>
                        <div class="mt-1 font-semibold text-error">
                            ${{ number_format($totalDueAmount, 2) }}
                        </div>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- Right column - Details tabs -->
        <div class="col-span-1 md:col-span-2">
            <x-card>
                <div class="mb-4 border-b border-gray-200">
                    <ul class="flex flex-wrap -mb-px text-sm font-medium text-center" role="tablist">
                        <li class="mr-2" role="presentation">
                            <button
                                class="inline-block p-4 border-b-2 rounded-t-lg {{ $activeTab == 'subjects' ? 'border-primary text-primary' : 'border-transparent hover:text-gray-600 hover:border-gray-300' }}"
                                wire:click="setActiveTab('subjects')"
                                type="button"
                                role="tab">
                                Subject Enrollments
                            </button>
                        </li>
                        <li class="mr-2" role="presentation">
                            <button
                                class="inline-block p-4 border-b-2 rounded-t-lg {{ $activeTab == 'invoices' ? 'border-primary text-primary' : 'border-transparent hover:text-gray-600 hover:border-gray-300' }}"
                                wire:click="setActiveTab('invoices')"
                                type="button"
                                role="tab">
                                Invoices
                            </button>
                        </li>
                        <li role="presentation">
                            <button
                                class="inline-block p-4 border-b-2 rounded-t-lg {{ $activeTab == 'notes' ? 'border-primary text-primary' : 'border-transparent hover:text-gray-600 hover:border-gray-300' }}"
                                wire:click="setActiveTab('notes')"
                                type="button"
                                role="tab">
                                Notes
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Tab content -->
                <div class="tab-content">
                    <!-- Subjects tab -->
                    <div class="{{ $activeTab == 'subjects' ? 'block' : 'hidden' }}">
                        <div class="overflow-x-auto">
                            <table class="table w-full table-zebra">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Code</th>
                                        <th>Teacher</th>
                                        <th>Status</th>
                                        <th>Grade</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($subjectEnrollments as $enrollment)
                                        <tr class="hover">
                                            <td>{{ $enrollment->subject->name }}</td>
                                            <td>{{ $enrollment->subject->code }}</td>
                                            <td>
                                                @if ($enrollment->teacher)
                                                    {{ $enrollment->teacher->user->name }}
                                                @else
                                                    <span class="text-gray-400">Not Assigned</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($enrollment->status === 'active')
                                                    <x-badge label="Active" color="success" />
                                                @elseif ($enrollment->status === 'pending')
                                                    <x-badge label="Pending" color="warning" />
                                                @elseif ($enrollment->status === 'completed')
                                                    <x-badge label="Completed" color="info" />
                                                @elseif ($enrollment->status === 'withdrawn')
                                                    <x-badge label="Withdrawn" color="error" />
                                                @else
                                                    <x-badge label="{{ ucfirst($enrollment->status) }}" color="neutral" />
                                                @endif
                                            </td>
                                            <td>
                                                @if ($enrollment->grade)
                                                    <span class="font-semibold">{{ $enrollment->grade }}</span>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>
                                            <td class="text-right">
                                                <x-button
                                                    icon="o-eye"
                                                    color="secondary"
                                                    size="sm"
                                                    tooltip="View Subject Details"
                                                    wire:click="viewSubject({{ $enrollment->id }})"
                                                />
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="py-6 text-center">
                                                <div class="flex flex-col items-center justify-center gap-2">
                                                    <x-icon name="o-book-open" class="w-12 h-12 text-gray-400" />
                                                    <p class="text-gray-500">No subject enrollments found</p>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Invoices tab -->
                    <div class="{{ $activeTab == 'invoices' ? 'block' : 'hidden' }}">
                        <div class="overflow-x-auto">
                            <table class="table w-full table-zebra">
                                <thead>
                                    <tr>
                                        <th>Invoice Number</th>
                                        <th>Date</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($invoices as $invoice)
                                        <tr class="hover">
                                            <td>{{ $invoice->invoice_number }}</td>
                                            <td>{{ $invoice->created_at->format('M d, Y') }}</td>
                                            <td>{{ $invoice->due_date->format('M d, Y') }}</td>
                                            <td>${{ number_format($invoice->amount, 2) }}</td>
                                            <td>
                                                @if ($invoice->status === 'paid')
                                                    <x-badge label="Paid" color="success" />
                                                @elseif ($invoice->status === 'unpaid')
                                                    <x-badge label="Unpaid" color="warning" />
                                                @elseif ($invoice->status === 'overdue')
                                                    <x-badge label="Overdue" color="error" />
                                                @elseif ($invoice->status === 'cancelled')
                                                    <x-badge label="Cancelled" color="neutral" />
                                                @else
                                                    <x-badge label="{{ ucfirst($invoice->status) }}" color="neutral" />
                                                @endif
                                            </td>
                                            <td class="text-right">
                                                <div class="flex justify-end gap-2">
                                                    <x-button
                                                        icon="o-eye"
                                                        color="secondary"
                                                        size="sm"
                                                        tooltip="View Invoice Details"
                                                        wire:click="viewInvoice({{ $invoice->id }})"
                                                    />

                                                    @if ($invoice->status === 'unpaid' || $invoice->status === 'overdue')
                                                        <x-button
                                                            icon="o-credit-card"
                                                            color="primary"
                                                            size="sm"
                                                            tooltip="Pay Invoice"
                                                            href="{{ route('student.payments.create', ['invoice' => $invoice->id]) }}"
                                                        />
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="py-6 text-center">
                                                <div class="flex flex-col items-center justify-center gap-2">
                                                    <x-icon name="o-document-text" class="w-12 h-12 text-gray-400" />
                                                    <p class="text-gray-500">No invoices found</p>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Notes tab -->
                    <div class="{{ $activeTab == 'notes' ? 'block' : 'hidden' }}">
                        <div class="space-y-4">
                            @if ($programEnrollment->notes)
                                <div class="p-4 rounded-lg bg-base-200">
                                    <p class="whitespace-pre-line">{{ $programEnrollment->notes }}</p>
                                </div>
                            @else
                                <div class="flex flex-col items-center justify-center gap-2 py-6">
                                    <x-icon name="o-document-text" class="w-12 h-12 text-gray-400" />
                                    <p class="text-gray-500">No notes available for this enrollment</p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>

    <!-- Withdrawal confirmation modal -->
    <x-modal wire:model="confirmingWithdrawal" title="Confirm Withdrawal">
        <div class="space-y-4">
            <p class="text-gray-600">
                Are you sure you want to request withdrawal from this program? This action cannot be undone.
            </p>

            <div class="p-4 rounded-lg bg-warning-100 text-warning-800">
                <div class="flex items-start">
                    <x-icon name="o-exclamation-triangle" class="flex-shrink-0 w-5 h-5 mr-2" />
                    <div>
                        <p class="font-medium">Important information:</p>
                        <ul class="mt-1 text-sm list-disc list-inside">
                            <li>Your request will be reviewed by an administrator</li>
                            <li>Refunds are subject to the terms specified in your enrollment agreement</li>
                            <li>You may continue to access course materials until your request is processed</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('confirmingWithdrawal', false)" />
            <x-button label="Confirm Withdrawal" color="error" wire:click="confirmWithdrawal" />
        </x-slot:actions>
    </x-modal>

    <!-- Custom script for withdrawal confirmation -->
    <script>
        document.addEventListener('livewire:initialized', () => {
            @this.on('confirm-withdrawal', () => {
                @this.set('confirmingWithdrawal', true);
            });
        });
    </script>
</div>
