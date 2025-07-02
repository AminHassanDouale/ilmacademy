<?php

use App\Models\Invoice;
use App\Models\ProgramEnrollment;
use App\Models\PaymentPlan;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Invoice')] class extends Component {
    use Toast;

    // Model instance
    public Invoice $invoice;

    // Form data
    public ?int $programEnrollmentId = null;
    public ?int $paymentPlanId = null;
    public string $amount = '';
    public ?string $dueDate = null;
    public string $status = '';
    public ?string $description = null;
    public ?string $notes = null;
    public string $invoiceNumber = '';

    // Options - Use Invoice model constants
    protected array $validStatuses = [
        Invoice::STATUS_DRAFT,
        Invoice::STATUS_SENT,
        Invoice::STATUS_PENDING,
        Invoice::STATUS_PARTIALLY_PAID,
        Invoice::STATUS_PAID,
        Invoice::STATUS_OVERDUE,
        Invoice::STATUS_CANCELLED,
    ];

    // Original data for change tracking
    protected array $originalData = [];

    // Mount the component
    public function mount(Invoice $invoice): void
    {
        $this->invoice = $invoice;

        Log::info('Invoice Edit Component Mounted', [
            'user_id' => Auth::id(),
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'ip' => request()->ip()
        ]);

        // Load current invoice data into form
        $this->loadInvoiceData();

        // Store original data for change tracking
        $this->storeOriginalData();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed edit page for invoice #{$invoice->invoice_number}",
            Invoice::class,
            $invoice->id,
            ['ip' => request()->ip()]
        );
    }

    // Load invoice data into form
    protected function loadInvoiceData(): void
    {
        $this->programEnrollmentId = $this->invoice->program_enrollment_id;
        $this->paymentPlanId = $this->invoice->payment_plan_id;
        $this->amount = (string) $this->invoice->amount;
        $this->dueDate = $this->invoice->due_date->format('Y-m-d');
        $this->status = $this->invoice->status;
        $this->description = $this->invoice->description;
        $this->notes = $this->invoice->notes;
        $this->invoiceNumber = $this->invoice->invoice_number;

        Log::info('Invoice Data Loaded', [
            'invoice_id' => $this->invoice->id,
            'form_data' => [
                'programEnrollmentId' => $this->programEnrollmentId,
                'paymentPlanId' => $this->paymentPlanId,
                'amount' => $this->amount,
                'status' => $this->status,
            ]
        ]);
    }

    // Store original data for change tracking
    protected function storeOriginalData(): void
    {
        $this->originalData = [
            'program_enrollment_id' => $this->invoice->program_enrollment_id,
            'payment_plan_id' => $this->invoice->payment_plan_id,
            'amount' => (string) $this->invoice->amount,
            'due_date' => $this->invoice->due_date->format('Y-m-d'),
            'status' => $this->invoice->status,
            'description' => $this->invoice->description,
            'notes' => $this->invoice->notes,
        ];
    }

    // Load data based on selected program enrollment
    public function loadProgramEnrollmentData(): void
    {
        Log::info('Loading Program Enrollment Data', [
            'program_enrollment_id' => $this->programEnrollmentId
        ]);

        if ($this->programEnrollmentId) {
            $enrollment = ProgramEnrollment::with(['paymentPlan', 'curriculum'])->find($this->programEnrollmentId);

            if ($enrollment) {
                Log::info('Program Enrollment Found', [
                    'enrollment_id' => $enrollment->id,
                    'payment_plan_id' => $enrollment->payment_plan_id,
                    'has_payment_plan' => $enrollment->paymentPlan ? true : false
                ]);

                if ($enrollment->paymentPlan) {
                    $this->paymentPlanId = $enrollment->paymentPlan->id;
                    $this->amount = (string) $enrollment->paymentPlan->amount;

                    Log::info('Payment Plan Data Loaded', [
                        'payment_plan_id' => $this->paymentPlanId,
                        'amount' => $this->amount,
                        'payment_plan_type' => $enrollment->paymentPlan->type
                    ]);
                }

                // Set description based on enrollment details
                if ($enrollment->curriculum && !$this->description) {
                    $this->description = "Invoice for " . $enrollment->curriculum->name;
                }
            } else {
                Log::error('Program Enrollment Not Found', [
                    'program_enrollment_id' => $this->programEnrollmentId
                ]);
            }
        }
    }

    // Updated program enrollment ID
    public function updatedProgramEnrollmentId(): void
    {
        Log::info('Program Enrollment ID Updated', [
            'old_program_enrollment_id' => $this->originalData['program_enrollment_id'] ?? null,
            'new_program_enrollment_id' => $this->programEnrollmentId
        ]);

        // Don't reset if it's the same as original (just loading)
        if ($this->programEnrollmentId != $this->originalData['program_enrollment_id']) {
            $this->loadProgramEnrollmentData();
        }
    }

    // Updated payment plan ID
    public function updatedPaymentPlanId(): void
    {
        Log::info('Payment Plan ID Updated', [
            'payment_plan_id' => $this->paymentPlanId
        ]);

        if ($this->paymentPlanId) {
            $paymentPlan = PaymentPlan::find($this->paymentPlanId);

            if ($paymentPlan) {
                $this->amount = (string) $paymentPlan->amount;
                Log::info('Payment Plan Amount Updated', [
                    'payment_plan_id' => $this->paymentPlanId,
                    'amount' => $this->amount,
                    'payment_plan_type' => $paymentPlan->type
                ]);
            } else {
                Log::error('Payment Plan Not Found', [
                    'payment_plan_id' => $this->paymentPlanId
                ]);
            }
        }
    }

    // Save the invoice
    public function save(): void
    {
        Log::info('Invoice Update Started', [
            'user_id' => Auth::id(),
            'invoice_id' => $this->invoice->id,
            'form_data' => [
                'programEnrollmentId' => $this->programEnrollmentId,
                'paymentPlanId' => $this->paymentPlanId,
                'invoiceNumber' => $this->invoiceNumber,
                'amount' => $this->amount,
                'dueDate' => $this->dueDate,
                'status' => $this->status,
                'description' => $this->description,
                'notes' => $this->notes,
            ]
        ]);

        try {
            // Validate form data
            Log::debug('Starting Validation');

            $validated = $this->validate([
                'programEnrollmentId' => 'required|exists:program_enrollments,id',
                'paymentPlanId' => 'nullable|exists:payment_plans,id',
                'invoiceNumber' => 'required|string|unique:invoices,invoice_number,' . $this->invoice->id,
                'amount' => 'required|numeric|min:0',
                'dueDate' => 'required|date',
                'status' => 'required|string|in:' . implode(',', $this->validStatuses),
                'description' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
            ], [
                'programEnrollmentId.required' => 'Please select a program enrollment.',
                'programEnrollmentId.exists' => 'The selected program enrollment is invalid.',
                'invoiceNumber.unique' => 'This invoice number already exists.',
                'amount.required' => 'Please enter an amount.',
                'amount.numeric' => 'Amount must be a valid number.',
                'amount.min' => 'Amount must be greater than or equal to 0.',
                'dueDate.required' => 'Please select a due date.',
                'dueDate.date' => 'Due date must be a valid date.',
                'status.required' => 'Please select a status.',
                'status.in' => 'The selected status is invalid.',
            ]);

            Log::info('Validation Passed', ['validated_data' => $validated]);

            // Get enrollment to extract related IDs
            $enrollment = ProgramEnrollment::findOrFail($validated['programEnrollmentId']);

            Log::info('Enrollment Data Retrieved', [
                'enrollment_id' => $enrollment->id,
                'child_profile_id' => $enrollment->child_profile_id,
                'academic_year_id' => $enrollment->academic_year_id,
                'curriculum_id' => $enrollment->curriculum_id
            ]);

            // Prepare data for DB
            $invoiceData = [
                'program_enrollment_id' => $validated['programEnrollmentId'],
                'payment_plan_id' => $validated['paymentPlanId'],
                'invoice_number' => $validated['invoiceNumber'],
                'amount' => $validated['amount'],
                'due_date' => $validated['dueDate'],
                'status' => $validated['status'],
                'description' => $validated['description'],
                'notes' => $validated['notes'],
                // Update related IDs from enrollment
                'child_profile_id' => $enrollment->child_profile_id,
                'academic_year_id' => $enrollment->academic_year_id,
                'curriculum_id' => $enrollment->curriculum_id,
            ];

            // Add paid_date if status changed to paid
            if ($validated['status'] === Invoice::STATUS_PAID && $this->originalData['status'] !== Invoice::STATUS_PAID) {
                $invoiceData['paid_date'] = now();
                Log::info('Added Paid Date', ['paid_date' => $invoiceData['paid_date']]);
            } elseif ($validated['status'] !== Invoice::STATUS_PAID && $this->originalData['status'] === Invoice::STATUS_PAID) {
                // Remove paid_date if status changed from paid to something else
                $invoiceData['paid_date'] = null;
                Log::info('Removed Paid Date');
            }

            // Track changes for activity log
            $changes = $this->getChanges($validated);

            Log::info('Prepared Invoice Data', [
                'invoice_data' => $invoiceData,
                'changes' => $changes
            ]);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Update invoice
            Log::debug('Updating Invoice Record');
            $this->invoice->update($invoiceData);
            Log::info('Invoice Updated Successfully', [
                'invoice_id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number
            ]);

            $studentName = $enrollment->childProfile ? $enrollment->childProfile->full_name : 'Unknown';

            // Log activity with changes
            if (!empty($changes)) {
                $changeDescription = "Updated invoice #{$this->invoice->invoice_number} for student: {$studentName}. Changes: " . implode(', ', $changes);

                ActivityLog::log(
                    Auth::id(),
                    'update',
                    $changeDescription,
                    Invoice::class,
                    $this->invoice->id,
                    [
                        'changes' => $changes,
                        'original_data' => $this->originalData,
                        'new_data' => $validated,
                        'student_name' => $studentName
                    ]
                );
            }

            DB::commit();
            Log::info('Database Transaction Committed');

            // Update original data for future comparisons
            $this->storeOriginalData();

            // Show success toast
            $this->success("Invoice #{$this->invoice->invoice_number} has been successfully updated.");
            Log::info('Success Toast Displayed');

            // Redirect to invoice show page
            Log::info('Redirecting to Invoice Show Page', [
                'invoice_id' => $this->invoice->id,
                'route' => 'admin.invoices.show'
            ]);

            $this->redirect(route('admin.invoices.show', $this->invoice->id));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation Failed', [
                'errors' => $e->errors(),
                'form_data' => [
                    'programEnrollmentId' => $this->programEnrollmentId,
                    'paymentPlanId' => $this->paymentPlanId,
                    'invoiceNumber' => $this->invoiceNumber,
                    'amount' => $this->amount,
                    'dueDate' => $this->dueDate,
                    'status' => $this->status,
                ]
            ]);

            // Re-throw validation exception to show errors to user
            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Invoice Update Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'invoice_id' => $this->invoice->id,
                'form_data' => [
                    'programEnrollmentId' => $this->programEnrollmentId,
                    'paymentPlanId' => $this->paymentPlanId,
                    'invoiceNumber' => $this->invoiceNumber,
                    'amount' => $this->amount,
                    'dueDate' => $this->dueDate,
                    'status' => $this->status,
                    'description' => $this->description,
                    'notes' => $this->notes,
                ]
            ]);

            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Get changes between original and new data
    protected function getChanges(array $newData): array
    {
        $changes = [];

        // Map form fields to human-readable names
        $fieldMap = [
            'programEnrollmentId' => 'Program Enrollment',
            'paymentPlanId' => 'Payment Plan',
            'amount' => 'Amount',
            'dueDate' => 'Due Date',
            'status' => 'Status',
            'description' => 'Description',
            'notes' => 'Notes',
            'invoiceNumber' => 'Invoice Number',
        ];

        foreach ($newData as $field => $newValue) {
            $originalField = match($field) {
                'programEnrollmentId' => 'program_enrollment_id',
                'paymentPlanId' => 'payment_plan_id',
                'dueDate' => 'due_date',
                'invoiceNumber' => 'invoice_number',
                default => $field
            };

            $originalValue = $this->originalData[$originalField] ?? null;

            if ($originalValue != $newValue) {
                $fieldName = $fieldMap[$field] ?? $field;

                // Format the change description
                if ($field === 'amount') {
                    $changes[] = "{$fieldName} from \${$originalValue} to \${$newValue}";
                } elseif ($field === 'status') {
                    $changes[] = "{$fieldName} from " . ucfirst(str_replace('_', ' ', $originalValue)) . " to " . ucfirst(str_replace('_', ' ', $newValue));
                } else {
                    $changes[] = "{$fieldName} changed";
                }
            }
        }

        return $changes;
    }

    // Get program enrollments for dropdown
    public function getProgramEnrollmentOptionsProperty(): array
    {
        try {
            Log::debug('Loading Program Enrollment Options');

            $enrollments = ProgramEnrollment::with('childProfile', 'curriculum', 'academicYear')
                ->orderByDesc('id')
                ->get();

            Log::info('Program Enrollments Loaded', [
                'count' => $enrollments->count(),
                'enrollment_ids' => $enrollments->pluck('id')->toArray()
            ]);

            $options = [];
            foreach ($enrollments as $enrollment) {
                $studentName = $enrollment->childProfile ? $enrollment->childProfile->full_name : 'Unknown Student';
                $curriculumName = $enrollment->curriculum ? $enrollment->curriculum->name : 'Unknown Curriculum';
                $academicYear = $enrollment->academicYear ? $enrollment->academicYear->name : 'Unknown Year';

                $displayName = "#{$enrollment->id} - {$studentName} ({$curriculumName}) - {$academicYear}";
                $options[$enrollment->id] = $displayName;
            }

            Log::debug('Program Enrollment Options Prepared', [
                'options_count' => count($options),
                'sample_options' => array_slice($options, 0, 3, true)
            ]);

            return $options;

        } catch (\Exception $e) {
            Log::error('Failed to Load Program Enrollment Options', [
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    // Get payment plans for dropdown
    public function getPaymentPlanOptionsProperty(): array
    {
        try {
            Log::debug('Loading Payment Plan Options', [
                'program_enrollment_id' => $this->programEnrollmentId
            ]);

            if (!$this->programEnrollmentId) {
                $plans = PaymentPlan::active()->orderBy('type')->get();
                Log::debug('Loading All Active Payment Plans', ['count' => $plans->count()]);
            } else {
                // If a program enrollment is selected, prioritize the associated payment plan
                $enrollment = ProgramEnrollment::find($this->programEnrollmentId);

                if ($enrollment && $enrollment->payment_plan_id) {
                    Log::debug('Loading Payment Plans with Priority', [
                        'associated_plan_id' => $enrollment->payment_plan_id
                    ]);

                    // Get the associated payment plan first, then others
                    $associatedPlan = PaymentPlan::active()->where('id', $enrollment->payment_plan_id)->get();
                    $otherPlans = PaymentPlan::active()->where('id', '!=', $enrollment->payment_plan_id)->orderBy('type')->get();
                    $plans = $associatedPlan->merge($otherPlans);
                } else {
                    $plans = PaymentPlan::active()->orderBy('type')->get();
                    Log::debug('Loading All Plans (No Associated Plan)', ['count' => $plans->count()]);
                }
            }

            $options = ['' => 'No payment plan']; // Add empty option
            foreach ($plans as $plan) {
                // Create a nice display string
                $displayText = $plan->name ?? ucfirst($plan->type);
                $displayText .= ' - $' . number_format($plan->amount, 2);

                // Add curriculum name if it exists
                if ($plan->curriculum) {
                    $displayText .= ' (' . $plan->curriculum->name . ')';
                }

                $options[$plan->id] = $displayText;
            }

            Log::info('Payment Plan Options Prepared', [
                'options_count' => count($options),
                'plan_ids' => array_keys($options)
            ]);

            return $options;

        } catch (\Exception $e) {
            Log::error('Failed to Load Payment Plan Options', [
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            return ['' => 'No payment plan'];
        }
    }

    // Get statuses for dropdown - Use Invoice model constants
    public function getStatusOptionsProperty(): array
    {
        return [
            Invoice::STATUS_DRAFT => 'Draft',
            Invoice::STATUS_SENT => 'Sent',
            Invoice::STATUS_PENDING => 'Pending',
            Invoice::STATUS_PARTIALLY_PAID => 'Partially Paid',
            Invoice::STATUS_PAID => 'Paid',
            Invoice::STATUS_OVERDUE => 'Overdue',
            Invoice::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    // Format a date to d/m/Y format
    public function formatDate(?string $date): string
    {
        if (!$date) {
            return '';
        }

        return date('d/m/Y', strtotime($date));
    }

    // Get formatted due date for display
    public function getFormattedDueDateProperty(): string
    {
        return $this->formatDate($this->dueDate);
    }

    // Get selected enrollment details
    public function getSelectedEnrollmentProperty()
    {
        if (!$this->programEnrollmentId) {
            return null;
        }

        try {
            return ProgramEnrollment::with('childProfile', 'curriculum', 'academicYear')
                ->find($this->programEnrollmentId);
        } catch (\Exception $e) {
            Log::error('Failed to Load Selected Enrollment', [
                'program_enrollment_id' => $this->programEnrollmentId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function with(): array
    {
        return [
            // Empty array - we use computed properties instead
        ];
    }
};?>
<div>
    <!-- Page header -->
    <x-header title="Edit Invoice #{{ $invoice->invoice_number }}" separator>
        <x-slot:actions>
            <x-button
                label="View Invoice"
                icon="o-eye"
                link="{{ route('admin.invoices.show', $invoice->id) }}"
                class="btn-ghost"
            />
            <x-button
                label="Back to List"
                icon="o-arrow-left"
                link="{{ route('admin.invoices.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Form -->
        <div class="lg:col-span-2">
            <x-card title="Invoice Information">
                <form wire:submit="save" class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Invoice Number -->
                        <div>
                            <x-input
                                label="Invoice Number"
                                wire:model="invoiceNumber"
                                placeholder="e.g., INV-202505-0001"
                                required
                            />
                            @if($invoice->paid_date)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Paid Date</div>
                            <div>{{ $invoice->paid_date->format('M d, Y \a\t g:i A') }}</div>
                        </div>
                    @endif

                    <div>
                        <div class="text-sm font-medium text-gray-500">Last Updated</div>
                        <div>{{ $invoice->updated_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Student Info Card (visible when enrollment selected) -->
            @if($this->selectedEnrollment)
                <x-card title="Student Information">
                    <div class="space-y-4">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Student</div>
                            <div class="font-semibold">
                                {{ $this->selectedEnrollment->childProfile ? $this->selectedEnrollment->childProfile->full_name : 'Unknown student' }}
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Curriculum</div>
                            <div>
                                {{ $this->selectedEnrollment->curriculum ? $this->selectedEnrollment->curriculum->name : 'Unknown curriculum' }}
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Academic Year</div>
                            <div class="flex items-center">
                                {{ $this->selectedEnrollment->academicYear ? $this->selectedEnrollment->academicYear->name : 'Unknown academic year' }}
                                @if ($this->selectedEnrollment->academicYear && $this->selectedEnrollment->academicYear->is_current)
                                    <x-badge label="Current" color="success" class="ml-2 badge-xs" />
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Enrollment Status</div>
                            <div>
                                <x-badge
                                    label="{{ $this->selectedEnrollment->status }}"
                                    color="{{ match(strtolower($this->selectedEnrollment->status ?: '')) {
                                        'active' => 'success',
                                        'pending' => 'warning',
                                        'completed' => 'info',
                                        'cancelled' => 'error',
                                        default => 'ghost'
                                    } }}"
                                    class="badge-sm"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <x-button
                            label="View Enrollment"
                            icon="o-eye"
                            link="{{ route('admin.enrollments.show', $this->selectedEnrollment->id) }}"
                            color="ghost"
                            class="w-full"
                            size="sm"
                        />
                    </div>
                </x-card>
            @endif

            <!-- Invoice Preview Card -->
            <x-card title="Invoice Preview">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="mb-3 text-center">
                        <div class="text-2xl font-bold">INVOICE</div>
                        <div class="font-mono">{{ $invoiceNumber }}</div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Student</div>
                            <div class="font-semibold">
                                {{ $this->selectedEnrollment && $this->selectedEnrollment->childProfile
                                    ? $this->selectedEnrollment->childProfile->full_name
                                    : ($invoice->student ? $invoice->student->full_name : 'Student name will appear here') }}
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Due Date</div>
                            <div class="font-semibold">
                                {{ $this->formattedDueDate ?: 'Select due date' }}
                            </div>
                        </div>
                    </div>

                    @if($description)
                        <div class="mb-4">
                            <div class="text-sm font-medium text-gray-500">Description</div>
                            <div>{{ $description }}</div>
                        </div>
                    @endif

                    <div class="py-4 my-4 border-t border-b border-base-300">
                        <div class="flex items-center justify-between">
                            <div class="font-semibold">Amount Due</div>
                            <div class="font-mono text-xl font-bold">${{ number_format((float)$amount ?: 0, 2) }}</div>
                        </div>
                    </div>

                    <div class="mt-4 text-sm text-gray-500">
                        <div><strong>Status:</strong>
                            {{ $this->statusOptions[$status] ?? ucfirst(str_replace('_', ' ', $status)) }}
                        </div>
                        @if($notes)
                            <div class="mt-2"><strong>Notes:</strong> {{ $notes }}</div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Help Card -->
            <x-card title="Help & Information">
                <div class="space-y-4 text-sm">
                    <div>
                        <div class="font-semibold">Status Changes</div>
                        <p class="text-gray-600">When changing status to "Paid", the payment date will be automatically set to today. Changing from "Paid" to another status will clear the payment date.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Program Enrollment</div>
                        <p class="text-gray-600">Changing the program enrollment will update the associated student, curriculum, and academic year information.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Amount</div>
                        <p class="text-gray-600">The amount will be automatically updated when you select a different payment plan.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Changes Tracking</div>
                        <p class="text-gray-600">All changes to this invoice will be logged for audit purposes. You can view the activity log on the invoice details page.</p>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
