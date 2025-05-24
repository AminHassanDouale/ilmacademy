<?php

use App\Models\ProgramEnrollment;
use App\Models\ActivityLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Program Enrollment Details')] class extends Component {
    use Toast;

    public ProgramEnrollment $enrollment;

    // For modal state
    public bool $showDeleteModal = false;
    public bool $showStatusModal = false;
    public bool $showAddSubjectModal = false;
    public bool $showRemoveSubjectModal = false;

    // For tab management
    public string $activeTab = 'subjects';

    // For status change
    public string $newStatus = '';
    public array $statuses = [
        ['value' => 'Active', 'label' => 'Active'],
        ['value' => 'Pending', 'label' => 'Pending'],
        ['value' => 'Completed', 'label' => 'Completed'],
        ['value' => 'Cancelled', 'label' => 'Cancelled'],
    ];

    // For subject management
    public array $selectedSubjects = [];
    public array $availableSubjects = [];
    public bool $hasAvailableSubjects = false;
    public ?int $subjectToRemove = null;

    public function mount(ProgramEnrollment $enrollment): void
    {
        $this->enrollment = $enrollment;

        // Load relationships, but handle potential missing models gracefully
        $relationships = [
            'childProfile',
            'curriculum',
            'academicYear',
            'paymentPlan',
            'subjectEnrollments.subject'
        ];

        // Only load invoices if the relationship exists
        if (method_exists($this->enrollment, 'invoices')) {
            try {
                $relationships[] = 'invoices';
            } catch (\Exception $e) {
                // Skip loading invoices if there's an issue
            }
        }

        $this->enrollment->load($relationships);

        // Initialize available subjects
        $this->loadAvailableSubjects();

        // Get student name safely for logging
        $studentName = $this->enrollment->childProfile?->full_name ?? 'Unknown Student';

        // Log activity - only if ActivityLog class exists
        if (class_exists(ActivityLog::class)) {
            try {
                ActivityLog::log(
                    Auth::id(),
                    'access',
                    "Viewed program enrollment details for student: {$studentName}",
                    ProgramEnrollment::class,
                    $this->enrollment->id,
                    ['ip' => request()->ip()]
                );
            } catch (\Exception $e) {
                // Silently fail if logging doesn't work
            }
        }
    }

    // Switch between tabs
    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    // Show status change modal
    public function showStatusChangeModal(): void
    {
        $this->newStatus = $this->enrollment->status;
        $this->showStatusModal = true;
    }

    // Load available subjects for this curriculum
    public function loadAvailableSubjects(): void
    {
        if (!$this->enrollment->curriculum) {
            $this->availableSubjects = [];
            $this->hasAvailableSubjects = false;
            return;
        }

        // Get all subjects for this curriculum
        $curriculumSubjects = $this->enrollment->curriculum->subjects()->get();

        // Get already enrolled subject IDs
        $enrolledSubjectIds = $this->enrollment->subjectEnrollments()
            ->pluck('subject_id')
            ->toArray();

        // Filter out already enrolled subjects
        $this->availableSubjects = $curriculumSubjects
            ->whereNotIn('id', $enrolledSubjectIds)
            ->map(fn($subject) => [
                'id' => $subject->id,
                'name' => $subject->name,
                'code' => $subject->code,
                'level' => $subject->level,
            ])
            ->values()
            ->toArray();

        $this->hasAvailableSubjects = count($this->availableSubjects) > 0;
    }

    // Show add subject form
    public function showAddSubjectForm(): void
    {
        $this->loadAvailableSubjects();
        $this->selectedSubjects = [];
        $this->showAddSubjectModal = true;
    }

    // Toggle subject selection
    public function toggleSubject(int $subjectId): void
    {
        if (in_array($subjectId, $this->selectedSubjects)) {
            $this->selectedSubjects = array_filter(
                $this->selectedSubjects,
                fn($id) => $id !== $subjectId
            );
        } else {
            $this->selectedSubjects[] = $subjectId;
        }
    }

    // Add selected subjects
    public function addSubjects(): void
    {
        if (empty($this->selectedSubjects)) {
            $this->error('Please select at least one subject.');
            return;
        }

        try {
            foreach ($this->selectedSubjects as $subjectId) {
                $this->enrollment->subjectEnrollments()->create([
                    'subject_id' => $subjectId,
                ]);
            }

            // Log the action
            $studentName = $this->enrollment->childProfile?->full_name ?? 'Unknown Student';
            if (class_exists(ActivityLog::class)) {
                try {
                    ActivityLog::log(
                        Auth::id(),
                        'create',
                        "Added " . count($this->selectedSubjects) . " subjects to enrollment for student: {$studentName}",
                        ProgramEnrollment::class,
                        $this->enrollment->id,
                        [
                            'subject_ids' => $this->selectedSubjects,
                            'student_name' => $studentName
                        ]
                    );
                } catch (\Exception $e) {
                    // Silently fail if logging doesn't work
                }
            }

            $this->success(count($this->selectedSubjects) . ' subject(s) added successfully.');
            $this->showAddSubjectModal = false;
            $this->selectedSubjects = [];

            // Refresh the enrollment relationships
            $this->enrollment->refresh();
            $this->enrollment->load(['subjectEnrollments.subject']);
            $this->loadAvailableSubjects();
            $this->loadAvailableSubjects();

        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Confirm subject removal
    public function confirmRemoveSubject(int $subjectEnrollmentId, string $subjectName): void
    {
        $this->subjectToRemove = $subjectEnrollmentId;
        $this->showRemoveSubjectModal = true;
    }

    // Remove subject
    public function removeSubject(): void
    {
        if (!$this->subjectToRemove) {
            $this->error('No subject selected for removal.');
            return;
        }

        try {
            $subjectEnrollment = $this->enrollment->subjectEnrollments()
                ->with('subject')
                ->find($this->subjectToRemove);

            if (!$subjectEnrollment) {
                $this->error('Subject enrollment not found.');
                return;
            }

            $subjectName = $subjectEnrollment->subject->name ?? 'Unknown Subject';

            $subjectEnrollment->delete();

            // Log the action
            $studentName = $this->enrollment->childProfile?->full_name ?? 'Unknown Student';
            if (class_exists(ActivityLog::class)) {
                try {
                    ActivityLog::log(
                        Auth::id(),
                        'delete',
                        "Removed subject '{$subjectName}' from enrollment for student: {$studentName}",
                        ProgramEnrollment::class,
                        $this->enrollment->id,
                        [
                            'subject_name' => $subjectName,
                            'student_name' => $studentName
                        ]
                    );
                } catch (\Exception $e) {
                    // Silently fail if logging doesn't work
                }
            }

            $this->success("Subject '{$subjectName}' removed successfully.");
            $this->showRemoveSubjectModal = false;
            $this->subjectToRemove = null;

            // Refresh the enrollment relationships
            $this->enrollment->refresh();
            $this->enrollment->load(['subjectEnrollments.subject']);

        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Confirm deletion
    public function confirmDelete(): void
    {
        $this->showDeleteModal = true;
    }

    // Cancel deletion
    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
    }

    // Delete enrollment - FIXED: Removed return statement from void function
    public function deleteEnrollment(): void
    {
        // Get data for logging before deletion
        $studentName = $this->enrollment->childProfile?->full_name ?? 'Unknown';
        $curriculumName = $this->enrollment->curriculum?->name ?? 'Unknown';
        $academicYear = $this->enrollment->academicYear?->name ?? 'Unknown';

        try {
            // Check if enrollment has related records
            $hasSubjectEnrollments = $this->enrollment->subjectEnrollments()->exists();

            // Safely check for invoices
            $hasInvoices = false;
            if (method_exists($this->enrollment, 'hasInvoices')) {
                $hasInvoices = $this->enrollment->hasInvoices();
            } else {
                try {
                    $hasInvoices = $this->enrollment->invoices()->exists();
                } catch (\Exception $e) {
                    // If invoices relationship doesn't exist, assume no invoices
                    $hasInvoices = false;
                }
            }

            if ($hasSubjectEnrollments || $hasInvoices) {
                $this->error("Cannot delete enrollment. It has associated subject enrollments or invoices.");
                $this->showDeleteModal = false;
                return;
            }

            // Delete enrollment
            $this->enrollment->delete();

            // Log the action
            if (class_exists(ActivityLog::class)) {
                try {
                    ActivityLog::log(
                        Auth::id(),
                        'delete',
                        "Deleted program enrollment for student: {$studentName}",
                        ProgramEnrollment::class,
                        $this->enrollment->id,
                        [
                            'student_name' => $studentName,
                            'curriculum_name' => $curriculumName,
                            'academic_year' => $academicYear,
                            'status' => $this->enrollment->status
                        ]
                    );
                } catch (\Exception $e) {
                    // Silently fail if logging doesn't work
                }
            }

            // Show toast notification
            $this->success("Program enrollment for {$studentName} has been successfully deleted.");

            // FIXED: Use redirectRoute() method instead of return redirect()
            $this->redirectRoute('admin.program-enrollments.index');

        } catch (\Exception $e) {
            $this->error("An error occurred during deletion: {$e->getMessage()}");
        }

        $this->showDeleteModal = false;
    }

    // Update enrollment status
    public function updateStatus(?string $newStatus = null): void
    {
        // Use parameter or property
        $status = $newStatus ?? $this->newStatus;

        // Define valid statuses
        $validStatuses = ['Active', 'Pending', 'Completed', 'Cancelled'];

        // Validate status
        if (!in_array($status, $validStatuses)) {
            $this->error("Invalid status provided!");
            return;
        }

        $oldStatus = $this->enrollment->status;

        // Update status
        $this->enrollment->status = $status;
        $this->enrollment->save();

        // Get student name safely
        $studentName = $this->enrollment->childProfile?->full_name ?? 'Unknown';

        // Log the action
        if (class_exists(ActivityLog::class)) {
            try {
                ActivityLog::log(
                    Auth::id(),
                    'update',
                    "Updated enrollment status from {$oldStatus} to {$status} for student: {$studentName}",
                    ProgramEnrollment::class,
                    $this->enrollment->id,
                    [
                        'old_status' => $oldStatus,
                        'new_status' => $status,
                        'student_name' => $studentName
                    ]
                );
            } catch (\Exception $e) {
                // Silently fail if logging doesn't work
            }
        }

        $this->success("Enrollment status updated to {$status}.");
        $this->showStatusModal = false;
    }

    public function with(): array
    {
        // Calculate statistics
        $subjectEnrollments = $this->enrollment->subjectEnrollments()->with('subject')->paginate(10);
        $invoices = $this->enrollment->invoices()->paginate(10);

        $subjectEnrollmentsCount = $this->enrollment->subjectEnrollments()->count();
        $invoicesCount = $this->enrollment->invoices()->count();

        // Calculate progress percentage
        $progressPercentage = $this->calculateProgressPercentage();

        return [
            'enrollment' => $this->enrollment,
            'childProfile' => $this->enrollment->childProfile,
            'curriculum' => $this->enrollment->curriculum,
            'academicYear' => $this->enrollment->academicYear,
            'paymentPlan' => $this->enrollment->paymentPlan,
            'subjectEnrollments' => $subjectEnrollments,
            'invoices' => $invoices,
            'subjectEnrollmentsCount' => $subjectEnrollmentsCount,
            'invoicesCount' => $invoicesCount,
            'progressPercentage' => $progressPercentage,
            'activityLogs' => $this->getActivityLogs(),
        ];
    }

    // Calculate progress percentage based on status
    protected function calculateProgressPercentage(): int
    {
        return match(strtolower($this->enrollment->status ?? '')) {
            'active' => 50, // Assume 50% for active enrollments
            'pending' => 10,
            'completed' => 100,
            'cancelled' => 0,
            default => 25
        };
    }

    // Get activity logs for this enrollment
    protected function getActivityLogs(): Collection
    {
        try {
            // Check if ActivityLog model exists
            if (!class_exists(ActivityLog::class)) {
                return collect();
            }

            // Use the correct column names from your ActivityLog model
            return ActivityLog::where('loggable_type', ProgramEnrollment::class)
                ->where('loggable_id', $this->enrollment->id)
                ->with('user')
                ->orderByDesc('created_at')
                ->take(15)
                ->get();
        } catch (\Exception $e) {
            // If there's any database error, return empty collection
            return collect();
        }
    }
};?>

<div>
    <!-- Page header -->
    <x-header
        title="Program Enrollment Details"
        subtitle="{{ $enrollment->childProfile?->full_name ?? 'Unknown Student' }}"
        separator
    >
        <x-slot:actions>
            <x-button
                label="Back to Enrollments"
                icon="o-arrow-left"
                link="{{ route('admin.enrollments.index') }}"
                class="btn-ghost"
            />



            <x-button
                label="Change Status"
                icon="o-arrow-path"
                wire:click="showStatusChangeModal"
                class="{{ match(strtolower($enrollment->status ?? '')) {
                    'active' => 'btn-success',
                    'pending' => 'btn-warning',
                    'completed' => 'btn-info',
                    'cancelled' => 'btn-error',
                    default => 'btn-neutral'
                } }}"
            />
        </x-slot:actions>
    </x-header>

    <!-- Enrollment Info Cards -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Student & Enrollment Info -->
        <x-card class="lg:col-span-1">
            <div class="p-5">
                <h2 class="mb-4 text-xl font-semibold">Enrollment Information</h2>

                <div class="space-y-4">
                    <div>
                        <span class="block text-sm font-medium text-gray-500">Student</span>
                        <div class="flex items-center mt-1">
                            <div class="mr-2 avatar placeholder">
                                <div class="w-8 rounded-full bg-neutral text-neutral-content">
                                    <span>{{ $enrollment->childProfile?->initials ?? '??' }}</span>
                                </div>
                            </div>
                            @if($enrollment->childProfile)
                                <a href="{{ route('admin.child-profiles.show', $enrollment->childProfile->id) }}" class="font-bold link link-hover">
                                    {{ $enrollment->childProfile->full_name }}
                                </a>
                            @else
                                <span class="font-bold text-gray-500">Unknown Student</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Status</span>
                        <div class="mt-1">
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
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Curriculum</span>
                        <div class="mt-1">
                            @if($enrollment->curriculum)
                                <a href="{{ route('admin.curricula.show', $enrollment->curriculum->id) }}" class="link link-hover">
                                    {{ $enrollment->curriculum->name }}
                                </a>
                            @else
                                <span class="text-gray-500">Unknown Curriculum</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Academic Year</span>
                        <div class="flex items-center mt-1">
                            @if($enrollment->academicYear)
                                <a href="{{ route('admin.academic-years.show', $enrollment->academicYear->id) }}" class="link link-hover">
                                    {{ $enrollment->academicYear->name }}
                                </a>
                                @if($enrollment->academicYear->is_current)
                                    <x-badge label="Current" color="success" class="ml-2 badge-xs" />
                                @endif
                            @else
                                <span class="text-gray-500">Unknown Academic Year</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Payment Plan</span>
                        <div class="mt-1">
                            @if($enrollment->paymentPlan)
                                <a href="{{ route('admin.payment-plans.show', $enrollment->paymentPlan->id) }}" class="link link-hover">
                                    <div class="flex items-center">
                                        <x-badge
                                            label="{{ $enrollment->paymentPlan->type ?? 'Unknown' }}"
                                            color="{{ match(strtolower($enrollment->paymentPlan->type ?? '')) {
                                                'monthly' => 'success',
                                                'quarterly' => 'info',
                                                'annual' => 'warning',
                                                'one-time' => 'error',
                                                default => 'ghost'
                                            } }}"
                                        />
                                        <span class="ml-2 font-mono">${{ number_format($enrollment->paymentPlan->amount ?? 0, 2) }}</span>
                                    </div>
                                </a>
                            @else
                                <span class="text-gray-400">No payment plan assigned</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Enrolled On</span>
                        <span class="block mt-1">{{ $enrollment->created_at?->format('F d, Y') ?? 'Unknown' }}</span>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Last Updated</span>
                        <span class="block mt-1">{{ $enrollment->updated_at?->format('F d, Y') ?? 'Unknown' }}</span>
                    </div>
                </div>
            </div>
        </x-card>

        <!-- Statistics Card -->
        <x-card class="lg:col-span-2">
            <div class="p-5">
                <h2 class="mb-4 text-xl font-semibold">Statistics</h2>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="p-4 rounded-lg bg-base-200">
                        <div class="flex items-center">
                            <div class="p-3 mr-4 rounded-full bg-primary/20">
                                <x-icon name="o-book-open" class="w-8 h-8 text-primary" />
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">Subject Enrollments</h3>
                                <div class="text-2xl font-bold">{{ $subjectEnrollmentsCount ?? 0 }}</div>
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
                                <div class="text-2xl font-bold">{{ $invoicesCount ?? 0 }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Enrollment Progress -->
                <div class="mt-6">
                    <h3 class="font-semibold">Enrollment Progress</h3>
                    <div class="mt-2 space-y-2">
                        @if(strtolower($enrollment->status ?? '') === 'active')
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm">Program Completion</span>
                                    <span class="text-sm font-medium">{{ $progressPercentage ?? 0 }}%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2.5">
                                    <div class="bg-primary h-2.5 rounded-full" style="width: {{ $progressPercentage ?? 0 }}%"></div>
                                </div>
                            </div>
                            <p class="mt-1 text-sm">
                                Student is actively progressing through the curriculum. The academic year is
                                @if($enrollment->academicYear?->is_current)
                                    currently in progress.
                                @else
                                    planned but not yet current.
                                @endif
                            </p>
                        @elseif(strtolower($enrollment->status ?? '') === 'pending')
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm">Program Completion</span>
                                    <span class="text-sm font-medium">{{ $progressPercentage ?? 0 }}%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2.5">
                                    <div class="bg-warning h-2.5 rounded-full" style="width: {{ $progressPercentage ?? 0 }}%"></div>
                                </div>
                            </div>
                            <p class="text-sm">
                                Enrollment is pending approval or confirmation. Once approved, the student will gain access to course materials.
                            </p>
                        @elseif(strtolower($enrollment->status ?? '') === 'completed')
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm">Program Completion</span>
                                    <span class="text-sm font-medium">100%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2.5">
                                    <div class="bg-success h-2.5 rounded-full" style="width: 100%"></div>
                                </div>
                            </div>
                            <p class="mt-1 text-sm">
                                Student has successfully completed all requirements for this curriculum.
                            </p>
                        @elseif(strtolower($enrollment->status ?? '') === 'cancelled')
                            <div>
                                <div class="flex justify-between mb-1">
                                    <span class="text-sm">Program Completion</span>
                                    <span class="text-sm font-medium">0%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2.5">
                                    <div class="bg-error h-2.5 rounded-full" style="width: 0%"></div>
                                </div>
                            </div>
                            <p class="text-sm text-error">
                                This enrollment has been cancelled. The student no longer has access to course materials.
                            </p>
                        @else
                            <p class="text-sm">
                                This enrollment has a custom status. Contact administration for more information.
                            </p>
                        @endif
                    </div>
                </div>

                @if($enrollment->academicYear)
                <div class="pt-4 mt-4 border-t">
                    <h3 class="font-semibold">Academic Year Timeline</h3>
                    <div class="mt-2">
                        <div class="flex flex-col justify-between text-sm sm:flex-row">
                            <div>
                                <span class="font-medium">Start:</span>
                                <span>{{ $enrollment->academicYear->start_date?->format('M d, Y') ?? 'Unknown' }}</span>
                            </div>
                            <div>
                                <span class="font-medium">End:</span>
                                <span>{{ $enrollment->academicYear->end_date?->format('M d, Y') ?? 'Unknown' }}</span>
                            </div>
                            @if($enrollment->academicYear->start_date && $enrollment->academicYear->end_date)
                            <div>
                                <span class="font-medium">Duration:</span>
                                <span>{{ $enrollment->academicYear->start_date->diffInMonths($enrollment->academicYear->end_date) }} months</span>
                            </div>
                            @endif
                        </div>

                        @if($enrollment->academicYear->is_current && $enrollment->academicYear->start_date && $enrollment->academicYear->end_date)
                        <div class="mt-3">
                            <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2">
                                @php
                                    $startDate = $enrollment->academicYear->start_date;
                                    $endDate = $enrollment->academicYear->end_date;
                                    $totalDays = $startDate->diffInDays($endDate);
                                    $daysPassed = $startDate->diffInDays(now());
                                    $timelinePercentage = min(100, max(0, round(($daysPassed / $totalDays) * 100)));
                                @endphp
                                <div class="bg-info h-1.5 rounded-full" style="width: {{ $timelinePercentage }}%"></div>
                            </div>
                            <div class="flex justify-between mt-1 text-xs text-gray-500">
                                <span>Start</span>
                                <span>Current: {{ $timelinePercentage }}%</span>
                                <span>End</span>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </x-card>
    </div>



    <!-- Tabs for related data -->
    <div class="mt-6">
        <div class="mb-4 tabs tabs-boxed">
            <button
                class="tab {{ $activeTab === 'subjects' ? 'tab-active' : '' }}"
                wire:click="switchTab('subjects')"
            >
                Subject Enrollments ({{ $subjectEnrollmentsCount }})
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
            @if ($activeTab === 'subjects')
                <div class="flex items-center justify-between p-4 border-b">
                    <h3 class="text-lg font-semibold">Enrolled Subjects</h3>

                    <x-button
                        label="Add Subjects"
                        icon="o-plus"
                        wire:click="showAddSubjectForm"
                        class="btn-primary btn-sm"
                        :disabled="!$hasAvailableSubjects"
                        tooltip="{{ $hasAvailableSubjects ? 'Add subjects to this enrollment' : 'No more subjects available in this curriculum' }}"
                    />
                </div>

                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject</th>
                                <th>Level</th>
                                <th>Date Enrolled</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($subjectEnrollments as $subjectEnrollment)
                                <tr class="hover">
                                    <td>{{ $subjectEnrollment->id }}</td>
                                    <td>
                                        <div class="font-bold">
                                            @if($subjectEnrollment->subject)
                                                <a href="{{ route('admin.subjects.show', $subjectEnrollment->subject->id) }}" class="link link-hover">
                                                    {{ $subjectEnrollment->subject->name }}
                                                </a>
                                            @else
                                                <span class="text-gray-500">Unknown subject</span>
                                            @endif
                                        </div>
                                        <div class="text-sm opacity-50">
                                            {{ $subjectEnrollment->subject->code ?? '' }}
                                        </div>
                                    </td>
                                    <td>
                                        @if($subjectEnrollment->subject)
                                            <x-badge
                                                label="{{ $subjectEnrollment->subject->level }}"
                                                color="{{ match(strtolower($subjectEnrollment->subject->level ?? '')) {
                                                    'beginner' => 'success',
                                                    'intermediate' => 'warning',
                                                    'advanced' => 'error',
                                                    default => 'ghost'
                                                } }}"
                                            />
                                        @else
                                            <span class="text-gray-500">N/A</span>
                                        @endif
                                    </td>
                                    <td>{{ $subjectEnrollment->created_at->format('M d, Y') }}</td>
                                    <td class="text-right">
                                        <x-button
                                            icon="o-trash"
                                            wire:click="confirmRemoveSubject({{ $subjectEnrollment->id }}, '{{ $subjectEnrollment->subject ? $subjectEnrollment->subject->name : 'Unknown subject' }}')"
                                            color="error"
                                            size="sm"
                                            tooltip="Remove subject"
                                        />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 text-center">
                                        <div class="flex flex-col items-center justify-center">
                                            <x-icon name="o-information-circle" class="w-12 h-12 text-gray-400" />
                                            <p class="mt-2 text-gray-500">No subjects enrolled yet.</p>
                                            @if($hasAvailableSubjects)
                                                <x-button
                                                    label="Add Subjects"
                                                    icon="o-plus"
                                                    wire:click="showAddSubjectForm"
                                                    class="mt-2 btn-primary btn-sm"
                                                />
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($subjectEnrollments && count($subjectEnrollments))
                    <div class="p-4 mt-4">
                        {{ $subjectEnrollments->links() }}
                    </div>
                @endif

            @elseif ($activeTab === 'invoices')
                <div class="flex items-center justify-between p-4 border-b">
                    <h3 class="text-lg font-semibold">Invoices</h3>

                    <x-button
                        label="Generate Invoice"
                        icon="o-plus"
                        link="{{ route('admin.invoices.create', ['program_enrollment_id' => $enrollment->id]) }}"
                        class="btn-primary btn-sm"
                    />
                </div>

                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Invoice Number</th>
                                <th>Amount</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
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
                                    <td class="text-right">
                                        <div class="flex justify-end gap-2">
                                            <x-button
                                                icon="o-eye"
                                                link="{{ route('admin.invoices.show', $invoice->id) }}"
                                                color="secondary"
                                                size="sm"
                                                tooltip="View"
                                            />

                                            <x-button
                                                icon="o-document-arrow-down"
                                                link="{{ route('admin.invoices.download', $invoice->id) }}"
                                                color="info"
                                                size="sm"
                                                tooltip="Download"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-4 text-center">
                                        <div class="flex flex-col items-center justify-center">
                                            <x-icon name="o-information-circle" class="w-12 h-12 text-gray-400" />
                                            <p class="mt-2 text-gray-500">No invoices found for this enrollment.</p>
                                            <x-button
                                                label="Generate Invoice"
                                                icon="o-plus"
                                                link="{{ route('admin.invoices.create', ['program_enrollment_id' => $enrollment->id]) }}"
                                                class="mt-2 btn-primary btn-sm"
                                            />
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

    <!-- Status change modal -->
    <x-modal wire:model="showStatusModal" title="Change Enrollment Status">
        <div class="p-4">
            <div class="mb-4">
                <x-select
                    label="New Status"
                    :options="$statuses"
                    wire:model="newStatus"
                    required
                />
            </div>

            <div class="py-2">
                <div class="flex items-center p-3 rounded-lg {{ match(strtolower($newStatus ?? '')) {
                    'active' => 'bg-success/10 text-success',
                    'pending' => 'bg-warning/10 text-warning',
                    'completed' => 'bg-info/10 text-info',
                    'cancelled' => 'bg-error/10 text-error',
                    default => 'bg-gray-100 text-gray-600'
                } }}">
                    <x-icon name="{{ match(strtolower($newStatus ?? '')) {
                        'active' => 'o-check-circle',
                        'pending' => 'o-clock',
                        'completed' => 'o-flag',
                        'cancelled' => 'o-x-circle',
                        default => 'o-information-circle'
                    } }}" class="w-6 h-6 mr-3" />

                    <div>
                        <p>{{ match(strtolower($newStatus ?? '')) {
                            'active' => 'Student will be marked as actively enrolled and will have access to all learning materials.',
                            'pending' => 'Enrollment will be marked as pending approval or confirmation.',
                            'completed' => 'Student will be marked as having completed all requirements for this curriculum.',
                            'cancelled' => 'Enrollment will be cancelled and student access will be revoked.',
                            default => 'Status will be updated with a custom value.'
                        } }}</p>
                    </div>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showStatusModal', false)" />
            <x-button label="Update Status" icon="o-check" wire:click="updateStatus" color="primary" />
        </x-slot:actions>
    </x-modal>

    <!-- Add subject modal -->
    <x-modal wire:model="showAddSubjectModal" title="Add Subjects to Enrollment">
        <div class="p-4">
            <p class="mb-4">Select subjects from {{ $curriculum ? $curriculum->name : 'this curriculum' }} to add to this enrollment:</p>

            @if(count($availableSubjects) > 0)
                <div class="grid grid-cols-1 gap-2 p-2 overflow-y-auto md:grid-cols-2 max-h-80">
                    @foreach($availableSubjects as $subject)
                        <div class="flex items-center p-2 border rounded-lg hover:bg-base-200 cursor-pointer {{ in_array($subject['id'], $selectedSubjects) ? 'border-primary bg-primary/5' : 'border-gray-200' }}"
                             wire:click="toggleSubject({{ $subject['id'] }})">
                            <div class="flex-1">
                                <div class="font-medium">{{ $subject['name'] }}</div>
                                <div class="text-xs opacity-70">{{ $subject['code'] }}</div>
                            </div>
                            <div>
                                @if(in_array($subject['id'], $selectedSubjects))
                                    <x-icon name="o-check-circle" class="w-6 h-6 text-primary" />
                                @else
                                    <div class="w-6 h-6 border-2 border-gray-300 rounded-full"></div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 text-sm">
                    <span class="font-semibold">{{ count($selectedSubjects) }}</span> subjects selected
                </div>
            @else
                <div class="py-8 text-center">
                    <x-icon name="o-information-circle" class="w-12 h-12 mx-auto text-gray-400" />
                    <p class="mt-2 text-gray-500">No more subjects available to add from this curriculum.</p>
                </div>
            @endif
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showAddSubjectModal', false)" />
            <x-button
                label="Add Selected Subjects"
                icon="o-plus"
                wire:click="addSubjects"
                color="primary"
                :disabled="count($selectedSubjects) === 0"
            />
        </x-slot:actions>
    </x-modal>

    <!-- Remove subject confirmation modal -->
    <x-modal wire:model="showRemoveSubjectModal" title="Remove Subject">
        <div class="p-4">
            <div class="flex items-center gap-4">
                <div class="p-3 rounded-full bg-error/20">
                    <x-icon name="o-exclamation-triangle" class="w-8 h-8 text-error" />
                </div>
                <div>
                    <h3 class="text-lg font-semibold">Are you sure you want to remove this subject?</h3>
                    <p class="text-gray-600">This action will remove the subject from this student's enrollment.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showRemoveSubjectModal', false)" />
            <x-button label="Remove Subject" icon="o-trash" wire:click="removeSubject" color="error" />
        </x-slot:actions>
    </x-modal>
</div>
