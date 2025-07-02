<?php

use App\Models\ProgramEnrollment;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Enrollment Details')] class extends Component {
    use Toast;

    // Model instance
    public ProgramEnrollment $programEnrollment;

    // Activity logs
    public $activityLogs = [];

    // Related data
    public $recentInvoices = [];
    public $attendanceStats = [];

    public function mount(ProgramEnrollment $programEnrollment): void
    {
        // Ensure the authenticated parent owns this enrollment
        if ($programEnrollment->childProfile->parent_id !== Auth::id()) {
            abort(403, 'You do not have permission to view this enrollment.');
        }

        $this->programEnrollment = $programEnrollment->load([
            'childProfile',
            'curriculum',
            'academicYear',
            'paymentPlan',
            'subjectEnrollments.subject'
        ]);

        // Load related data
        $this->loadActivityLogs();
        $this->loadRecentInvoices();
        $this->loadAttendanceStats();

        // Log activity
        ActivityLog::logActivity(
            Auth::id(),
            'view',
            "Viewed enrollment: {$programEnrollment->childProfile->full_name} in {$programEnrollment->curriculum->name}",
            $programEnrollment,
            [
                'child_name' => $programEnrollment->childProfile->full_name,
                'child_id' => $programEnrollment->childProfile->id,
                'curriculum_name' => $programEnrollment->curriculum->name,
                'curriculum_id' => $programEnrollment->curriculum->id,
            ]
        );
    }

    // Load activity logs for this enrollment
    protected function loadActivityLogs(): void
    {
        try {
            $this->activityLogs = ActivityLog::with('user')
                ->where(function ($query) {
                    $query->where('subject_type', ProgramEnrollment::class)
                          ->where('subject_id', $this->programEnrollment->id);
                })
                ->orWhere(function ($query) {
                    $query->where('loggable_type', ProgramEnrollment::class)
                          ->where('loggable_id', $this->programEnrollment->id);
                })
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();
        } catch (\Exception $e) {
            $this->activityLogs = collect();
        }
    }

    // Load recent invoices for this enrollment
    protected function loadRecentInvoices(): void
    {
        try {
            // Try to get invoices directly related to enrollment, fallback to child/academic year
            if (class_exists('App\Models\Invoice')) {
                $invoiceClass = 'App\Models\Invoice';

                $this->recentInvoices = $invoiceClass::where(function ($query) {
                    // Direct enrollment relationship
                    $query->where('program_enrollment_id', $this->programEnrollment->id)
                          // Or by child and academic year
                          ->orWhere(function ($q) {
                              $q->where('child_profile_id', $this->programEnrollment->child_profile_id)
                                ->where('academic_year_id', $this->programEnrollment->academic_year_id);
                          });
                })
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();
            } else {
                $this->recentInvoices = collect();
            }
        } catch (\Exception $e) {
            $this->recentInvoices = collect();
        }
    }

    // Load attendance statistics
    protected function loadAttendanceStats(): void
    {
        try {
            if (class_exists('App\Models\Attendance')) {
                $attendanceClass = 'App\Models\Attendance';

                $totalSessions = $attendanceClass::whereHas('session', function ($query) {
                    $query->whereHas('subjectEnrollment', function ($q) {
                        $q->where('program_enrollment_id', $this->programEnrollment->id);
                    });
                })->where('child_profile_id', $this->programEnrollment->child_profile_id)->count();

                $presentSessions = $attendanceClass::whereHas('session', function ($query) {
                    $query->whereHas('subjectEnrollment', function ($q) {
                        $q->where('program_enrollment_id', $this->programEnrollment->id);
                    });
                })
                ->where('child_profile_id', $this->programEnrollment->child_profile_id)
                ->where('status', 'present')
                ->count();

                $this->attendanceStats = [
                    'total_sessions' => $totalSessions,
                    'present_sessions' => $presentSessions,
                    'attendance_rate' => $totalSessions > 0 ? round(($presentSessions / $totalSessions) * 100, 1) : 0,
                ];
            } else {
                $this->attendanceStats = [
                    'total_sessions' => 0,
                    'present_sessions' => 0,
                    'attendance_rate' => 0,
                ];
            }
        } catch (\Exception $e) {
            $this->attendanceStats = [
                'total_sessions' => 0,
                'present_sessions' => 0,
                'attendance_rate' => 0,
            ];
        }
    }

    // Helper function to get status color
    private function getStatusColor(string $status): string
    {
        return match($status) {
            'Active' => 'bg-green-100 text-green-800',
            'Inactive' => 'bg-gray-100 text-gray-600',
            'Suspended' => 'bg-red-100 text-red-800',
            'Completed' => 'bg-blue-100 text-blue-800',
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
            'enroll' => 'o-academic-cap',
            'payment' => 'o-credit-card',
            'attendance' => 'o-calendar',
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
            'enroll' => 'text-indigo-600',
            'payment' => 'text-emerald-600',
            'attendance' => 'text-orange-600',
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
    <x-header title="Enrollment: {{ $programEnrollment->curriculum->name }}" separator>
        <x-slot:middle class="!justify-end">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getStatusColor($programEnrollment->status) }}">
                {{ $programEnrollment->status }}
            </span>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="View Child"
                icon="o-user"
                link="{{ route('parent.children.show', $programEnrollment->childProfile->id) }}"
                class="btn-ghost"
            />

            <x-button
                label="Back to List"
                icon="o-arrow-left"
                link="{{ route('parent.enrollments.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Enrollment Details -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Enrollment Information -->
            <x-card title="Enrollment Information">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Child</div>
                        <div class="flex items-center mt-1">
                            <div class="mr-3 avatar placeholder">
                                <div class="w-10 h-10 text-white rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                                    <span class="text-sm font-bold">{{ $programEnrollment->childProfile->initials }}</span>
                                </div>
                            </div>
                            <div>
                                <div class="font-semibold">{{ $programEnrollment->childProfile->full_name }}</div>
                                @if($programEnrollment->childProfile->age)
                                    <div class="text-sm text-gray-500">{{ $programEnrollment->childProfile->age }} years old</div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Program</div>
                        <div class="text-lg font-semibold">{{ $programEnrollment->curriculum->name }}</div>
                        @if($programEnrollment->curriculum->code)
                            <div class="text-sm text-gray-500">Code: {{ $programEnrollment->curriculum->code }}</div>
                        @endif
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Academic Year</div>
                        <div class="text-lg">{{ $programEnrollment->academicYear->name }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Status</div>
                        <div class="mt-1">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium {{ $this->getStatusColor($programEnrollment->status) }}">
                                {{ $programEnrollment->status }}
                            </span>
                        </div>
                    </div>

                    @if($programEnrollment->paymentPlan)
                        <div class="md:col-span-2">
                            <div class="text-sm font-medium text-gray-500">Payment Plan</div>
                            <div class="p-3 mt-1 border rounded-md bg-gray-50">
                                <div class="font-medium">{{ $programEnrollment->paymentPlan->name }}</div>
                                @if($programEnrollment->paymentPlan->amount)
                                    <div class="text-sm font-medium text-green-600">${{ number_format($programEnrollment->paymentPlan->amount, 2) }}</div>
                                @endif
                                @if($programEnrollment->paymentPlan->description)
                                    <div class="text-sm text-gray-600">{{ $programEnrollment->paymentPlan->description }}</div>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if($programEnrollment->curriculum->description)
                        <div class="md:col-span-2">
                            <div class="text-sm font-medium text-gray-500">Program Description</div>
                            <div class="p-3 mt-1 rounded-md bg-gray-50">
                                <p class="text-sm">{{ $programEnrollment->curriculum->description }}</p>
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Subject Enrollments -->
            @if($programEnrollment->subjectEnrollments->count() > 0)
                <x-card title="Enrolled Subjects">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        @foreach($programEnrollment->subjectEnrollments as $subjectEnrollment)
                            <div class="p-4 border rounded-lg">
                                <div class="font-medium">{{ $subjectEnrollment->subject->name ?? 'Unknown Subject' }}</div>
                                @if($subjectEnrollment->subject && $subjectEnrollment->subject->code)
                                    <div class="text-sm text-gray-500">{{ $subjectEnrollment->subject->code }}</div>
                                @endif
                                @if($subjectEnrollment->subject && $subjectEnrollment->subject->description)
                                    <div class="mt-2 text-sm text-gray-600">{{ $subjectEnrollment->subject->description }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif

            <!-- Attendance Overview -->
            @if($attendanceStats['total_sessions'] > 0)
                <x-card title="Attendance Overview">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-blue-600">{{ $attendanceStats['total_sessions'] }}</div>
                            <div class="text-sm text-gray-500">Total Sessions</div>
                        </div>

                        <div class="text-center">
                            <div class="text-2xl font-bold text-green-600">{{ $attendanceStats['present_sessions'] }}</div>
                            <div class="text-sm text-gray-500">Present</div>
                        </div>

                        <div class="text-center">
                            <div class="text-2xl font-bold {{ $attendanceStats['attendance_rate'] >= 80 ? 'text-green-600' : ($attendanceStats['attendance_rate'] >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                {{ $attendanceStats['attendance_rate'] }}%
                            </div>
                            <div class="text-sm text-gray-500">Attendance Rate</div>
                        </div>
                    </div>

                    <div class="pt-4 mt-4 border-t">
                        <x-button
                            label="View Full Attendance"
                            icon="o-calendar"
                            link="{{ route('parent.attendance.index', ['child' => $programEnrollment->childProfile->id]) }}"
                            class="btn-outline"
                        />
                    </div>
                </x-card>
            @endif

            <!-- Recent Invoices -->
            @if($recentInvoices->count() > 0)
                <x-card title="Recent Invoices">
                    <div class="space-y-3">
                        @foreach($recentInvoices as $invoice)
                            <div class="flex items-center justify-between p-3 border rounded-md">
                                <div class="flex-1">
                                    <div class="font-medium">{{ $invoice->invoice_number }}</div>
                                    <div class="text-sm text-gray-500">
                                        {{ $invoice->description ?: 'Invoice for ' . $programEnrollment->curriculum->name }}
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
                                <div class="ml-4">
                                    <x-button
                                        label="View"
                                        icon="o-eye"
                                        link="{{ route('parent.invoices.show', $invoice->id) }}"
                                        class="btn-xs btn-outline"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="pt-4 mt-4 border-t">
                        <x-button
                            label="View All Invoices"
                            icon="o-document-text"
                            link="{{ route('parent.invoices.index', ['child' => $programEnrollment->childProfile->id]) }}"
                            class="btn-outline"
                        />
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
                        label="View Child Profile"
                        icon="o-user"
                        link="{{ route('parent.children.show', $programEnrollment->childProfile->id) }}"
                        class="w-full btn-outline"
                    />

                    <x-button
                        label="View Attendance"
                        icon="o-calendar"
                        link="{{ route('parent.attendance.index', ['child' => $programEnrollment->childProfile->id]) }}"
                        class="w-full btn-outline"
                    />

                    <x-button
                        label="View Exams"
                        icon="o-academic-cap"
                        link="{{ route('parent.exams.index', ['child' => $programEnrollment->childProfile->id]) }}"
                        class="w-full btn-outline"
                    />

                    <x-button
                        label="View Invoices"
                        icon="o-document-text"
                        link="{{ route('parent.invoices.index', ['child' => $programEnrollment->childProfile->id]) }}"
                        class="w-full btn-outline"
                    />

                    @if($recentInvoices->where('status', 'pending')->count() > 0)
                        <x-button
                            label="Pay Pending Invoices"
                            icon="o-credit-card"
                            link="{{ route('parent.invoices.index', ['child' => $programEnrollment->childProfile->id, 'status' => 'pending']) }}"
                            class="w-full btn-primary"
                        />
                    @endif
                </div>
            </x-card>

            <!-- Enrollment Statistics -->
            <x-card title="Statistics">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Enrolled Subjects</span>
                        <span class="font-semibold">{{ $programEnrollment->subjectEnrollments->count() }}</span>
                    </div>

                    @if($attendanceStats['total_sessions'] > 0)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">Attendance Rate</span>
                            <span class="font-semibold {{ $attendanceStats['attendance_rate'] >= 80 ? 'text-green-600' : ($attendanceStats['attendance_rate'] >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                                {{ $attendanceStats['attendance_rate'] }}%
                            </span>
                        </div>
                    @endif

                    @if($recentInvoices->count() > 0)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">Total Invoices</span>
                            <span class="font-semibold">{{ $recentInvoices->count() }}</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">Pending Payments</span>
                            <span class="font-semibold text-yellow-600">{{ $recentInvoices->where('status', 'pending')->count() }}</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">Total Amount Due</span>
                            <span class="font-semibold text-red-600">
                                ${{ number_format($recentInvoices->where('status', 'pending')->sum('amount'), 2) }}
                            </span>
                        </div>
                    @endif

                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Enrolled Since</span>
                        <span class="font-semibold">{{ $this->formatDate($programEnrollment->created_at) }}</span>
                    </div>
                </div>
            </x-card>

            <!-- System Information -->
            <x-card title="System Information">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Enrollment ID</div>
                        <div class="font-mono text-xs">{{ $programEnrollment->id }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Created</div>
                        <div>{{ $programEnrollment->created_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Updated</div>
                        <div>{{ $programEnrollment->updated_at->format('M d, Y \a\t g:i A') }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Related Links -->
            <x-card title="Related">
                <div class="space-y-2">
                    <x-button
                        label="All Enrollments"
                        icon="o-academic-cap"
                        link="{{ route('parent.enrollments.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />

                    <x-button
                        label="Create New Enrollment"
                        icon="o-plus"
                        link="{{ route('parent.enrollments.create') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />

                    @if($programEnrollment->curriculum)
                        <x-button
                            label="Other {{ $programEnrollment->curriculum->name }} Enrollments"
                            icon="o-users"
                            link="{{ route('parent.enrollments.index', ['curriculum' => $programEnrollment->curriculum->id]) }}"
                            class="justify-start w-full btn-ghost btn-sm"
                        />
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</div>
