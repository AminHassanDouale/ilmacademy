<?php

use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Child Details')] class extends Component {
    use Toast;

    // Model instance
    public ChildProfile $childProfile;

    // Activity logs
    public $activityLogs = [];

    public function mount(ChildProfile $childProfile): void
    {
        // Ensure the authenticated parent owns this child
        if ($childProfile->parent_id !== Auth::id()) {
            abort(403, 'You do not have permission to view this child.');
        }

        $this->childProfile = $childProfile->load([
            'programEnrollments.curriculum',
            'programEnrollments.academicYear',
            'programEnrollments.paymentPlan',
            'invoices.academicYear',
            'invoices.curriculum',
            'parent'
        ]);

        // Load activity logs
        $this->loadActivityLogs();

        // Log activity
        ActivityLog::logActivity(
            Auth::id(),
            'view',
            "Viewed child profile: {$childProfile->full_name}",
            $childProfile,
            [
                'child_name' => $childProfile->full_name,
                'child_id' => $childProfile->id,
            ]
        );
    }

    // Load activity logs for this child
    protected function loadActivityLogs(): void
    {
        try {
            $this->activityLogs = ActivityLog::with('user')
                ->where(function ($query) {
                    $query->where('subject_type', ChildProfile::class)
                          ->where('subject_id', $this->childProfile->id);
                })
                ->orWhere(function ($query) {
                    $query->where('loggable_type', ChildProfile::class)
                          ->where('loggable_id', $this->childProfile->id);
                })
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        } catch (\Exception $e) {
            $this->activityLogs = collect();
        }
    }

    // Navigation methods
    public function redirectToEdit(): void
    {
        $this->redirect(route('parent.children.edit', $this->childProfile));
    }

    public function redirectToEnrollments(): void
    {
        $this->redirect(route('parent.enrollments.index', ['child' => $this->childProfile->id]));
    }

    public function redirectToInvoices(): void
    {
        $this->redirect(route('parent.invoices.index', ['child' => $this->childProfile->id]));
    }

    public function redirectToAttendance(): void
    {
        $this->redirect(route('parent.attendance.index', ['child' => $this->childProfile->id]));
    }

    // Helper function to get age color
    private function getAgeColor(int $age): string
    {
        return match(true) {
            $age <= 3 => 'bg-pink-100 text-pink-800',
            $age <= 6 => 'bg-purple-100 text-purple-800',
            $age <= 12 => 'bg-blue-100 text-blue-800',
            $age <= 18 => 'bg-green-100 text-green-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to get enrollment status color
    private function getEnrollmentStatusColor(string $status): string
    {
        return match(strtolower($status)) {
            'active' => 'bg-green-100 text-green-800',
            'inactive' => 'bg-gray-100 text-gray-600',
            'suspended' => 'bg-red-100 text-red-800',
            'completed' => 'bg-blue-100 text-blue-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to get invoice status color
    private function getInvoiceStatusColor(string $status): string
    {
        return match(strtolower($status)) {
            'paid' => 'bg-green-100 text-green-800',
            'pending' => 'bg-yellow-100 text-yellow-800',
            'overdue' => 'bg-red-100 text-red-800',
            'cancelled' => 'bg-gray-100 text-gray-600',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Get activity icon
    public function getActivityIcon(string $action): string
    {
        return match($action) {
            'create' => 'o-plus',
            'update' => 'o-pencil',
            'view' => 'o-eye',
            'access' => 'o-arrow-right-on-rectangle',
            'delete' => 'o-trash',
            'enroll' => 'o-academic-cap',
            'payment' => 'o-credit-card',
            default => 'o-information-circle'
        };
    }

    // Get activity color
    public function getActivityColor(string $action): string
    {
        return match($action) {
            'create' => 'text-green-600',
            'update' => 'text-blue-600',
            'view' => 'text-gray-600',
            'access' => 'text-purple-600',
            'delete' => 'text-red-600',
            'enroll' => 'text-indigo-600',
            'payment' => 'text-emerald-600',
            default => 'text-gray-600'
        };
    }

    // Format date for display
    public function formatDate($date): string
    {
        if (!$date) {
            return 'Not set';
        }

        if (is_string($date)) {
            $date = \Carbon\Carbon::parse($date);
        }

        return $date->format('M d, Y');
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
    <x-header title="Child: {{ $childProfile->full_name }}" separator>
        <x-slot:middle class="!justify-end">
            @if($childProfile->age)
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getAgeColor($childProfile->age) }}">
                    {{ $childProfile->age }} years old
                </span>
            @endif
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Edit"
                icon="o-pencil"
                wire:click="redirectToEdit"
                class="btn-primary"
            />

            <x-button
                label="Back to List"
                icon="o-arrow-left"
                link="{{ route('parent.children.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Child Details -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Child Information -->
            <x-card title="Child Information">
                <div class="flex items-start space-x-6">
                    <!-- Avatar -->
                    <div class="flex-shrink-0">
                        <div class="avatar placeholder">
                            <div class="w-24 h-24 text-white rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                                <span class="text-2xl font-bold">{{ $childProfile->initials }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Child Details -->
                    <div class="grid flex-1 grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Full Name</div>
                            <div class="text-lg font-semibold">{{ $childProfile->full_name }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Date of Birth</div>
                            <div>{{ $this->formatDate($childProfile->date_of_birth) }}</div>
                            @if($childProfile->age)
                                <div class="text-sm text-gray-500">{{ $childProfile->age }} years old</div>
                            @endif
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Gender</div>
                            <div>{{ $childProfile->gender ? ucfirst($childProfile->gender) : 'Not specified' }}</div>
                        </div>

                        @if($childProfile->email)
                            <div>
                                <div class="text-sm font-medium text-gray-500">Email Address</div>
                                <div class="font-mono text-sm">{{ $childProfile->email }}</div>
                            </div>
                        @endif

                        @if($childProfile->phone)
                            <div>
                                <div class="text-sm font-medium text-gray-500">Phone Number</div>
                                <div class="font-mono text-sm">{{ $childProfile->phone }}</div>
                            </div>
                        @endif

                        @if($childProfile->address)
                            <div class="md:col-span-2">
                                <div class="text-sm font-medium text-gray-500">Address</div>
                                <div class="p-3 rounded-md bg-gray-50">{{ $childProfile->address }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Emergency Contact -->
            <x-card title="Emergency Contact">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Contact Name</div>
                        <div class="text-lg">{{ $childProfile->emergency_contact_name ?: 'Not provided' }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Contact Phone</div>
                        <div class="font-mono text-lg">{{ $childProfile->emergency_contact_phone ?: 'Not provided' }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Medical Information -->
            @if($childProfile->medical_conditions || $childProfile->allergies || $childProfile->special_needs || $childProfile->additional_needs)
                <x-card title="Medical Information">
                    <div class="space-y-4">
                        @if($childProfile->medical_conditions)
                            <div>
                                <div class="mb-2 text-sm font-medium text-gray-500">Medical Conditions</div>
                                <div class="p-3 border border-yellow-200 rounded-md bg-yellow-50">
                                    <p class="text-sm">{{ $childProfile->medical_conditions }}</p>
                                </div>
                            </div>
                        @endif

                        @if($childProfile->allergies)
                            <div>
                                <div class="mb-2 text-sm font-medium text-gray-500">Allergies</div>
                                <div class="p-3 border border-red-200 rounded-md bg-red-50">
                                    <p class="text-sm text-red-800">{{ $childProfile->allergies }}</p>
                                </div>
                            </div>
                        @endif

                        @if($childProfile->special_needs)
                            <div>
                                <div class="mb-2 text-sm font-medium text-gray-500">Special Needs</div>
                                <div class="p-3 border border-blue-200 rounded-md bg-blue-50">
                                    <p class="text-sm">{{ $childProfile->special_needs }}</p>
                                </div>
                            </div>
                        @endif

                        @if($childProfile->additional_needs)
                            <div>
                                <div class="mb-2 text-sm font-medium text-gray-500">Additional Needs</div>
                                <div class="p-3 border border-purple-200 rounded-md bg-purple-50">
                                    <p class="text-sm">{{ $childProfile->additional_needs }}</p>
                                </div>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif

            <!-- Program Enrollments -->
            <x-card title="Program Enrollments">
                @if($childProfile->programEnrollments->count() > 0)
                    <div class="space-y-4">
                        @foreach($childProfile->programEnrollments as $enrollment)
                            <div class="p-4 border rounded-lg hover:bg-gray-50">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3">
                                            <h4 class="text-lg font-semibold">{{ $enrollment->curriculum->name ?? 'Unknown Curriculum' }}</h4>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getEnrollmentStatusColor($enrollment->status) }}">
                                                {{ ucfirst($enrollment->status) }}
                                            </span>
                                        </div>
                                        <div class="mt-1 text-sm text-gray-600">
                                            Academic Year: {{ $enrollment->academicYear->name ?? 'Unknown Year' }}
                                        </div>
                                        @if($enrollment->paymentPlan)
                                            <div class="text-sm text-gray-600">
                                                Payment Plan: {{ $enrollment->paymentPlan->name }}
                                            </div>
                                        @endif
                                    </div>
                                    <div class="flex gap-2">
                                        <x-button
                                            label="View"
                                            icon="o-eye"
                                            link="{{ route('parent.enrollments.show', $enrollment) }}"
                                            class="btn-ghost btn-sm"
                                        />
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="pt-4 mt-4 border-t">
                        <x-button
                            label="View All Enrollments"
                            icon="o-academic-cap"
                            wire:click="redirectToEnrollments"
                            class="btn-outline"
                        />
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-academic-cap" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No enrollments yet</div>
                        <x-button
                            label="Create Enrollment"
                            icon="o-plus"
                            link="{{ route('parent.enrollments.create', ['child' => $childProfile->id]) }}"
                            class="mt-2 btn-primary btn-sm"
                        />
                    </div>
                @endif
            </x-card>

            <!-- Recent Invoices -->
            @if($childProfile->invoices && $childProfile->invoices->count() > 0)
                <x-card title="Recent Invoices">
                    <div class="space-y-3">
                        @foreach($childProfile->invoices->take(3) as $invoice)
                            <div class="flex items-center justify-between p-3 border rounded-md">
                                <div>
                                    <div class="font-medium">{{ $invoice->invoice_number }}</div>
                                    <div class="text-sm text-gray-500">
                                        {{ $invoice->description ?: ($invoice->curriculum->name ?? 'Invoice') }}
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        Due: {{ $this->formatDate($invoice->due_date) }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-semibold">${{ number_format($invoice->amount, 2) }}</div>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getInvoiceStatusColor($invoice->status) }}">
                                        {{ ucfirst($invoice->status) }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="pt-4 mt-4 border-t">
                        <x-button
                            label="View All Invoices"
                            icon="o-document-text"
                            wire:click="redirectToInvoices"
                            class="btn-outline"
                        />
                    </div>
                </x-card>
            @endif

            <!-- Notes -->
            @if($childProfile->notes)
                <x-card title="Additional Notes">
                    <div class="p-4 rounded-md bg-gray-50">
                        <p class="text-sm">{{ $childProfile->notes }}</p>
                    </div>
                </x-card>
            @endif

            <!-- Activity Log -->
            @if($activityLogs->count() > 0)
                <x-card title="Recent Activity">
                    <div class="space-y-4 overflow-y-auto max-h-96">
                        @foreach($activityLogs as $log)
                            <div class="flex items-start pb-4 space-x-4 border-b border-gray-100 last:border-b-0">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center w-8 h-8 bg-gray-100 rounded-full">
                                        <x-icon name="{{ $this->getActivityIcon($log->action ?? $log->activity_type) }}" class="w-4 h-4 {{ $this->getActivityColor($log->action ?? $log->activity_type) }}" />
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm">
                                        <span class="font-medium text-gray-900">
                                            {{ $log->user ? $log->user->name : 'System' }}
                                        </span>
                                        <span class="text-gray-600">{{ $log->description ?? $log->activity_description }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ $log->created_at->diffForHumans() }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>

        <!-- Right column (1/3) - Quick Actions and Stats -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-3">
                    <x-button
                        label="Edit Child"
                        icon="o-pencil"
                        wire:click="redirectToEdit"
                        class="w-full btn-outline"
                    />

                    <x-button
                        label="View Enrollments"
                        icon="o-academic-cap"
                        wire:click="redirectToEnrollments"
                        class="w-full btn-outline"
                    />

                    <x-button
                        label="View Invoices"
                        icon="o-document-text"
                        wire:click="redirectToInvoices"
                        class="w-full btn-outline"
                    />

                    <x-button
                        label="View Attendance"
                        icon="o-calendar"
                        wire:click="redirectToAttendance"
                        class="w-full btn-outline"
                    />

                    <x-button
                        label="View Exams"
                        icon="o-academic-cap"
                        link="{{ route('parent.exams.index', ['child' => $childProfile->id]) }}"
                        class="w-full btn-outline"
                    />
                </div>
            </x-card>

            <!-- Child Statistics -->
            <x-card title="Statistics">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Active Enrollments</span>
                        <span class="font-semibold">{{ $childProfile->programEnrollments->where('status', 'Active')->count() }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Total Enrollments</span>
                        <span class="font-semibold">{{ $childProfile->programEnrollments->count() }}</span>
                    </div>

                    @if($childProfile->invoices)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">Total Invoices</span>
                            <span class="font-semibold">{{ $childProfile->invoices->count() }}</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">Pending Invoices</span>
                            <span class="font-semibold text-yellow-600">{{ $childProfile->invoices->where('status', 'pending')->count() }}</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">Paid Invoices</span>
                            <span class="font-semibold text-green-600">{{ $childProfile->invoices->where('status', 'paid')->count() }}</span>
                        </div>
                    @endif

                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Profile Created</span>
                        <span class="font-semibold">{{ $this->formatDate($childProfile->created_at) }}</span>
                    </div>
                </div>
            </x-card>

            <!-- System Information -->
            <x-card title="System Information">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Child ID</div>
                        <div class="font-mono text-xs">{{ $childProfile->id }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Created</div>
                        <div>{{ $childProfile->created_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Updated</div>
                        <div>{{ $childProfile->updated_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Parent</div>
                        <div>{{ $childProfile->parent->name ?? 'Unknown' }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Related Links -->
            <x-card title="Related">
                <div class="space-y-2">
                    <x-button
                        label="All Children"
                        icon="o-users"
                        link="{{ route('parent.children.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />

                    <x-button
                        label="Create Enrollment"
                        icon="o-plus"
                        link="{{ route('parent.enrollments.create', ['child' => $childProfile->id]) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />

                    @if($childProfile->programEnrollments->count() > 0)
                        <x-button
                            label="Academic Reports"
                            icon="o-chart-bar"
                            link="#"
                            class="justify-start w-full btn-ghost btn-sm"
                        />
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</div>
