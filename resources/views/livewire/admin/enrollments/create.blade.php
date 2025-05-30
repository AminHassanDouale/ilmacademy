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

new #[Title('Create Program Enrollment')] class extends Component {
    use Toast;

    // Form fields
    public ?int $child_profile_id = null;
    public ?int $curriculum_id = null;
    public ?int $academic_year_id = null;
    public ?int $payment_plan_id = null;
    public string $status = 'Pending';

    // Form options
    public array $students = [];
    public array $curricula = [];
    public array $academicYears = [];
    public array $paymentPlans = [];
    public array $statusOptions = [];

    // Loading states
    public bool $isLoading = false;

    // Step management for wizard
    public int $currentStep = 1;
    public int $totalSteps = 3;

    public function mount(): void
    {
        // Load form options
        $this->loadFormOptions();

        // Auto-select current academic year if available
        $currentYear = collect($this->academicYears)->firstWhere('label', 'like', '%(Current)%');
        if ($currentYear) {
            $this->academic_year_id = $currentYear['value'];
        }

        // Log activity
        if (class_exists(ActivityLog::class)) {
            try {
                ActivityLog::log(
                    Auth::id(),
                    'access',
                    "Accessed program enrollment creation form",
                    ProgramEnrollment::class,
                    null,
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
            $this->students = ChildProfile::select('id', 'first_name', 'last_name', 'date_of_birth')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->map(fn($student) => [
                    'value' => $student->id,
                    'label' => $student->full_name . ' (Age: ' . ($student->age ?? 'Unknown') . ')'
                ])
                ->toArray();
        } catch (\Exception $e) {
            $this->students = [];
        }

        // Load curricula
        try {
            $this->curricula = Curriculum::select('id', 'name', 'code', 'description')
                ->orderBy('name')
                ->get()
                ->map(fn($curriculum) => [
                    'value' => $curriculum->id,
                    'label' => $curriculum->name . ($curriculum->code ? " ({$curriculum->code})" : ''),
                    'description' => $curriculum->description
                ])
                ->toArray();
        } catch (\Exception $e) {
            $this->curricula = [];
        }

        // Load academic years
        try {
            $this->academicYears = AcademicYear::select('id', 'name', 'start_date', 'end_date', 'is_current')
                ->orderByDesc('is_current')
                ->orderByDesc('start_date')
                ->get()
                ->map(fn($year) => [
                    'value' => $year->id,
                    'label' => $year->name . ($year->is_current ? ' (Current)' : ''),
                    'start_date' => $year->start_date,
                    'end_date' => $year->end_date
                ])
                ->toArray();
        } catch (\Exception $e) {
            $this->academicYears = [];
        }

        // Load payment plans
        $this->loadPaymentPlans();

        // Status options
        $this->statusOptions = [
            ['value' => 'Pending', 'label' => 'Pending'],
            ['value' => 'Active', 'label' => 'Active'],
            ['value' => 'Completed', 'label' => 'Completed'],
            ['value' => 'Cancelled', 'label' => 'Cancelled'],
        ];
    }

    protected function loadPaymentPlans(): void
    {
        try {
            $query = PaymentPlan::select('id', 'type', 'name', 'amount', 'curriculum_id', 'description')
                ->where('is_active', true);

            // Filter by curriculum if selected
            if ($this->curriculum_id) {
                $query->where(function($q) {
                    $q->where('curriculum_id', $this->curriculum_id)
                      ->orWhereNull('curriculum_id');
                });
            }

            $this->paymentPlans = $query->orderBy('type')
                ->orderBy('amount')
                ->get()
                ->map(fn($plan) => [
                    'value' => $plan->id,
                    'label' => ($plan->name ?? ucfirst($plan->type)) . ' - $' . number_format($plan->amount, 2),
                    'type' => $plan->type,
                    'amount' => $plan->amount,
                    'description' => $plan->description
                ])
                ->toArray();
        } catch (\Exception $e) {
            $this->paymentPlans = [];
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

    // Step navigation
    public function nextStep(): void
    {
        if ($this->currentStep < $this->totalSteps) {
            // Validate current step before proceeding
            if ($this->validateCurrentStep()) {
                $this->currentStep++;
            }
        }
    }

    public function previousStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    public function goToStep(int $step): void
    {
        if ($step >= 1 && $step <= $this->totalSteps) {
            $this->currentStep = $step;
        }
    }

    protected function validateCurrentStep(): bool
    {
        switch ($this->currentStep) {
            case 1:
                $this->validate([
                    'child_profile_id' => 'required|exists:child_profiles,id',
                ]);
                return true;
            case 2:
                $this->validate([
                    'curriculum_id' => 'required|exists:curricula,id',
                    'academic_year_id' => 'required|exists:academic_years,id',
                ]);
                return true;
            case 3:
                // Optional step validation
                return true;
        }
        return true;
    }

    public function save(): void
    {
        $this->isLoading = true;

        // Final validation
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
                ->first();

            if ($existingEnrollment) {
                $this->error('This student is already enrolled in this curriculum for the selected academic year.');
                $this->isLoading = false;
                return;
            }

            // Create the enrollment
            $enrollment = ProgramEnrollment::create([
                'child_profile_id' => $this->child_profile_id,
                'curriculum_id' => $this->curriculum_id,
                'academic_year_id' => $this->academic_year_id,
                'payment_plan_id' => $this->payment_plan_id,
                'status' => $this->status,
            ]);

            // Load relationships for logging
            $enrollment->load(['childProfile', 'curriculum', 'academicYear', 'paymentPlan']);

            // Log the activity
            if (class_exists(ActivityLog::class)) {
                try {
                    $studentName = $enrollment->childProfile?->full_name ?? 'Unknown Student';
                    ActivityLog::log(
                        Auth::id(),
                        'create',
                        "Created program enrollment for student: {$studentName}",
                        ProgramEnrollment::class,
                        $enrollment->id,
                        [
                            'student_name' => $studentName,
                            'curriculum_name' => $enrollment->curriculum?->name,
                            'academic_year' => $enrollment->academicYear?->name,
                            'status' => $enrollment->status
                        ]
                    );
                } catch (\Exception $e) {
                    // Silently fail if logging doesn't work
                }
            }

            $this->success('Program enrollment created successfully!');

            // Redirect to the show page
            $this->redirectRoute('admin.enrollments.show', ['programEnrollment' => $enrollment]);

        } catch (\Exception $e) {
            $this->error('An error occurred while creating the enrollment: ' . $e->getMessage());
        }

        $this->isLoading = false;
    }

    public function cancel(): void
    {
        $this->redirectRoute('admin.program-enrollments.index');
    }

    // Helper methods for the view
    public function getSelectedStudent()
    {
        if (!$this->child_profile_id) return null;

        try {
            return ChildProfile::find($this->child_profile_id);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getSelectedCurriculum()
    {
        if (!$this->curriculum_id) return null;

        try {
            return Curriculum::find($this->curriculum_id);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getSelectedAcademicYear()
    {
        if (!$this->academic_year_id) return null;

        try {
            return AcademicYear::find($this->academic_year_id);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getSelectedPaymentPlan()
    {
        if (!$this->payment_plan_id) return null;

        try {
            return PaymentPlan::find($this->payment_plan_id);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function with(): array
    {
        return [
            'selectedStudent' => $this->getSelectedStudent(),
            'selectedCurriculum' => $this->getSelectedCurriculum(),
            'selectedAcademicYear' => $this->getSelectedAcademicYear(),
            'selectedPaymentPlan' => $this->getSelectedPaymentPlan(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header
        title="Create Program Enrollment"
        subtitle="Enroll a student in a curriculum program"
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
                label="Back to Enrollments"
                icon="o-arrow-left"
                link="{{ route('admin.enrollments.index') }}"
                class="btn-secondary"
            />
        </x-slot:actions>
    </x-header>

    <!-- Progress Steps -->
    <div class="max-w-4xl mx-auto mb-8">
        <div class="flex items-center justify-between">
            @for($i = 1; $i <= $totalSteps; $i++)
                <div class="flex items-center {{ $i < $totalSteps ? 'flex-1' : '' }}">
                    <div class="flex items-center justify-center w-10 h-10 rounded-full {{ $currentStep >= $i ? 'bg-primary text-white' : 'bg-gray-200 text-gray-600' }}">
                        @if($currentStep > $i)
                            <x-icon name="o-check" class="w-5 h-5" />
                        @else
                            {{ $i }}
                        @endif
                    </div>
                    <div class="ml-3">
                        <div class="text-sm font-medium {{ $currentStep >= $i ? 'text-primary' : 'text-gray-500' }}">
                            Step {{ $i }}
                        </div>
                        <div class="text-xs text-gray-500">
                            @switch($i)
                                @case(1) Select Student @break
                                @case(2) Choose Program @break
                                @case(3) Review & Create @break
                            @endswitch
                        </div>
                    </div>
                    @if($i < $totalSteps)
                        <div class="flex-1 h-px mx-4 bg-gray-200"></div>
                    @endif
                </div>
            @endfor
        </div>
    </div>

    <!-- Main Form -->
    <div class="max-w-4xl mx-auto">
        <x-card>
            <div class="p-6">
                <form wire:submit="save">
                    @if($currentStep === 1)
                        <!-- Step 1: Select Student -->
                        <div class="space-y-6">
                            <div>
                                <h3 class="mb-4 text-lg font-semibold">Select Student</h3>
                                <p class="mb-6 text-gray-600">Choose the student you want to enroll in a program.</p>
                            </div>

                            <div>
                                <x-select
                                    label="Student"
                                    :options="$students"
                                    wire:model.live="child_profile_id"
                                    placeholder="Select a student"
                                    search
                                    required
                                    hint="Search by student name"
                                />
                                @if(empty($students))
                                    <div class="p-4 mt-2 border border-yellow-200 rounded-lg bg-yellow-50">
                                        <div class="flex items-center">
                                            <x-icon name="o-exclamation-triangle" class="w-5 h-5 mr-2 text-yellow-500" />
                                            <div>
                                                <h4 class="text-sm font-medium text-yellow-800">No Students Found</h4>
                                                <p class="text-sm text-yellow-700">Please create student profiles before enrolling them in programs.</p>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            @if($selectedStudent)
                                <div class="p-4 border border-blue-200 rounded-lg bg-blue-50">
                                    <h4 class="mb-2 font-medium text-blue-900">Selected Student</h4>
                                    <div class="grid grid-cols-2 gap-4 text-sm">
                                        <div>
                                            <span class="font-medium">Name:</span> {{ $selectedStudent->full_name }}
                                        </div>
                                        <div>
                                            <span class="font-medium">Age:</span> {{ $selectedStudent->age ?? 'Unknown' }} years old
                                        </div>
                                        <div>
                                            <span class="font-medium">Gender:</span> {{ ucfirst($selectedStudent->gender ?? 'Not specified') }}
                                        </div>
                                        <div>
                                            <span class="font-medium">Date of Birth:</span> {{ $selectedStudent->date_of_birth?->format('M d, Y') ?? 'Not provided' }}
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>

                    @elseif($currentStep === 2)
                        <!-- Step 2: Choose Program -->
                        <div class="space-y-6">
                            <div>
                                <h3 class="mb-4 text-lg font-semibold">Choose Program Details</h3>
                                <p class="mb-6 text-gray-600">Select the curriculum and academic year for this enrollment.</p>
                            </div>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <!-- Curriculum Selection -->
                                <div>
                                    <x-select
                                        label="Curriculum"
                                        :options="$curricula"
                                        wire:model.live="curriculum_id"
                                        placeholder="Select a curriculum"
                                        search
                                        required
                                        hint="Choose the curriculum program"
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
                                        hint="Choose the academic year"
                                    />
                                </div>
                            </div>

                            @if($selectedCurriculum)
                                <div class="p-4 border border-green-200 rounded-lg bg-green-50">
                                    <h4 class="mb-2 font-medium text-green-900">Selected Curriculum</h4>
                                    <div class="text-sm">
                                        <div class="mb-2">
                                            <span class="font-medium">Name:</span> {{ $selectedCurriculum->name }}
                                            @if($selectedCurriculum->code)
                                                <span class="px-2 py-1 ml-2 text-xs text-green-800 bg-green-100 rounded">{{ $selectedCurriculum->code }}</span>
                                            @endif
                                        </div>
                                        @if($selectedCurriculum->description)
                                            <div>
                                                <span class="font-medium">Description:</span> {{ $selectedCurriculum->description }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            @if($selectedAcademicYear)
                                <div class="p-4 border border-purple-200 rounded-lg bg-purple-50">
                                    <h4 class="mb-2 font-medium text-purple-900">Selected Academic Year</h4>
                                    <div class="text-sm">
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <span class="font-medium">Year:</span> {{ $selectedAcademicYear->name }}
                                                @if($selectedAcademicYear->is_current)
                                                    <span class="px-2 py-1 ml-2 text-xs text-purple-800 bg-purple-100 rounded">Current</span>
                                                @endif
                                            </div>
                                            <div>
                                                <span class="font-medium">Duration:</span>
                                                {{ $selectedAcademicYear->start_date?->format('M Y') }} - {{ $selectedAcademicYear->end_date?->format('M Y') }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>

                    @elseif($currentStep === 3)
                        <!-- Step 3: Review & Additional Options -->
                        <div class="space-y-6">
                            <div>
                                <h3 class="mb-4 text-lg font-semibold">Review & Additional Options</h3>
                                <p class="mb-6 text-gray-600">Review the enrollment details and set additional options.</p>
                            </div>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <!-- Payment Plan Selection -->
                                <div>
                                    <x-select
                                        label="Payment Plan (Optional)"
                                        :options="$paymentPlans"
                                        wire:model="payment_plan_id"
                                        placeholder="Select payment plan"
                                        search
                                        hint="Choose a payment plan if available"
                                    />
                                    @if(empty($paymentPlans))
                                        <p class="mt-1 text-sm text-gray-500">No payment plans available for the selected curriculum.</p>
                                    @endif
                                </div>

                                <!-- Status Selection -->
                                <div>
                                    <x-select
                                        label="Initial Status"
                                        :options="$statusOptions"
                                        wire:model="status"
                                        placeholder="Select status"
                                        required
                                        hint="Initial enrollment status"
                                    />
                                </div>
                            </div>

                            @if($selectedPaymentPlan)
                                <div class="p-4 border border-indigo-200 rounded-lg bg-indigo-50">
                                    <h4 class="mb-2 font-medium text-indigo-900">Selected Payment Plan</h4>
                                    <div class="text-sm">
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <span class="font-medium">Plan:</span> {{ $selectedPaymentPlan->name ?? ucfirst($selectedPaymentPlan->type) }}
                                            </div>
                                            <div>
                                                <span class="font-medium">Amount:</span> ${{ number_format($selectedPaymentPlan->amount, 2) }}
                                            </div>
                                        </div>
                                        @if($selectedPaymentPlan->description)
                                            <div class="mt-2">
                                                <span class="font-medium">Description:</span> {{ $selectedPaymentPlan->description }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <!-- Enrollment Summary -->
                            <div class="p-4 border border-gray-200 rounded-lg bg-gray-50">
                                <h4 class="mb-3 font-medium text-gray-900">Enrollment Summary</h4>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Student:</span>
                                        <span class="font-medium">{{ $selectedStudent?->full_name ?? 'Not selected' }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Curriculum:</span>
                                        <span class="font-medium">{{ $selectedCurriculum?->name ?? 'Not selected' }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Academic Year:</span>
                                        <span class="font-medium">{{ $selectedAcademicYear?->name ?? 'Not selected' }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Payment Plan:</span>
                                        <span class="font-medium">{{ $selectedPaymentPlan ? ($selectedPaymentPlan->name ?? ucfirst($selectedPaymentPlan->type)) : 'None selected' }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Status:</span>
                                        <span class="font-medium">{{ $status }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Duplicate Check Warning -->
                            @if($child_profile_id && $curriculum_id && $academic_year_id)
                                @php
                                    $existingEnrollment = \App\Models\ProgramEnrollment::where('child_profile_id', $child_profile_id)
                                        ->where('curriculum_id', $curriculum_id)
                                        ->where('academic_year_id', $academic_year_id)
                                        ->first();
                                @endphp
                                @if($existingEnrollment)
                                    <div class="p-4 border border-red-200 rounded-lg bg-red-50">
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
                        </div>
                    @endif

                    <!-- Navigation Buttons -->
                    <div class="flex items-center justify-between pt-6 mt-8 border-t">
                        <div>
                            @if($currentStep > 1)
                                <x-button
                                    label="Previous"
                                    icon="o-arrow-left"
                                    wire:click="previousStep"
                                    class="btn-ghost"
                                />
                            @endif
                        </div>

                        <div class="flex gap-4">
                            <x-button
                                label="Cancel"
                                icon="o-x-mark"
                                wire:click="cancel"
                                class="btn-ghost"
                            />

                            @if($currentStep < $totalSteps)
                                <x-button
                                    label="Next"
                                    icon="o-arrow-right"
                                    wire:click="nextStep"
                                    class="btn-primary"
                                    :disabled="!$child_profile_id && $currentStep === 1"
                                />
                            @else
                                <x-button
                                    label="Create Enrollment"
                                    icon="o-check"
                                    type="submit"
                                    class="btn-primary"
                                    :loading="$isLoading"
                                    spinner="save"
                                />
                            @endif
                        </div>
                    </div>
                </form>
            </div>
        </x-card>
    </div>
</div>
