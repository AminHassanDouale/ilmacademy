<?php

use App\Models\ChildProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Child Details')] class extends Component {
    use Toast;

    // Model instance
    public ChildProfile $childProfile;

    // Activity logs
    public $activityLogs = [];

    // Mount the component
    public function mount(ChildProfile $childProfile): void
    {
        $this->childProfile = $childProfile->load([
            'parent',
            'user',
            'programEnrollments.curriculum',
            'programEnrollments.academicYear',
            'payments',
            'invoices'
        ]);

        Log::info('Child Show Component Mounted', [
            'admin_user_id' => Auth::id(),
            'viewed_child_id' => $childProfile->id,
            'viewed_child_name' => $childProfile->full_name,
            'ip' => request()->ip()
        ]);

        // Load activity logs
        $this->loadActivityLogs();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed child profile: {$childProfile->full_name}",
            ChildProfile::class,
            $childProfile->id,
            [
                'viewed_child_name' => $childProfile->full_name,
                'viewed_child_age' => $childProfile->age,
                'parent_name' => $childProfile->parent ? $childProfile->parent->name : null,
                'ip' => request()->ip()
            ]
        );
    }

    // Load activity logs for this child
    protected function loadActivityLogs(): void
    {
        try {
            $this->activityLogs = ActivityLog::with('user')
                ->where(function ($query) {
                    $query->where('loggable_type', ChildProfile::class)
                          ->where('loggable_id', $this->childProfile->id);
                })
                ->when($this->childProfile->user_id, function ($query) {
                    $query->orWhere('user_id', $this->childProfile->user_id);
                })
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            Log::info('Activity Logs Loaded', [
                'child_id' => $this->childProfile->id,
                'logs_count' => $this->activityLogs->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to Load Activity Logs', [
                'child_id' => $this->childProfile->id,
                'error' => $e->getMessage()
            ]);
            $this->activityLogs = collect();
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

    // Get gender color for display
    public function getGenderColor(string $gender): string
    {
        return match(strtolower($gender)) {
            'male' => 'bg-blue-100 text-blue-800',
            'female' => 'bg-pink-100 text-pink-800',
            'other' => 'bg-purple-100 text-purple-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Get age group color
    public function getAgeGroupColor(int $age): string
    {
        return match(true) {
            $age <= 2 => 'bg-green-100 text-green-800',
            $age <= 5 => 'bg-blue-100 text-blue-800',
            $age <= 8 => 'bg-yellow-100 text-yellow-800',
            $age <= 12 => 'bg-orange-100 text-orange-800',
            $age <= 15 => 'bg-red-100 text-red-800',
            $age <= 18 => 'bg-purple-100 text-purple-800',
            default => 'bg-gray-100 text-gray-800'
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
            'payment' => 'o-currency-dollar',
            'medical' => 'o-heart',
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
            'payment' => 'text-yellow-600',
            'medical' => 'text-red-600',
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
    <x-header title="Student: {{ $childProfile->full_name }}" separator>
        <x-slot:middle class="!justify-end">
            <!-- Age Badge -->
            @if($childProfile->age)
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getAgeGroupColor($childProfile->age) }}">
                    {{ $childProfile->age }} years old
                </span>
            @endif

            <!-- Gender Badge -->
            @if($childProfile->gender)
                <span class="inline-flex items-center px-3 py-1 ml-2 text-sm font-medium {{ $this->getGenderColor($childProfile->gender) }}">
                    {{ ucfirst($childProfile->gender) }}
                </span>
            @endif

            <!-- Special Needs Badge -->
            @if($childProfile->special_needs || $childProfile->medical_conditions || $childProfile->allergies)
                <span class="inline-flex items-center px-3 py-1 ml-2 text-sm font-medium text-red-800 bg-red-100 rounded-full">
                    <x-icon name="o-heart" class="w-4 h-4 mr-1" />
                    Special Needs
                </span>
            @endif
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Edit"
                icon="o-pencil"
                class="btn-primary"
            />

            @if($childProfile->parent)
                <x-button
                    label="View Parent"
                    icon="o-user"
                    link="{{ route('admin.parents.show', $childProfile->parent->parentProfile->id) }}"
                    class="btn-outline"
                />
            @endif

            <x-button
                label="Back to List"
                icon="o-arrow-left"
                link="{{ route('admin.children.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Child Details -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Child Information -->
            <x-card title="Student Information">
                <div class="flex items-start space-x-6">
                    <!-- Avatar -->
                    <div class="flex-shrink-0">
                        <div class="avatar">
                            <div class="w-24 h-24 bg-blue-100 rounded-full">
                                @if($childProfile->photo)
                                    <img src="{{ $childProfile->photo }}" alt="{{ $childProfile->full_name }}" />
                                @else
                                    <div class="flex items-center justify-center w-full h-full text-2xl font-bold text-blue-600">
                                        {{ $childProfile->initials }}
                                    </div>
                                @endif
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
                            @if($childProfile->date_of_birth)
                                <div class="text-sm">{{ $childProfile->date_of_birth->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $childProfile->age }} years old</div>
                            @else
                                <div class="text-sm text-gray-500">Not provided</div>
                            @endif
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Gender</div>
                            @if($childProfile->gender)
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getGenderColor($childProfile->gender) }}">
                                    {{ ucfirst($childProfile->gender) }}
                                </span>
                            @else
                                <div class="text-sm text-gray-500">Not specified</div>
                            @endif
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Email</div>
                            <div class="font-mono text-sm">{{ $childProfile->email ?: 'Not provided' }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Phone</div>
                            <div class="font-mono text-sm">{{ $childProfile->phone ?: 'Not provided' }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Emergency Contact</div>
                            @if($childProfile->emergency_contact_name)
                                <div class="text-sm">{{ $childProfile->emergency_contact_name }}</div>
                                @if($childProfile->emergency_contact_phone)
                                    <div class="font-mono text-xs text-gray-500">{{ $childProfile->emergency_contact_phone }}</div>
                                @endif
                            @else
                                <div class="text-sm text-gray-500">Not provided</div>
                            @endif
                        </div>

                        @if($childProfile->address)
                            <div class="md:col-span-2">
                                <div class="text-sm font-medium text-gray-500">Address</div>
                                <div class="p-3 rounded-md bg-gray-50">{{ $childProfile->address }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Parent Information -->
            @if($childProfile->parent)
                <x-card title="Parent Information">
                    <div class="flex items-center p-4 space-x-4 border border-green-200 rounded-lg bg-green-50">
                        <div class="avatar">
                            <div class="w-16 h-16 rounded-full">
                                <img src="{{ $childProfile->parent->profile_photo_url }}" alt="{{ $childProfile->parent->name }}" />
                            </div>
                        </div>
                        <div class="flex-1">
                            <div class="font-semibold text-green-900">{{ $childProfile->parent->name }}</div>
                            <div class="text-sm text-green-700">{{ $childProfile->parent->email }}</div>
                            @if($childProfile->parent->parentProfile && $childProfile->parent->parentProfile->phone)
                                <div class="font-mono text-sm text-green-600">{{ $childProfile->parent->parentProfile->phone }}</div>
                            @endif
                        </div>
                        <div>
                            <x-button
                                label="View Parent"
                                icon="o-eye"
                                link="{{ route('admin.parents.show', $childProfile->parent->parentProfile->id) }}"
                                class="btn-outline btn-sm"
                            />
                        </div>
                    </div>
                </x-card>
            @endif

            <!-- Medical & Special Needs -->
            @if($childProfile->medical_conditions || $childProfile->allergies || $childProfile->special_needs || $childProfile->additional_needs)
                <x-card title="Medical & Special Needs">
                    <div class="space-y-4">
                        @if($childProfile->medical_conditions)
                            <div>
                                <div class="mb-2 text-sm font-medium text-gray-500">Medical Conditions</div>
                                <div class="p-3 border border-red-200 rounded-md bg-red-50">
                                    <div class="flex items-start">
                                        <x-icon name="o-heart" class="w-5 h-5 text-red-500 mr-2 mt-0.5" />
                                        <div class="text-sm text-red-800">{{ $childProfile->medical_conditions }}</div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if($childProfile->allergies)
                            <div>
                                <div class="mb-2 text-sm font-medium text-gray-500">Allergies</div>
                                <div class="p-3 border border-yellow-200 rounded-md bg-yellow-50">
                                    <div class="flex items-start">
                                        <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-yellow-500 mr-2 mt-0.5" />
                                        <div class="text-sm text-yellow-800">{{ $childProfile->allergies }}</div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if($childProfile->special_needs)
                            <div>
                                <div class="mb-2 text-sm font-medium text-gray-500">Special Needs</div>
                                <div class="p-3 border border-blue-200 rounded-md bg-blue-50">
                                    <div class="flex items-start">
                                        <x-icon name="o-heart" class="w-5 h-5 text-blue-500 mr-2 mt-0.5" />
                                        <div class="text-sm text-blue-800">{{ $childProfile->special_needs }}</div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if($childProfile->additional_needs)
                            <div>
                                <div class="mb-2 text-sm font-medium text-gray-500">Additional Needs</div>
                                <div class="p-3 border border-purple-200 rounded-md bg-purple-50">
                                    <div class="flex items-start">
                                        <x-icon name="o-information-circle" class="w-5 h-5 text-purple-500 mr-2 mt-0.5" />
                                        <div class="text-sm text-purple-800">{{ $childProfile->additional_needs }}</div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif

            <!-- Program Enrollments -->
            <x-card title="Program Enrollments">
                @if($childProfile->programEnrollments->count() > 0)
                    <div class="space-y-3">
                        @foreach($childProfile->programEnrollments as $enrollment)
                            <div class="p-4 border border-indigo-200 rounded-lg bg-indigo-50">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-semibold text-indigo-900">
                                            {{ $enrollment->curriculum ? $enrollment->curriculum->name : 'Unknown Program' }}
                                        </div>
                                        <div class="text-sm text-indigo-700">
                                            {{ $enrollment->academicYear ? $enrollment->academicYear->name : 'Unknown Year' }}
                                        </div>
                                        @if($enrollment->status)
                                            <div class="text-xs text-indigo-600">Status: {{ ucfirst($enrollment->status) }}</div>
                                        @endif
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-indigo-800 bg-indigo-100 rounded-full">
                                            Enrolled
                                        </span>
                                        <x-button
                                            label="View"
                                            icon="o-eye"
                                            class="btn-xs btn-outline"
                                        />
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-academic-cap" class="w-12 h-12 mx-auto text-gray-300" />
                        <div class="mt-2 text-sm text-gray-500">No program enrollments</div>
                        <x-button
                            label="Enroll in Program"
                            icon="o-plus"
                            class="mt-2 btn-primary btn-sm"
                        />
                    </div>
                @endif
            </x-card>

            <!-- Notes -->
            @if($childProfile->notes)
                <x-card title="Notes">
                    <div class="p-4 rounded-md bg-gray-50">
                        {{ $childProfile->notes }}
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
            <x-card title="Quick Actions">
                <div class="space-y-3">
                    <x-button
                        label="Edit Student"
                        icon="o-pencil"
                        class="w-full btn-outline"
                    />

                    <x-button
                        label="Enroll in Program"
                        icon="o-academic-cap"
                        class="w-full btn-primary"
                    />

                    <x-button
                        label="Add Payment"
                        icon="o-currency-dollar"
                        class="w-full btn-success"
                    />

                    @if($childProfile->parent)
                        <x-button
                            label="Contact Parent"
                            icon="o-phone"
                            class="w-full btn-info"
                        />
                    @endif
                </div>
            </x-card>

            <!-- Student Statistics -->
            <x-card title="Student Statistics">
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-500">Active Enrollments</span>
                        <span class="font-semibold">{{ $childProfile->programEnrollments->count() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-500">Total Payments</span>
                        <span class="font-semibold">{{ $childProfile->payments->count() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="font-medium text-gray-500">Total Invoices</span>
                        <span class="font-semibold">{{ $childProfile->invoices->count() }}</span>
                    </div>
                    @if($childProfile->date_of_birth)
                        <div class="flex justify-between">
                            <span class="font-medium text-gray-500">Days Since Birth</span>
                            <span class="font-semibold">{{ $childProfile->date_of_birth->diffInDays(now()) }}</span>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- System Information -->
            <x-card title="System Information">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Student ID</div>
                        <div class="font-mono text-xs">{{ $childProfile->id }}</div>
                    </div>

                    @if($childProfile->user_id)
                        <div>
                            <div class="font-medium text-gray-500">User ID</div>
                            <div class="font-mono text-xs">{{ $childProfile->user_id }}</div>
                        </div>
                    @endif

                    @if($childProfile->parent_id)
                        <div>
                            <div class="font-medium text-gray-500">Parent ID</div>
                            <div class="font-mono text-xs">{{ $childProfile->parent_id }}</div>
                        </div>
                    @endif

                    <div>
                        <div class="font-medium text-gray-500">Profile Created</div>
                        <div>{{ $this->formatDate($childProfile->created_at) }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Last Updated</div>
                        <div>{{ $this->formatDate($childProfile->updated_at) }}</div>
                    </div>

                    @if($childProfile->deleted_at)
                        <div>
                            <div class="font-medium text-gray-500">Soft Deleted</div>
                            <div class="text-red-600">{{ $this->formatDate($childProfile->deleted_at) }}</div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Related Links -->
            <x-card title="Related">
                <div class="space-y-2">
                    @if($childProfile->parent)
                        <x-button
                            label="View Parent Profile"
                            icon="o-user"
                            link="{{ route('admin.parents.show', $childProfile->parent->parentProfile->id) }}"
                            class="justify-start w-full btn-ghost btn-sm"
                        />
                    @endif

                    @if($childProfile->programEnrollments->count() > 0)
                        <x-button
                            label="View Enrollments"
                            icon="o-academic-cap"
                            class="justify-start w-full btn-ghost btn-sm"
                        />
                    @endif

                    @if($childProfile->payments->count() > 0)
                        <x-button
                            label="View Payments"
                            icon="o-currency-dollar"
                            class="justify-start w-full btn-ghost btn-sm"
                        />
                    @endif

                    <x-button
                        label="View All Children"
                        icon="o-users"
                        link="{{ route('admin.children.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />

                    <x-button
                        label="Activity Logs"
                        icon="o-document-text"
                        link="{{ route('admin.activity-logs.index', ['child' => $childProfile->id]) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                </div>
            </x-card>
        </div>
    </div>
</div>
