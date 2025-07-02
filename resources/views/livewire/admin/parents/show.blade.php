<?php

use App\Models\ParentProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Parent Details')] class extends Component {
    use Toast;

    // Model instance
    public ParentProfile $parentProfile;

    // Activity logs
    public $activityLogs = [];

    // Mount the component
    public function mount(ParentProfile $parentProfile): void
    {
        $this->parentProfile = $parentProfile->load([
            'user',
            'children',
            'programEnrollments.curriculum',
            'programEnrollments.academicYear',
            'invoices'
        ]);

        Log::info('Parent Show Component Mounted', [
            'admin_user_id' => Auth::id(),
            'viewed_parent_id' => $parentProfile->id,
            'viewed_user_email' => $parentProfile->user->email,
            'ip' => request()->ip()
        ]);

        // Load activity logs
        $this->loadActivityLogs();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed parent profile: {$parentProfile->user->name} ({$parentProfile->user->email})",
            ParentProfile::class,
            $parentProfile->id,
            [
                'viewed_parent_name' => $parentProfile->user->name,
                'viewed_parent_email' => $parentProfile->user->email,
                'parent_occupation' => $parentProfile->occupation,
                'children_count' => $parentProfile->children->count(),
                'ip' => request()->ip()
            ]
        );
    }

    // Load activity logs for this parent
    protected function loadActivityLogs(): void
    {
        try {
            $this->activityLogs = ActivityLog::with('user')
                ->where(function ($query) {
                    $query->where('loggable_type', ParentProfile::class)
                          ->where('loggable_id', $this->parentProfile->id);
                })
    // Load activity logs for this parent
    protected function loadActivityLogs(): void
    {
        try {
            $this->activityLogs = ActivityLog::with('user')
                ->where(function ($query) {
                    $query->where('loggable_type', ParentProfile::class)
                          ->where('loggable_id', $this->parentProfile->id);
                })
                ->orWhere('user_id', $this->parentProfile->user_id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            Log::info('Activity Logs Loaded', [
                'parent_id' => $this->parentProfile->id,
                'logs_count' => $this->activityLogs->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to Load Activity Logs', [
                'parent_id' => $this->parentProfile->id,
                'error' => $e->getMessage()
            ]);
            $this->activityLogs = collect();
        }
    }

    // Activate parent
    public function activateParent(): void
    {
        try {
            if ($this->parentProfile->user->status === 'active') {
                $this->error('Parent is already active.');
                return;
            }

            $oldStatus = $this->parentProfile->user->status;
            $this->parentProfile->user->update(['status' => 'active']);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                "Activated parent: {$this->parentProfile->user->name} ({$this->parentProfile->user->email})",
                ParentProfile::class,
                $this->parentProfile->id,
                [
                    'old_status' => $oldStatus,
                    'new_status' => 'active',
                    'target_parent_name' => $this->parentProfile->user->name,
                    'target_parent_email' => $this->parentProfile->user->email
                ]
            );

            // Reload activity logs
            $this->loadActivityLogs();

            $this->success('Parent activated successfully.');

            Log::info('Parent Activated', [
                'admin_user_id' => Auth::id(),
                'target_parent_id' => $this->parentProfile->id,
                'old_status' => $oldStatus
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Activate Parent', [
                'parent_id' => $this->parentProfile->id,
                'error' => $e->getMessage()
            ]);
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Deactivate parent
    public function deactivateParent(): void
    {
        try {
            if ($this->parentProfile->user_id === Auth::id()) {
                $this->error('You cannot deactivate your own account.');
                return;
            }

            if ($this->parentProfile->user->status === 'inactive') {
                $this->error('Parent is already inactive.');
                return;
            }

            $oldStatus = $this->parentProfile->user->status;
            $this->parentProfile->user->update(['status' => 'inactive']);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                "Deactivated parent: {$this->parentProfile->user->name} ({$this->parentProfile->user->email})",
                ParentProfile::class,
                $this->parentProfile->id,
                [
                    'old_status' => $oldStatus,
                    'new_status' => 'inactive',
                    'target_parent_name' => $this->parentProfile->user->name,
                    'target_parent_email' => $this->parentProfile->user->email
                ]
            );

            // Reload activity logs
            $this->loadActivityLogs();

            $this->success('Parent deactivated successfully.');

            Log::info('Parent Deactivated', [
                'admin_user_id' => Auth::id(),
                'target_parent_id' => $this->parentProfile->id,
                'old_status' => $oldStatus
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Deactivate Parent', [
                'parent_id' => $this->parentProfile->id,
                'error' => $e->getMessage()
            ]);
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Suspend parent
    public function suspendParent(): void
    {
        try {
            if ($this->parentProfile->user_id === Auth::id()) {
                $this->error('You cannot suspend your own account.');
                return;
            }

            if ($this->parentProfile->user->status === 'suspended') {
                $this->error('Parent is already suspended.');
                return;
            }

            $oldStatus = $this->parentProfile->user->status;
            $this->parentProfile->user->update(['status' => 'suspended']);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                "Suspended parent: {$this->parentProfile->user->name} ({$this->parentProfile->user->email})",
                ParentProfile::class,
                $this->parentProfile->id,
                [
                    'old_status' => $oldStatus,
                    'new_status' => 'suspended',
                    'target_parent_name' => $this->parentProfile->user->name,
                    'target_parent_email' => $this->parentProfile->user->email
                ]
            );

            // Reload activity logs
            $this->loadActivityLogs();

            $this->success('Parent suspended successfully.');

            Log::info('Parent Suspended', [
                'admin_user_id' => Auth::id(),
                'target_parent_id' => $this->parentProfile->id,
                'old_status' => $oldStatus
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Suspend Parent', [
                'parent_id' => $this->parentProfile->id,
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
    <x-header title="Parent: {{ $parentProfile->user->name }}" separator>
        <x-slot:middle class="!justify-end">
            <!-- Status Badge -->
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getStatusColor($parentProfile->user->status) }}">
                {{ ucfirst($parentProfile->user->status) }}
            </span>

            @if($parentProfile->user_id === Auth::id())
                <span class="inline-flex items-center px-3 py-1 ml-2 text-sm font-medium text-blue-800 bg-blue-100 rounded-full">
                    <x-icon name="o-user" class="w-4 h-4 mr-1" />
                    You
                </span>
            @endif

            @if($parentProfile->children->count() > 0)
                <span class="inline-flex items-center px-3 py-1 ml-2 text-sm font-medium text-purple-800 bg-purple-100 rounded-full">
                    <x-icon name="o-heart" class="w-4 h-4 mr-1" />
                    {{ $parentProfile->children->count() }} {{ Str::plural('Child', $parentProfile->children->count()) }}
                </span>
            @endif
        </x-slot:middle>

        <x-slot:actions>
            <!-- Action buttons based on status -->
            @if($parentProfile->user->status !== 'active')
                <x-button
                    label="Activate"
                    icon="o-check"
                    wire:click="activateParent"
                    class="btn-success"
                    wire:confirm="Are you sure you want to activate this parent?"
                />
            @endif

            @if($parentProfile->user->status !== 'inactive' && $parentProfile->user_id !== Auth::id())
                <x-button
                    label="Deactivate"
                    icon="o-x-mark"
                    wire:click="deactivateParent"
                    class="btn-error"
                    wire:confirm="Are you sure you want to deactivate this parent?"
                />
            @endif

            @if($parentProfile->user->status !== 'suspended' && $parentProfile->user_id !== Auth::id())
                <x-button
                    label="Suspend"
                    icon="o-shield-exclamation"
                    wire:click="suspendParent"
                    class="btn-warning"
                    wire:confirm="Are you sure you want to suspend this parent?"
                />
            @endif

            <x-button
                label="Edit"
                icon="o-pencil"
                class="btn-primary"
            />

            <x-button
                label="Back to List"
                icon="o-arrow-left"
                link="{{ route('admin.parents.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Parent Details -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Parent Information -->
            <x-card title="Parent Information">
                <div class="flex items-start space-x-6">
                    <!-- Avatar -->
                    <div class="flex-shrink-0">
                        <div class="avatar">
                            <div class="w-24 h-24 rounded-full">
                                <img src="{{ $parentProfile->user->profile_photo_url }}" alt="{{ $parentProfile->user->name }}" />
                            </div>
                        </div>
                    </div>

                    <!-- Parent Details -->
                    <div class="grid flex-1 grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Full Name</div>
                            <div class="text-lg font-semibold">{{ $parentProfile->user->name }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Email Address</div>
                            <div class="font-mono">{{ $parentProfile->user->email }}</div>
                            @if($parentProfile->user->email_verified_at)
                                <div class="mt-1 text-xs text-green-600">
                                    <x-icon name="o-check-circle" class="inline w-3 h-3 mr-1" />
                                    Verified {{ $parentProfile->user->email_verified_at->diffForHumans() }}
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
                            <div class="font-mono">{{ $parentProfile->phone ?: $parentProfile->user->phone ?: 'Not provided' }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Status</div>
                            <div>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($parentProfile->user->status) }}">
                                    {{ ucfirst($parentProfile->user->status) }}
                                </span>
                            </div>
                        </div>

                        @if($parentProfile->occupation)
                            <div>
                                <div class="text-sm font-medium text-gray-500">Occupation</div>
                                <div class="text-sm">{{ $parentProfile->occupation }}</div>
                                @if($parentProfile->company)
                                    <div class="text-xs text-gray-500">{{ $parentProfile->company }}</div>
                                @endif
                            </div>
                        @endif

                        @if($parentProfile->emergency_contact)
                            <div>
                                <div class="text-sm font-medium text-gray-500">Emergency Contact</div>
                                <div class="font-mono text-sm">{{ $parentProfile->emergency_contact }}</div>
                            </div>
                        @endif

                        @if($parentProfile->address)
                            <div class="md:col-span-2">
                                <div class="text-sm font-medium text-gray-500">Address</div>
                                <div class="p-3 rounded-md bg-gray-50">{{ $parentProfile->address }}</div>
                            </div>
                        @endif

                        @if($parentProfile->notes)
                            <div class="md:col-span-2">
                                <div class="text-sm font-medium text-gray-500">Notes</div>
                                <div class="p-3 rounded-md bg-gray-50">{{ $parentProfile->notes }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Children Information -->
            <x-card title="Children">
                @if($parentProfile->children->count() > 0)
                    <div class="space-y-4">
                        @foreach($parentProfile->children as $child)
                            <div class="p-4 border border-blue-200 rounded-lg bg-blue-50">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-4">
                                        <div class="avatar">
                                            <div class="w-12 h-12 bg-blue-100 rounded-full">
                                                <div class="flex items-center justify-center w-full h-full font-semibold text-blue-600">
                                                    {{ $child->initials }}
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-blue-900">{{ $child->full_name }}</div>
                                            <div class="text-sm text-blue-700">
                                                @if($child->age)
                                                    Age: {{ $child->age }} years old
                                                @endif
                                                @if($child->date_of_birth)
                                                    ({{ $child->date_of_birth->format('M d, Y') }})
                                                @endif
                                            </div>
                                            @if($child->gender)
                                                <div class="text-xs text-blue-600">{{ ucfirst($child->gender) }}</div>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-800 bg-blue-100 rounded-full">
                                            Student
                                        </span>
                                        <x-button
                                            label="View"
                                            icon="o-eye"
                                            link="{{ route('admin.children.show', $child->id) }}"
                                            class="btn-xs btn-outline"
                                        />
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-users" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No children registered</div>
                        <x-button
                            label="Add Child"
                            icon="o-plus"
                            class="mt-2 btn-primary btn-sm"
                        />
                    </div>
                @endif
            </x-card>

            <!-- Program Enrollments Overview -->
            <x-card title="Program Enrollments">
                @if($parentProfile->programEnrollments->count() > 0)
                    <div class="space-y-3">
                        @foreach($parentProfile->programEnrollments->take(5) as $enrollment)
                            <div class="p-3 border rounded-md bg-gray-50">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-medium">{{ $enrollment->curriculum ? $enrollment->curriculum->name : 'Unknown Program' }}</div>
                                        <div class="text-sm text-gray-500">
                                            {{ $enrollment->academicYear ? $enrollment->academicYear->name : 'Unknown Year' }}
                                        </div>
                                    </div>
                                    <x-button
                                        label="View"
                                        icon="o-eye"
                                        class="btn-ghost btn-xs"
                                    />
                                </div>
                            </div>
                        @endforeach
                        @if($parentProfile->programEnrollments->count() > 5)
                            <div class="pt-2 text-center">
                                <x-button
                                    label="View All Enrollments ({{ $parentProfile->programEnrollments->count() }})"
                                    icon="o-eye"
                                    class="btn-outline btn-sm"
                                />
                            </div>
                        @endif
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-academic-cap" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No program enrollments found</div>
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
            @if($parentProfile->user_id !== Auth::id())
                <x-card title="Quick Actions">
                    <div class="space-y-3">
                        @if($parentProfile->user->status !== 'active')
                            <x-button
                                label="Activate Parent"
                                icon="o-check"
                                wire:click="activateParent"
                                class="w-full btn-success"
                                wire:confirm="Are you sure you want to activate this parent?"
                            />
                        @endif

                        @if($parentProfile->user->status !== 'inactive')
                            <x-button
                                label="Deactivate Parent"
                                icon="o-x-mark"
                                wire:click="deactivateParent"
                                class="w-full btn-error"
                                wire:confirm="Are you sure you want to deactivate this parent?"
                            />
                        @endif

                        @if($parentProfile->user->status !== 'suspended')
                            <x-button
                                label="Suspend Parent"
                                icon="o-shield-exclamation"
                                wire:click="suspendParent"
                                class="w-full btn-warning"
                                wire:confirm="Are you sure you want to suspend this parent?"
                            />
                        @endif

                        <x-button
                            label="Edit Parent"
                            icon="o-pencil"
                            class="w-full btn-outline"
                        />
                    </div>
                </x-card>
            @endif

            <!-- Family Statistics -->
            <x-card title="Family Statistics">
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-500">Children</span>
                        <span class="font-semibold">{{ $parentProfile->children->count() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-500">Active Enrollments</span>
                        <span class="font-semibold">{{ $parentProfile->programEnrollments->count() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-500">Total Invoices</span>
                        <span class="font-semibold">{{ $parentProfile->invoices->count() }}</span>
                    </div>
                    @if($parentProfile->children->count() > 0)
                        <div class="flex justify-between">
                            <span class="font-medium text-gray-500">Youngest Child</span>
                            <span class="font-semibold">
                                @php
                                    $youngest = $parentProfile->children->sortByDesc('date_of_birth')->first();
                                @endphp
                                {{ $youngest ? $youngest->age . ' years' : 'Unknown' }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="font-medium text-gray-500">Oldest Child</span>
                            <span class="font-semibold">
                                @php
                                    $oldest = $parentProfile->children->sortBy('date_of_birth')->first();
                                @endphp
                                {{ $oldest ? $oldest->age . ' years' : 'Unknown' }}
                            </span>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- System Information -->
            <x-card title="System Information">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Parent ID</div>
                        <div class="font-mono text-xs">{{ $parentProfile->id }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">User ID</div>
                        <div class="font-mono text-xs">{{ $parentProfile->user_id }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Profile Created</div>
                        <div>{{ $this->formatDate($parentProfile->created_at) }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Updated</div>
                        <div>{{ $this->formatDate($parentProfile->updated_at) }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">User Last Login</div>
                        <div>{{ $this->formatDate($parentProfile->user->last_login_at) }}</div>
                        @if($parentProfile->user->last_login_ip)
                            <div class="font-mono text-xs text-gray-500">{{ $parentProfile->user->last_login_ip }}</div>
                        @endif
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Email Verification</div>
                        <div>
                            @if($parentProfile->user->email_verified_at)
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
                    @if($parentProfile->children->count() > 0)
                        <x-button
                            label="View Children"
                            icon="o-users"
                            link="{{ route('admin.children.index', ['parent' => $parentProfile->id]) }}"
                            class="justify-start w-full btn-ghost btn-sm"
                        />
                    @endif

                    <x-button
                        label="View All Parents"
                        icon="o-users"
                        link="{{ route('admin.parents.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />

                    <x-button
                        label="Activity Logs"
                        icon="o-document-text"
                        link="{{ route('admin.activity-logs.index', ['parent' => $parentProfile->id]) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                </div>
            </x-card>
        </div>
    </div>
</div>
