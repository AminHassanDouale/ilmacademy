<?php

use App\Models\ProgramEnrollment;
use App\Models\ChildProfile;
use App\Models\Curriculum;
use App\Models\AcademicYear;
use App\Models\PaymentPlan;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Program Enrollment')] class extends Component {
    use Toast;

    public ProgramEnrollment $enrollment;

    // Form fields
    public ?int $child_profile_id = null;
    public ?int $curriculum_id = null;
    public ?int $academic_year_id = null;
    public ?int $payment_plan_id = null;
    public ?string $status = null;

    // Form options
    public array $students = [];
    public array $curricula = [];
    public array $academicYears = [];
    public array $paymentPlans = [];
    public array $statusOptions = [];

    // Loading states
    public bool $isLoading = false;

    // Debug information
    public array $debugInfo = [];

    public function mount(ProgramEnrollment $enrollment): void
    {
        $this->enrollment = $enrollment;

        // Load relationships
        $this->enrollment->load([
            'childProfile',
            'curriculum',
            'academicYear',
            'paymentPlan'
        ]);

        // Set form values
        $this->child_profile_id = $this->enrollment->child_profile_id;
        $this->curriculum_id = $this->enrollment->curriculum_id;
        $this->academic_year_id = $this->enrollment->academic_year_id;
        $this->payment_plan_id = $this->enrollment->payment_plan_id;
        $this->status = $this->enrollment->status ?? 'Pending';

        // Load form options
        $this->loadFormOptions();

        // Add debug information
        $this->debugInfo = [
            'enrollment_id' => $this->enrollment->id,
            'students_count' => count($this->students),
            'curricula_count' => count($this->curricula),
            'academic_years_count' => count($this->academicYears),
            'payment_plans_count' => count($this->paymentPlans),
            'current_student_id' => $this->child_profile_id,
            'current_curriculum_id' => $this->curriculum_id,
            'current_academic_year_id' => $this->academic_year_id,
            'current_payment_plan_id' => $this->payment_plan_id,
            'current_status' => $this->status,
        ];

        // Log activity
        if (class_exists(ActivityLog::class)) {
            try {
                $studentName = $this->enrollment->childProfile?->full_name ?? 'Unknown Student';
                ActivityLog::log(
                    Auth::id(),
                    'access',
                    "Accessed edit form for program enrollment of student: {$studentName}",
                    ProgramEnrollment::class,
                    $this->enrollment->id,
                    ['ip' => request()->ip()]
                );
            } catch (\Exception $e) {
                // Silently fail if logging doesn't work
            }
        }
    }

    protected function loadFormOptions(): void
    {
        // Load students
        try {
            $students = ChildProfile::select('id', 'first_name', 'last_name')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get();

            $this->students = $students->map(fn($student) => [
                'value' => $student->id,
                'label' => $student->full_name ?? ($student->first_name . ' ' . $student->last_name)
            ])->toArray();

            $this->debugInfo['students_raw_count'] = $students->count();
        } catch (\Exception $e) {
            $this->students = [];
            $this->debugInfo['students_error'] = $e->getMessage();
        }

        // Load curricula
        try {
            $curricula = Curriculum::select('id', 'name', 'code')
                ->orderBy('name')
                ->get();

            $this->curricula = $curricula->map(fn($curriculum) => [
                'value' => $curriculum->id,
                'label' => $curriculum->name . ($curriculum->code ? " ({$curriculum->code})" : '')
            ])->toArray();

            $this->debugInfo['curricula_raw_count'] = $curricula->count();
        } catch (\Exception $e) {
            $this->curricula = [];
            $this->debugInfo['curricula_error'] = $e->getMessage();
        }

        // Load academic years
        try {
            $academicYears = AcademicYear::select('id', 'name', 'start_date', 'end_date', 'is_current')
                ->orderByDesc('is_current')
                ->orderByDesc('start_date')
                ->get();

            $this->academicYears = $academicYears->map(fn($year) => [
                'value' => $year->id,
                'label' => $year->name . ($year->is_current ? ' (Current)' : '')
            ])->toArray();

            $this->debugInfo['academic_years_raw_count'] = $academicYears->count();
        } catch (\Exception $e) {
            $this->academicYears = [];
            $this->debugInfo['academic_years_error'] = $e->getMessage();
        }

        // Load payment plans
        $this->loadPaymentPlans();

        // Status options
        $this->statusOptions = [
            ['value' => 'Active', 'label' => 'Active'],
            ['value' => 'Pending', 'label' => 'Pending'],
            ['value' => 'Completed', 'label' => 'Completed'],
            ['value' => 'Cancelled', 'label' => 'Cancelled'],
        ];
    }

    protected function loadPaymentPlans(): void
    {
        try {
            $query = PaymentPlan::select('id', 'type', 'amount', 'curriculum_id');

            // Filter by curriculum if selected
            if ($this->curriculum_id) {
                $query->where('curriculum_id', $this->curriculum_id);
            }

            $paymentPlans = $query->orderBy('type')
                ->orderBy('amount')
                ->get();

            $this->paymentPlans = $paymentPlans->map(fn($plan) => [
                'value' => $plan->id,
                'label' => $plan->type . ' - $' . number_format($plan->amount, 2)
            ])->toArray();

            $this->debugInfo['payment_plans_raw_count'] = $paymentPlans->count();
            $this->debugInfo['payment_plans_curriculum_filter'] = $this->curriculum_id;
        } catch (\Exception $e) {
            $this->paymentPlans = [];
            $this->debugInfo['payment_plans_error'] = $e->getMessage();
        }
    }

    // Watch for curriculum changes to update payment plans
    public function updatedCurriculumId(): void
    {
        $this->loadPaymentPlans();

        // Reset payment plan if it doesn't belong to the new curriculum
        if ($this->payment_plan_id) {
            $planExists = collect($this->paymentPlans)
                ->where('value', $this->payment_plan_id)
                ->isNotEmpty();

            if (!$planExists) {
                $this->payment_plan_id = null;
            }
        }
    }

    public function save(): void
    {
        $this->isLoading = true;

        // Validate the form
        $this->validate([
            'child_profile_id' => 'required|exists:child_profiles,id',
            'curriculum_id' => 'required|exists:curricula,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'payment_plan_id' => 'nullable|exists:payment_plans,id',
            'status' => 'required|string|in:Active,Pending,Completed,Cancelled',
        ]);

        try {
            // Check for duplicate enrollment
            $existingEnrollment = ProgramEnrollment::where('child_profile_id', $this->child_profile_id)
                ->where('curriculum_id', $this->curriculum_id)
                ->where('academic_year_id', $this->academic_year_id)
                ->where('id', '!=', $this->enrollment->id)
                ->first();

            if ($existingEnrollment) {
                $this->error('This student is already enrolled in this curriculum for the selected academic year.');
                $this->isLoading = false;
                return;
            }

            // Store old values for logging
            $oldValues = [
                'child_profile_id' => $this->enrollment->child_profile_id,
                'curriculum_id' => $this->enrollment->curriculum_id,
                'academic_year_id' => $this->enrollment->academic_year_id,
                'payment_plan_id' => $this->enrollment->payment_plan_id,
                'status' => $this->enrollment->status,
            ];

            // Update the enrollment
            $this->enrollment->update([
                'child_profile_id' => $this->child_profile_id,
                'curriculum_id' => $this->curriculum_id,
                'academic_year_id' => $this->academic_year_id,
                'payment_plan_id' => $this->payment_plan_id,
                'status' => $this->status,
            ]);

            // Refresh the model to get updated relationships
            $this->enrollment->refresh();
            $this->enrollment->load(['childProfile', 'curriculum', 'academicYear', 'paymentPlan']);

            // Log the activity
            if (class_exists(ActivityLog::class)) {
                try {
                    $studentName = $this->enrollment->childProfile?->full_name ?? 'Unknown Student';
                    $changes = [];

                    foreach ($oldValues as $field => $oldValue) {
                        $newValue = $this->enrollment->{$field};
                        if ($oldValue != $newValue) {
                            $changes[$field] = ['old' => $oldValue, 'new' => $newValue];
                        }
                    }

                    ActivityLog::log(
                        Auth::id(),
                        'update',
                        "Updated program enrollment for student: {$studentName}",
                        ProgramEnrollment::class,
                        $this->enrollment->id,
                        [
                            'student_name' => $studentName,
                            'changes' => $changes
                        ]
                    );
                } catch (\Exception $e) {
                    // Silently fail if logging doesn't work
                }
            }

            $this->success('Program enrollment updated successfully!');

            // Redirect to the show page
            $this->redirectRoute('admin.enrollments.show', ['programEnrollment' => $this->enrollment]);

        } catch (\Exception $e) {
            $this->error('An error occurred while updating the enrollment: ' . $e->getMessage());
        }

        $this->isLoading = false;
    }

    public function cancel(): void
    {
        $this->redirectRoute('admin.enrollments.show', ['programEnrollment' => $this->enrollment]);
    }

    public function with(): array
    {
        return [
            'enrollment' => $this->enrollment,
            'debugInfo' => $this->debugInfo,
        ];
    }
};?>
<div>
    <!-- Page header -->
    <x-header
        title="Edit Program Enrollment"
        subtitle="Update enrollment details for {{ $enrollment->childProfile?->full_name ?? 'Unknown Student' }}"
        separator
    >
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-x-mark"
                wire:click="cancel"
                class="btn-ghost"
            />

            <x-button
                label="View Enrollment"
                icon="o-eye"

                class="btn-secondary"
            />
        </x-slot:actions>
    </x-header>

    <!-- Edit Form -->
    <div class="max-w-4xl mx-auto">
        <x-card>
            <div class="p-6">
                <form wire:submit="save">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Student Selection -->
                        <div class="md:col-span-2">
                            <x-select
                                label="Student"
                                :options="$students"
                                wire:model="child_profile_id"
                                placeholder="Select a student"
                                search
                                required
                                hint="Choose the student for this enrollment"
                            />
                        </div>

                        <!-- Curriculum Selection -->
                        <div>
                            <x-select
                                label="Curriculum"
                                :options="$curricula"
                                wire:model.live="curriculum_id"
                                placeholder="Select a curriculum"
                                search
                                required
                                hint="Select the curriculum for this enrollment"
                            />
                        </div>

                        <!-- Academic Year Selection -->
                        <div>
                            <x-select
                                label="Academic Year"
                                :options="$academicYears"
                                wire:model="academic_year_id"
                                placeholder="Select academic year"
                                search
                                required
                                hint="Choose the academic year for this enrollment"
                            />
                        </div>

                        <!-- Payment Plan Selection -->
                        <div>
                            <x-select
                                label="Payment Plan"
                                :options="$paymentPlans"
                                wire:model="payment_plan_id"
                                placeholder="Select payment plan (optional)"
                                search
                                hint="Payment plan will be filtered based on the selected curriculum"
                            />
                            @if(empty($paymentPlans) && $curriculum_id)
                                <p class="mt-1 text-sm text-gray-500">No payment plans available for the selected curriculum.</p>
                            @endif
                        </div>

                        <!-- Status Selection -->
                        <div>
                            <x-select
                                label="Status"
                                :options="$statusOptions"
                                wire:model="status"
                                placeholder="Select status"
                                required
                                hint="Current enrollment status"
                            />
                        </div>
                    </div>

                    <!-- Current Information Display -->
                    <div class="p-4 mt-8 rounded-lg bg-gray-50">
                        <h3 class="mb-4 text-lg font-semibold">Current Enrollment Information</h3>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <span class="block text-sm font-medium text-gray-500">Current Student</span>
                                <span class="block text-sm">{{ $enrollment->childProfile?->full_name ?? 'Unknown Student' }}</span>
                            </div>
                            <div>
                                <span class="block text-sm font-medium text-gray-500">Current Curriculum</span>
                                <span class="block text-sm">{{ $enrollment->curriculum?->name ?? 'Unknown Curriculum' }}</span>
                            </div>
                            <div>
                                <span class="block text-sm font-medium text-gray-500">Current Academic Year</span>
                                <span class="block text-sm">{{ $enrollment->academicYear?->name ?? 'Unknown Academic Year' }}</span>
                            </div>
                            <div>
                                <span class="block text-sm font-medium text-gray-500">Current Payment Plan</span>
                                <span class="block text-sm">
                                    @if($enrollment->paymentPlan)
                                        {{ $enrollment->paymentPlan->type }} - ${{ number_format($enrollment->paymentPlan->amount, 2) }}
                                    @else
                                        No payment plan assigned
                                    @endif
                                </span>
                            </div>
                            <div>
                                <span class="block text-sm font-medium text-gray-500">Current Status</span>
                                <x-badge
                                    label="{{ $enrollment->status ?? 'Unknown' }}"
                                    color="{{ match(strtolower($enrollment->status ?? '')) {
                                        'active' => 'success',
                                        'pending' => 'warning',
                                        'completed' => 'info',
                                        'cancelled' => 'error',
                                        default => 'ghost'
                                    } }}"
                                />
                            </div>
                            <div>
                                <span class="block text-sm font-medium text-gray-500">Last Updated</span>
                                <span class="block text-sm">{{ $enrollment->updated_at?->format('F d, Y g:i A') ?? 'Unknown' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Warning Messages -->
                    @if($child_profile_id && $curriculum_id && $academic_year_id)
                        @php
                            $existingEnrollment = \App\Models\ProgramEnrollment::where('child_profile_id', $child_profile_id)
                                ->where('curriculum_id', $curriculum_id)
                                ->where('academic_year_id', $academic_year_id)
                                ->where('id', '!=', $enrollment->id)
                                ->first();
                        @endphp
                        @if($existingEnrollment)
                            <div class="p-4 mt-4 border border-red-200 rounded-lg bg-red-50">
                                <div class="flex items-center">
                                    <x-icon name="o-exclamation-triangle" class="w-5 h-5 mr-2 text-red-500" />
                                    <div>
                                        <h4 class="text-sm font-medium text-red-800">Duplicate Enrollment Warning</h4>
                                        <p class="text-sm text-red-700">This student is already enrolled in this curriculum for the selected academic year.</p>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endif

                    <!-- Action Buttons -->
                    <div class="flex justify-end gap-4 pt-6 mt-8 border-t">
                        <x-button
                            label="Cancel"
                            icon="o-x-mark"
                            wire:click="cancel"
                            class="btn-ghost"
                        />

                        <x-button
                            label="Update Enrollment"
                            icon="o-check"
                            type="submit"
                            class="btn-primary"
                            :loading="$isLoading"
                            spinner="save"
                        />
                    </div>
                </form>
            </div>
        </x-card>

        <!-- Related Information -->
        <div class="grid grid-cols-1 gap-6 mt-6 md:grid-cols-2">
            <!-- Subject Enrollments Info -->
            <x-card>
                <div class="p-6">
                    <h3 class="mb-4 text-lg font-semibold">Subject Enrollments</h3>
                    <div class="flex items-center">
                        <x-icon name="o-book-open" class="w-8 h-8 mr-3 text-blue-500" />
                        <div>
                            <div class="text-2xl font-bold">{{ $enrollment->subjectEnrollments()->count() }}</div>
                            <div class="text-sm text-gray-500">Enrolled Subjects</div>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-gray-600">
                        Changing the curriculum may affect subject enrollments. Please review after saving.
                    </p>
                </div>
            </x-card>

            <!-- Invoices Info -->
            <x-card>
                <div class="p-6">
                    <h3 class="mb-4 text-lg font-semibold">Financial Information</h3>
                    <div class="flex items-center">
                        <x-icon name="o-document-text" class="w-8 h-8 mr-3 text-green-500" />
                        <div>
                            @php
                                $invoicesCount = 0;
                                try {
                                    $invoicesCount = $enrollment->invoices()->count();
                                } catch (\Exception $e) {
                                    // Handle case where invoices relationship doesn't exist
                                }
                            @endphp
                            <div class="text-2xl font-bold">{{ $invoicesCount }}</div>
                            <div class="text-sm text-gray-500">Related Invoices</div>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-gray-600">
                        Changing payment plan may affect future invoicing.
                    </p>
                </div>
            </x-card>
        </div>
    </div>
</div>
