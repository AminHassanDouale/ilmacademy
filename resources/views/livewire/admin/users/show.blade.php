<?php

use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('User Details')] class extends Component {
    use Toast;

    // Model instance
    public User $user;

    // Activity logs
    public $activityLogs = [];

    // Mount the component
    public function mount(User $user): void
    {
        $this->user = $user->load([
            'roles',
            'teacherProfile',
            'parentProfile',
            'clientProfile',
            'childProfile',
            'children',
            'programEnrollments.curriculum',
            'programEnrollments.academicYear'
        ]);

        Log::info('User Show Component Mounted', [
            'admin_user_id' => Auth::id(),
            'viewed_user_id' => $user->id,
            'viewed_user_email' => $user->email,
            'ip' => request()->ip()
        ]);

        // Load activity logs
        $this->loadActivityLogs();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed user profile: {$user->name} ({$user->email})",
            User::class,
            $user->id,
            [
                'viewed_user_name' => $user->name,
                'viewed_user_email' => $user->email,
                'viewed_user_roles' => $user->roles->pluck('name')->toArray(),
                'ip' => request()->ip()
            ]
        );
    }

    // Load activity logs for this user
    protected function loadActivityLogs(): void
    {
        try {
            $this->activityLogs = ActivityLog::with('user')
                ->where(function ($query) {
                    $query->where('loggable_type', User::class)
                          ->where('loggable_id', $this->user->id);
                })
                ->orWhere('user_id', $this->user->id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            Log::info('Activity Logs Loaded', [
                'user_id' => $this->user->id,
                'logs_count' => $this->activityLogs->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to Load Activity Logs', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage()
            ]);
            $this->activityLogs = collect();
        }
    }

    // Activate user
    public function activateUser(): void
    {
        try {
            if ($this->user->status === 'active') {
                $this->error('User is already active.');
                return;
            }

            $oldStatus = $this->user->status;
            $this->user->update(['status' => 'active']);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                "Activated user: {$this->user->name} ({$this->user->email})",
                User::class,
                $this->user->id,
                [
                    'old_status' => $oldStatus,
                    'new_status' => 'active',
                    'target_user_name' => $this->user->name,
                    'target_user_email' => $this->user->email
                ]
            );

            // Reload activity logs
            $this->loadActivityLogs();

            $this->success('User activated successfully.');

            Log::info('User Activated', [
                'admin_user_id' => Auth::id(),
                'target_user_id' => $this->user->id,
                'old_status' => $oldStatus
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Activate User', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage()
            ]);
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Deactivate user
    public function deactivateUser(): void
    {
        try {
            if ($this->user->id === Auth::id()) {
                $this->error('You cannot deactivate your own account.');
                return;
            }

            if ($this->user->status === 'inactive') {
                $this->error('User is already inactive.');
                return;
            }

            $oldStatus = $this->user->status;
            $this->user->update(['status' => 'inactive']);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                "Deactivated user: {$this->user->name} ({$this->user->email})",
                User::class,
                $this->user->id,
                [
                    'old_status' => $oldStatus,
                    'new_status' => 'inactive',
                    'target_user_name' => $this->user->name,
                    'target_user_email' => $this->user->email
                ]
            );

            // Reload activity logs
            $this->loadActivityLogs();

            $this->success('User deactivated successfully.');

            Log::info('User Deactivated', [
                'admin_user_id' => Auth::id(),
                'target_user_id' => $this->user->id,
                'old_status' => $oldStatus
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Deactivate User', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage()
            ]);
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Suspend user
    public function suspendUser(): void
    {
        try {
            if ($this->user->id === Auth::id()) {
                $this->error('You cannot suspend your own account.');
                return;
            }

            if ($this->user->status === 'suspended') {
                $this->error('User is already suspended.');
                return;
            }

            $oldStatus = $this->user->status;
            $this->user->update(['status' => 'suspended']);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                "Suspended user: {$this->user->name} ({$this->user->email})",
                User::class,
                $this->user->id,
                [
                    'old_status' => $oldStatus,
                    'new_status' => 'suspended',
                    'target_user_name' => $this->user->name,
                    'target_user_email' => $this->user->email
                ]
            );

            // Reload activity logs
            $this->loadActivityLogs();

            $this->success('User suspended successfully.');

            Log::info('User Suspended', [
                'admin_user_id' => Auth::id(),
                'target_user_id' => $this->user->id,
                'old_status' => $oldStatus
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Suspend User', [
                'user_id' => $this->user->id,
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

    // Get role color for display
    public function getRoleColor(string $role): string
    {
        return match($role) {
            'admin' => 'bg-purple-100 text-purple-800',
            'teacher' => 'bg-blue-100 text-blue-800',
            'parent' => 'bg-green-100 text-green-800',
            'student' => 'bg-orange-100 text-orange-800',
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

    // Get user's profile information
    public function getUserProfileProperty()
    {
        if ($this->user->teacherProfile) {
            return ['type' => 'Teacher', 'profile' => $this->user->teacherProfile];
        }

        if ($this->user->parentProfile) {
            return ['type' => 'Parent', 'profile' => $this->user->parentProfile];
        }

        if ($this->user->clientProfile) {
            return ['type' => 'Client', 'profile' => $this->user->clientProfile];
        }

        if ($this->user->childProfile) {
            return ['type' => 'Student', 'profile' => $this->user->childProfile];
        }

        return null;
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
    <x-header title="User: {{ $user->name }}" separator>
        <x-slot:middle class="!justify-end">
            <!-- Status Badge -->
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getStatusColor($user->status) }}">
                {{ ucfirst($user->status) }}
            </span>

            @if($user->id === Auth::id())
                <span class="inline-flex items-center px-3 py-1 ml-2 text-sm font-medium text-blue-800 bg-blue-100 rounded-full">
                    <x-icon name="o-user" class="w-4 h-4 mr-1" />
                    You
                </span>
            @endif
        </x-slot:middle>

        <x-slot:actions>
            <!-- Action buttons based on status -->
            @if($user->status !== 'active')
                <x-button
                    label="Activate"
                    icon="o-check"
                    wire:click="activateUser"
                    class="btn-success"
                    wire:confirm="Are you sure you want to activate this user?"
                />
            @endif

            @if($user->status !== 'inactive' && $user->id !== Auth::id())
                <x-button
                    label="Deactivate"
                    icon="o-x-mark"
                    wire:click="deactivateUser"
                    class="btn-error"
                    wire:confirm="Are you sure you want to deactivate this user?"
                />
            @endif

            @if($user->status !== 'suspended' && $user->id !== Auth::id())
                <x-button
                    label="Suspend"
                    icon="o-shield-exclamation"
                    wire:click="suspendUser"
                    class="btn-warning"
                    wire:confirm="Are you sure you want to suspend this user?"
                />
            @endif

            <x-button
                label="Edit"
                icon="o-pencil"
                link="{{ route('admin.users.edit', $user->id) }}"
                class="btn-primary"
            />

            <x-button
                label="Back to List"
                icon="o-arrow-left"
                link="{{ route('admin.users.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - User Details -->
        <div class="space-y-6 lg:col-span-2">
            <!-- User Information -->
            <x-card title="User Information">
                <div class="flex items-start space-x-6">
                    <!-- Avatar -->
                    <div class="flex-shrink-0">
                        <div class="avatar">
                            <div class="w-24 h-24 rounded-full">
                                <img src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}" />
                            </div>
                        </div>
                    </div>

                    <!-- User Details -->
                    <div class="grid flex-1 grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Full Name</div>
                            <div class="text-lg font-semibold">{{ $user->name }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Email Address</div>
                            <div class="font-mono">{{ $user->email }}</div>
                            @if($user->email_verified_at)
                                <div class="mt-1 text-xs text-green-600">
                                    <x-icon name="o-check-circle" class="inline w-3 h-3 mr-1" />
                                    Verified {{ $user->email_verified_at->diffForHumans() }}
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
                            <div class="font-mono">{{ $user->phone ?: 'Not provided' }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Status</div>
                            <div>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($user->status) }}">
                                    {{ ucfirst($user->status) }}
                                </span>
                            </div>
                        </div>

                        @if($user->address)
                            <div class="md:col-span-2">
                                <div class="text-sm font-medium text-gray-500">Address</div>
                                <div class="p-3 rounded-md bg-gray-50">{{ $user->address }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Roles Information -->
            <x-card title="Roles & Permissions">
                @if($user->roles->count() > 0)
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        @foreach($user->roles as $role)
                            <div class="p-4 border rounded-lg {{ $this->getRoleColor($role->name) }} border-opacity-20">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-semibold">{{ ucfirst($role->name) }}</div>
                                        <div class="text-sm opacity-75">
                                            {{ match($role->name) {
                                                'admin' => 'Full system access and user management',
                                                'teacher' => 'Manage classes, students, and curriculum',
                                                'parent' => 'View children\'s progress and communicate with teachers',
                                                'student' => 'Access learning materials and submit assignments',
                                                default => 'User role'
                                            } }}
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getRoleColor($role->name) }}">
                                        {{ ucfirst($role->name) }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-shield-exclamation" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No roles assigned</div>
                        <x-button
                            label="Assign Roles"
                            icon="o-plus"
                            link="{{ route('admin.users.edit', $user->id) }}"
                            class="mt-2 btn-primary btn-sm"
                        />
                    </div>
                @endif
            </x-card>

            <!-- Profile Information -->
            @if($this->userProfile)
                <x-card title="{{ $this->userProfile['type'] }} Profile">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        @if($this->userProfile['type'] === 'Student' && $user->programEnrollments->count() > 0)
                            <div class="md:col-span-2">
                                <div class="mb-3 text-sm font-medium text-gray-500">Program Enrollments</div>
                                <div class="space-y-3">
                                    @foreach($user->programEnrollments as $enrollment)
                                        <div class="p-3 border rounded-md bg-gray-50">
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <div class="font-medium">{{ $enrollment->curriculum ? $enrollment->curriculum->name : 'Unknown Curriculum' }}</div>
                                                    <div class="text-sm text-gray-500">{{ $enrollment->academicYear ? $enrollment->academicYear->name : 'Unknown Year' }}</div>
                                                </div>
                                                <x-button
                                                    label="View"
                                                    icon="o-eye"
                                                    link="{{ route('admin.enrollments.show', $enrollment->id) }}"
                                                    class="btn-ghost btn-xs"
                                                />
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if($this->userProfile['type'] === 'Parent' && $user->children->count() > 0)
                            <div class="md:col-span-2">
                                <div class="mb-3 text-sm font-medium text-gray-500">Children</div>
                                <div class="space-y-3">
                                    @foreach($user->children as $child)
                                        <div class="p-3 border rounded-md bg-gray-50">
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <div class="font-medium">{{ $child->full_name }}</div>
                                                    <div class="text-sm text-gray-500">{{ $child->date_of_birth ? $child->date_of_birth->format('M d, Y') : 'DOB not set' }}</div>
                                                </div>
                                                <x-button
                                                    label="View"
                                                    icon="o-eye"
                                                    link="{{ route('admin.students.show', $child->id) }}"
                                                    class="btn-ghost btn-xs"
                                                />
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif

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
            @if($user->id !== Auth::id())
                <x-card title="Quick Actions">
                    <div class="space-y-3">
                        @if($user->status !== 'active')
                            <x-button
                                label="Activate User"
                                icon="o-check"
                                wire:click="activateUser"
                                class="w-full btn-success"
                                wire:confirm="Are you sure you want to activate this user?"
                            />
                        @endif

                        @if($user->status !== 'inactive')
                            <x-button
                                label="Deactivate User"
                                icon="o-x-mark"
                                wire:click="deactivateUser"
                                class="w-full btn-error"
                                wire:confirm="Are you sure you want to deactivate this user?"
                            />
                        @endif

                        @if($user->status !== 'suspended')
                            <x-button
                                label="Suspend User"
                                icon="o-shield-exclamation"
                                wire:click="suspendUser"
                                class="w-full btn-warning"
                                wire:confirm="Are you sure you want to suspend this user?"
                            />
                        @endif

                        <x-button
                            label="Edit User"
                            icon="o-pencil"
                            link="{{ route('admin.users.edit', $user->id) }}"
                            class="w-full btn-outline"
                        />
                    </div>
                </x-card>
            @endif

            <!-- System Information -->
            <x-card title="System Information">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">User ID</div>
                        <div class="font-mono text-xs">{{ $user->id }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Created</div>
                        <div>{{ $this->formatDate($user->created_at) }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Updated</div>
                        <div>{{ $this->formatDate($user->updated_at) }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Login</div>
                        <div>{{ $this->formatDate($user->last_login_at) }}</div>
                        @if($user->last_login_ip)
                            <div class="font-mono text-xs text-gray-500">{{ $user->last_login_ip }}</div>
                        @endif
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Email Verification</div>
                        <div>
                            @if($user->email_verified_at)
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
                    @if($user->isStudent() && $user->programEnrollments->count() > 0)
                        <x-button
                            label="View Enrollments"
                            icon="o-academic-cap"
                            link="{{ route('admin.enrollments.index', ['student' => $user->id]) }}"
                            class="justify-start w-full btn-ghost btn-sm"
                        />
                    @endif

                    @if($user->isParent() && $user->children->count() > 0)
                        <x-button
                            label="View Children"
                            icon="o-users"
                            link="{{ route('admin.students.index', ['parent' => $user->id]) }}"
                            class="justify-start w-full btn-ghost btn-sm"
                        />
                    @endif

                    <x-button
                        label="View All Users"
                        icon="o-users"
                        link="{{ route('admin.users.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />

                    <x-button
                        label="Activity Logs"
                        icon="o-document-text"
                        link="{{ route('admin.activity-logs.index', ['user' => $user->id]) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                </div>
            </x-card>
        </div>
    </div>
</div>
