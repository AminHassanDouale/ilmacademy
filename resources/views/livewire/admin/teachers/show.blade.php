<?php

use App\Models\TeacherProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Teacher Details')] class extends Component {
    use Toast;

    // Model instance
    public TeacherProfile $teacherProfile;

    // Activity logs
    public $activityLogs = [];

    // Mount the component
    public function mount(TeacherProfile $teacherProfile): void
    {
        $this->teacherProfile = $teacherProfile->load([
            'user',
            'subjects',
            'sessions',
            'exams',
            'timetableSlots'
        ]);

        Log::info('Teacher Show Component Mounted', [
            'admin_user_id' => Auth::id(),
            'viewed_teacher_id' => $teacherProfile->id,
            'viewed_user_email' => $teacherProfile->user->email,
            'ip' => request()->ip()
        ]);

        // Load activity logs
        $this->loadActivityLogs();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed teacher profile: {$teacherProfile->user->name} ({$teacherProfile->user->email})",
            TeacherProfile::class,
            $teacherProfile->id,
            [
                'viewed_teacher_name' => $teacherProfile->user->name,
                'viewed_teacher_email' => $teacherProfile->user->email,
                'teacher_specialization' => $teacherProfile->specialization,
                'ip' => request()->ip()
            ]
        );
    }

    // Load activity logs for this teacher
    protected function loadActivityLogs(): void
    {
        try {
            $this->activityLogs = ActivityLog::with('user')
                ->where(function ($query) {
                    $query->where('loggable_type', TeacherProfile::class)
                          ->where('loggable_id', $this->teacherProfile->id);
                })
                ->orWhere('user_id', $this->teacherProfile->user_id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            Log::info('Activity Logs Loaded', [
                'teacher_id' => $this->teacherProfile->id,
                'logs_count' => $this->activityLogs->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to Load Activity Logs', [
                'teacher_id' => $this->teacherProfile->id,
                'error' => $e->getMessage()
            ]);
            $this->activityLogs = collect();
        }
    }

    // Activate teacher
    public function activateTeacher(): void
    {
        try {
            if ($this->teacherProfile->user->status === 'active') {
                $this->error('Teacher is already active.');
                return;
            }

            $oldStatus = $this->teacherProfile->user->status;
            $this->teacherProfile->user->update(['status' => 'active']);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                "Activated teacher: {$this->teacherProfile->user->name} ({$this->teacherProfile->user->email})",
                TeacherProfile::class,
                $this->teacherProfile->id,
                [
                    'old_status' => $oldStatus,
                    'new_status' => 'active',
                    'target_teacher_name' => $this->teacherProfile->user->name,
                    'target_teacher_email' => $this->teacherProfile->user->email
                ]
            );

            // Reload activity logs
            $this->loadActivityLogs();

            $this->success('Teacher activated successfully.');

            Log::info('Teacher Activated', [
                'admin_user_id' => Auth::id(),
                'target_teacher_id' => $this->teacherProfile->id,
                'old_status' => $oldStatus
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Activate Teacher', [
                'teacher_id' => $this->teacherProfile->id,
                'error' => $e->getMessage()
            ]);
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Deactivate teacher
    public function deactivateTeacher(): void
    {
        try {
            if ($this->teacherProfile->user_id === Auth::id()) {
                $this->error('You cannot deactivate your own account.');
                return;
            }

            if ($this->teacherProfile->user->status === 'inactive') {
                $this->error('Teacher is already inactive.');
                return;
            }

            $oldStatus = $this->teacherProfile->user->status;
            $this->teacherProfile->user->update(['status' => 'inactive']);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                "Deactivated teacher: {$this->teacherProfile->user->name} ({$this->teacherProfile->user->email})",
                TeacherProfile::class,
                $this->teacherProfile->id,
                [
                    'old_status' => $oldStatus,
                    'new_status' => 'inactive',
                    'target_teacher_name' => $this->teacherProfile->user->name,
                    'target_teacher_email' => $this->teacherProfile->user->email
                ]
            );

            // Reload activity logs
            $this->loadActivityLogs();

            $this->success('Teacher deactivated successfully.');

            Log::info('Teacher Deactivated', [
                'admin_user_id' => Auth::id(),
                'target_teacher_id' => $this->teacherProfile->id,
                'old_status' => $oldStatus
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Deactivate Teacher', [
                'teacher_id' => $this->teacherProfile->id,
                'error' => $e->getMessage()
            ]);
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Suspend teacher
    public function suspendTeacher(): void
    {
        try {
            if ($this->teacherProfile->user_id === Auth::id()) {
                $this->error('You cannot suspend your own account.');
                return;
            }

            if ($this->teacherProfile->user->status === 'suspended') {
                $this->error('Teacher is already suspended.');
                return;
            }

            $oldStatus = $this->teacherProfile->user->status;
            $this->teacherProfile->user->update(['status' => 'suspended']);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                "Suspended teacher: {$this->teacherProfile->user->name} ({$this->teacherProfile->user->email})",
                TeacherProfile::class,
                $this->teacherProfile->id,
                [
                    'old_status' => $oldStatus,
                    'new_status' => 'suspended',
                    'target_teacher_name' => $this->teacherProfile->user->name,
                    'target_teacher_email' => $this->teacherProfile->user->email
                ]
            );

            // Reload activity logs
            $this->loadActivityLogs();

            $this->success('Teacher suspended successfully.');

            Log::info('Teacher Suspended', [
                'admin_user_id' => Auth::id(),
                'target_teacher_id' => $this->teacherProfile->id,
                'old_status' => $oldStatus
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Suspend Teacher', [
                'teacher_id' => $this->teacherProfile->id,
                'error' => $e->getMessage()
            ]);
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Get status color for display
    public function getStatusColor(string $status): string
    {
        return match($status) {
            'active' => 'bg-green-100 text-green-800',
            'inactive' => 'bg-gray-100 text-gray-600',
            'suspended' => 'bg-red-100 text-red-800',
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
            'login' => 'o-arrow-right-on-rectangle',
            'logout' => 'o-arrow-left-on-rectangle',
            'bulk_update' => 'o-squares-plus',
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
            'login' => 'text-green-600',
            'logout' => 'text-orange-600',
            'bulk_update' => 'text-blue-600',
            default => 'text-gray-600'
        };
    }

    // Format date for display
    public function formatDate($date): string
    {
        if (!$date) {
            return 'Never';
        }

        if (is_string($date)) {
            $date = \Carbon\Carbon::parse($date);
        }

        return $date->format('M d, Y \a\t g:i A');
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
    <x-header title="Teacher: {{ $teacherProfile->user->name }}" separator>
        <x-slot:middle class="!justify-end">
            <!-- Status Badge -->
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getStatusColor($teacherProfile->user->status) }}">
                {{ ucfirst($teacherProfile->user->status) }}
            </span>

            @if($teacherProfile->user_id === Auth::id())
                <span class="inline-flex items-center px-3 py-1 ml-2 text-sm font-medium text-blue-800 bg-blue-100 rounded-full">
                    <x-icon name="o-user" class="w-4 h-4 mr-1" />
                    You
                </span>
            @endif

            @if($teacherProfile->specialization)
                <span class="inline-flex items-center px-3 py-1 ml-2 text-sm font-medium text-purple-800 bg-purple-100 rounded-full">
                    <x-icon name="o-academic-cap" class="w-4 h-4 mr-1" />
                    {{ ucfirst($teacherProfile->specialization) }}
                </span>
            @endif
        </x-slot:middle>

        <x-slot:actions>
            <!-- Action buttons based on status -->
            @if($teacherProfile->user->status !== 'active')
                <x-button
                    label="Activate"
                    icon="o-check"
                    wire:click="activateTeacher"
                    class="btn-success"
                    wire:confirm="Are you sure you want to activate this teacher?"
                />
            @endif

            @if($teacherProfile->user->status !== 'inactive' && $teacherProfile->user_id !== Auth::id())
                <x-button
                    label="Deactivate"
                    icon="o-x-mark"
                    wire:click="deactivateTeacher"
                    class="btn-error"
                    wire:confirm="Are you sure you want to deactivate this teacher?"
                />
            @endif

            @if($teacherProfile->user->status !== 'suspended' && $teacherProfile->user_id !== Auth::id())
                <x-button
                    label="Suspend"
                    icon="o-shield-exclamation"
                    wire:click="suspendTeacher"
                    class="btn-warning"
                    wire:confirm="Are you sure you want to suspend this teacher?"
                />
            @endif

            <x-button
                label="Edit"
                icon="o-pencil"
                link="{{ route('admin.teachers.edit', $teacherProfile->id) }}"
                class="btn-primary"
            />

            <x-button
                label="Back to List"
                icon="o-arrow-left"
                link="{{ route('admin.teachers.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Teacher Details -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Teacher Information -->
            <x-card title="Teacher Information">
                <div class="flex items-start space-x-6">
                    <!-- Avatar -->
                    <div class="flex-shrink-0">
                        <div class="avatar">
                            <div class="w-24 h-24 rounded-full">
                                <img src="{{ $teacherProfile->user->profile_photo_url }}" alt="{{ $teacherProfile->user->name }}" />
                            </div>
                        </div>
                    </div>

                    <!-- Teacher Details -->
                    <div class="grid flex-1 grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Full Name</div>
                            <div class="text-lg font-semibold">{{ $teacherProfile->user->name }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Email Address</div>
                            <div class="font-mono">{{ $teacherProfile->user->email }}</div>
                            @if($teacherProfile->user->email_verified_at)
                                <div class="mt-1 text-xs text-green-600">
                                    <x-icon name="o-check-circle" class="inline w-3 h-3 mr-1" />
                                    Verified {{ $teacherProfile->user->email_verified_at->diffForHumans() }}
                                </div>
                            @else
                                <div class="mt-1 text-xs text-red-600">
                                    <x-icon name="o-x-circle" class="inline w-3 h-3 mr-1" />
                                    Not verified
                                </div>
                            @endif
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Phone Number</div>
                            <div class="font-mono">{{ $teacherProfile->phone ?: $teacherProfile->user->phone ?: 'Not provided' }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Status</div>
                            <div>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($teacherProfile->user->status) }}">
                                    {{ ucfirst($teacherProfile->user->status) }}
                                </span>
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Specialization</div>
                            <div>
                                @if($teacherProfile->specialization)
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-purple-800 bg-purple-100 rounded-full">
                                        {{ ucfirst($teacherProfile->specialization) }}
                                    </span>
                                @else
                                    <span class="text-gray-500">Not specified</span>
                                @endif
                            </div>
                        </div>

                        @if($teacherProfile->bio)
                            <div class="md:col-span-2">
                                <div class="text-sm font-medium text-gray-500">Biography</div>
                                <div class="p-3 rounded-md bg-gray-50">{{ $teacherProfile->bio }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Subjects Teaching -->
            <x-card title="Subjects Teaching">
                @if($teacherProfile->subjects->count() > 0)
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        @foreach($teacherProfile->subjects as $subject)
                            <div class="p-4 border border-blue-200 rounded-lg bg-blue-50">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-semibold text-blue-900">{{ $subject->name }}</div>
                                        @if($subject->description)
                                            <div class="text-sm text-blue-700">{{ $subject->description }}</div>
                                        @endif
                                    </div>
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-800 bg-blue-100 rounded-full">
                                        Subject
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-book-open" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No subjects assigned</div>
                        <x-button
                            label="Assign Subjects"
                            icon="o-plus"
                            link="{{ route('admin.teachers.edit', $teacherProfile->id) }}"
                            class="mt-2 btn-primary btn-sm"
                        />
                    </div>
                @endif
            </x-card>

            <!-- Sessions Overview -->
            <x-card title="Recent Sessions">
                @if($teacherProfile->sessions->count() > 0)
                    <div class="space-y-3">
                        @foreach($teacherProfile->sessions->take(5) as $session)
                            <div class="p-3 border rounded-md bg-gray-50">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-medium">{{ $session->title ?? 'Session #' . $session->id }}</div>
                                        <div class="text-sm text-gray-500">
                                            {{ $session->start_time ? $session->start_time->format('M d, Y \a\t g:i A') : 'No date set' }}
                                        </div>
                                    </div>
                                    <x-button
                                        label="View"
                                        icon="o-eye"
                                        link="{{ route('admin.teachers.sessions', $teacherProfile->id) }}"
                                        class="btn-ghost btn-xs"
                                    />
                                </div>
                            </div>
                        @endforeach
                        @if($teacherProfile->sessions->count() > 5)
                            <div class="pt-2 text-center">
                                <x-button
                                    label="View All Sessions ({{ $teacherProfile->sessions->count() }})"
                                    icon="o-eye"
                                    link="{{ route('admin.teachers.sessions', $teacherProfile->id) }}"
                                    class="btn-outline btn-sm"
                                />
                            </div>
                        @endif
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-calendar" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No sessions found</div>
                    </div>
                @endif
            </x-card>

            <!-- Activity Log -->
            <x-card title="Activity Log">
                @if($activityLogs->count() > 0)
                    <div class="space-y-4 overflow-y-auto max-h-96">
                        @foreach($activityLogs as $log)
                            <div class="flex items-start pb-4 space-x-4 border-b border-gray-100 last:border-b-0">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center w-8 h-8 bg-gray-100 rounded-full">
                                        <x-icon name="{{ $this->getActivityIcon($log->action) }}" class="w-4 h-4 {{ $this->getActivityColor($log->action) }}" />
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm">
                                        <span class="font-medium text-gray-900">
                                            {{ $log->user ? $log->user->name : 'System' }}
                                        </span>
                                        <span class="text-gray-600">{{ $log->description }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ $log->created_at->diffForHumans() }} • {{ $log->created_at->format('M d, Y \a\t g:i A') }}
                                    </div>
                                    @if($log->additional_data && is_array($log->additional_data) && count($log->additional_data) > 0)
                                        <div class="mt-2">
                                            <details class="text-xs">
                                                <summary class="text-gray-500 cursor-pointer hover:text-gray-700">View details</summary>
                                                <div class="p-2 mt-2 font-mono text-xs rounded bg-gray-50">
                                                    @foreach($log->additional_data as $key => $value)
                                                        @if(!in_array($key, ['ip', 'ip_address']))
                                                            <div><strong>{{ ucfirst(str_replace('_', ' ', $key)) }}:</strong>
                                                                @if(is_array($value) || is_object($value))
                                                                    {{ json_encode($value, JSON_PRETTY_PRINT) }}
                                                                @else
                                                                    {{ $value }}
                                                                @endif
                                                            </div>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            </details>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-document-text" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No activity logs available</div>
                    </div>
                @endif
            </x-card>
        </div>

        <!-- Right column (1/3) - Additional Info -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            @if($teacherProfile->user_id !== Auth::id())
                <x-card title="Quick Actions">
                    <div class="space-y-3">
                        @if($teacherProfile->user->status !== 'active')
                            <x-button
                                label="Activate Teacher"
                                icon="o-check"
                                wire:click="activateTeacher"
                                class="w-full btn-success"
                                wire:confirm="Are you sure you want to activate this teacher?"
                            />
                        @endif

                        @if($teacherProfile->user->status !== 'inactive')
                            <x-button
                                label="Deactivate Teacher"
                                icon="o-x-mark"
                                wire:click="deactivateTeacher"
                                class="w-full btn-error"
                                wire:confirm="Are you sure you want to deactivate this teacher?"
                            />
                        @endif

                        @if($teacherProfile->user->status !== 'suspended')
                            <x-button
                                label="Suspend Teacher"
                                icon="o-shield-exclamation"
                                wire:click="suspendTeacher"
                                class="w-full btn-warning"
                                wire:confirm="Are you sure you want to suspend this teacher?"
                            />
                        @endif

                        <x-button
                            label="Edit Teacher"
                            icon="o-pencil"
                            link="{{ route('admin.teachers.edit', $teacherProfile->id) }}"
                            class="w-full btn-outline"
                        />
                    </div>
                </x-card>
            @endif

            <!-- Statistics -->
            <x-card title="Teaching Statistics">
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-500">Subjects Teaching</span>
                        <span class="font-semibold">{{ $teacherProfile->subjects->count() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-500">Total Sessions</span>
                        <span class="font-semibold">{{ $teacherProfile->sessions->count() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-500">Exams Created</span>
                        <span class="font-semibold">{{ $teacherProfile->exams->count() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-500">Timetable Slots</span>
                        <span class="font-semibold">{{ $teacherProfile->timetableSlots->count() }}</span>
                    </div>
                </div>
            </x-card>

            <!-- System Information -->
            <x-card title="System Information">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Teacher ID</div>
                        <div class="font-mono text-xs">{{ $teacherProfile->id }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">User ID</div>
                        <div class="font-mono text-xs">{{ $teacherProfile->user_id }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Profile Created</div>
                        <div>{{ $this->formatDate($teacherProfile->created_at) }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Updated</div>
                        <div>{{ $this->formatDate($teacherProfile->updated_at) }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">User Last Login</div>
                        <div>{{ $this->formatDate($teacherProfile->user->last_login_at) }}</div>
                        @if($teacherProfile->user->last_login_ip)
                            <div class="font-mono text-xs text-gray-500">{{ $teacherProfile->user->last_login_ip }}</div>
                        @endif
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Email Verification</div>
                        <div>
                            @if($teacherProfile->user->email_verified_at)
                                <span class="text-xs text-green-600">
                                    <x-icon name="o-check-circle" class="inline w-3 h-3 mr-1" />
                                    Verified
                                </span>
                            @else
                                <span class="text-xs text-red-600">
                                    <x-icon name="o-x-circle" class="inline w-3 h-3 mr-1" />
                                    Not verified
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Related Links -->
            <x-card title="Related">
                <div class="space-y-2">
                    <x-button
                        label="View Sessions"
                        icon="o-calendar"
                        link="{{ route('admin.teachers.sessions', $teacherProfile->id) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />

                    <x-button
                        label="View Exams"
                        icon="o-document-text"
                        link="{{ route('admin.teachers.exams', $teacherProfile->id) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />

                    <x-button
                        label="View Timetable"
                        icon="o-clock"
                        link="{{ route('admin.teachers.timetable', $teacherProfile->id) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />

                    <x-button
                        label="View All Teachers"
                        icon="o-academic-cap"
                        link="{{ route('admin.teachers.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />

                    <x-button
                        label="Activity Logs"
                        icon="o-document-text"
                        link="{{ route('admin.activity-logs.index', ['teacher' => $teacherProfile->id]) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                </div>
            </x-card>
        </div>
    </div>
</div>
