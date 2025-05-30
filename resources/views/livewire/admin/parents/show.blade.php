<?php

use App\Models\ParentProfile;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Parent Details')] class extends Component {
    use Toast;

    public ParentProfile $parent;
    public ?User $user = null;
    public array $childProfiles = [];
    public bool $showDeleteChildModal = false;
    public ?int $childToDelete = null;

    // Stats
    public int $totalChildren = 0;
    public array $recentActivities = [];

    public function mount(ParentProfile $parent): void
    {
        $this->parent = $parent;
        $this->user = $parent->user;

        // Check if the user relationship is loaded
        if (!$this->user) {
            // Attempt to load user manually if relationship is not loaded
            $this->user = User::find($parent->user_id);
        }

        $this->loadChildProfiles();
        $this->calculateStats();

        // Log activity - safely handle possible null user
        ActivityLog::log(
            Auth::id(),
            'access',
            "Viewed parent profile: " . ($this->user?->name ?? 'Unknown'),
            ParentProfile::class,
            $this->parent->id,
            ['parent_name' => $this->user?->name ?? 'Unknown']
        );
    }

    // Load child profiles
    private function loadChildProfiles(): void
    {
        $this->childProfiles = $this->parent->childProfiles()
            ->with(['class', 'section']) // Assuming these relations exist
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    // Calculate stats
    private function calculateStats(): void
    {
        $this->totalChildren = count($this->childProfiles);

        // Get recent activities
        $this->recentActivities = ActivityLog::where('loggable_type', ParentProfile::class)
            ->where('loggable_id', $this->parent->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->toArray();
    }

    // Confirm child deletion
    public function confirmDeleteChild(int $childId): void
    {
        $this->childToDelete = $childId;
        $this->showDeleteChildModal = true;
    }

    // Cancel child deletion
    public function cancelDeleteChild(): void
    {
        $this->childToDelete = null;
        $this->showDeleteChildModal = false;
    }

    // Delete a child profile
    public function deleteChild(): void
    {
        if ($this->childToDelete) {
            $childProfile = $this->parent->childProfiles()->find($this->childToDelete);

            if ($childProfile) {
                $childName = $childProfile->name;

                try {
                    // Delete child profile
                    $childProfile->delete();

                    // Log the action
                    ActivityLog::log(
                        Auth::id(),
                        'delete',
                        "Deleted child profile: $childName",
                        ParentProfile::class,
                        $this->parent->id,
                        ['child_name' => $childName, 'parent_name' => $this->user->name]
                    );

                    // Reload child profiles
                    $this->loadChildProfiles();
                    $this->calculateStats();

                    // Show toast notification
                    $this->success("Child $childName has been successfully deleted.");
                } catch (\Exception $e) {
                    $this->error("An error occurred during deletion: {$e->getMessage()}");
                }
            } else {
                $this->error("Child profile not found!");
            }
        }

        $this->showDeleteChildModal = false;
        $this->childToDelete = null;
    }

    // Toggle parent status
    public function toggleStatus(): void
    {
        if ($this->user) {
            $newStatus = $this->user->status === 'active' ? 'inactive' : 'active';
            $statusText = $newStatus === 'active' ? 'activated' : 'deactivated';
            $userName = $this->user->name ?? 'Unknown';

            $this->user->status = $newStatus;
            $this->user->save();

            // Log the action
            ActivityLog::log(
                Auth::id(),
                'update',
                "Modified status of parent {$userName}: $statusText",
                ParentProfile::class,
                $this->parent->id,
                ['old_status' => $newStatus === 'active' ? 'inactive' : 'active', 'new_status' => $newStatus]
            );

            $this->success("The status of parent {$userName} has been changed to $statusText.");
        } else {
            $this->error("Cannot update status: User record not found.");
        }
    }

    // Refresh data when child added
    #[On('childAdded')]
    public function handleChildAdded(): void
    {
        $this->loadChildProfiles();
        $this->calculateStats();
        $this->success("Child profile has been added successfully.");
    }
};?>

<div>
    <!-- Breadcrumbs -->
    <div class="mb-4 text-sm breadcrumbs">
        <ul>
            <li><a href="/admin/dashboard">Dashboard</a></li>
            <li><a href="/admin/parents">Parents</a></li>
            <li>{{ $user?->name ?? 'Parent Details' }}</li>
        </ul>
    </div>

    <!-- Page header -->
    <x-header :title="'Parent: ' . ($user?->name ?? 'No Name')" separator progress-indicator>
        <x-slot:actions>
            <x-button
                label="Add Child"
                icon="o-plus"
                wire:click="$dispatch('openModal', { component: 'admin.children.create-modal', arguments: { parentId: {{ $parent->id }} }})"
                class="btn-secondary"
                responsive
            />


        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Parent Information Card -->
        <x-card title="Parent Information" class="lg:col-span-1">
            <div class="flex flex-col items-center mb-6 text-center">
                <div class="mb-4 avatar">
                    <div class="w-24 h-24 rounded-full ring ring-primary ring-offset-base-100 ring-offset-2">
                        <img src="{{ $user?->profile_photo_url ?? 'https://ui-avatars.com/api/?name=User&color=7F9CF5&background=EBF4FF' }}" alt="{{ $user?->name ?? 'User' }}" />
                    </div>
                </div>
                <h3 class="text-xl font-bold">{{ $user?->name ?? 'N/A' }}</h3>
                <p class="text-sm opacity-70">
                    Parent since {{ $parent->created_at?->format('M d, Y') ?? 'N/A' }}
                </p>

                <div class="mt-2">
                    <x-badge
                        label="{{ match($user->status ?? 'inactive') {
                            'active' => 'Active',
                            'inactive' => 'Inactive',
                            'suspended' => 'Suspended',
                            default => 'Unknown'
                        } }}"
                        color="{{ match($user->status ?? 'inactive') {
                            'active' => 'success',
                            'inactive' => 'warning',
                            'suspended' => 'error',
                            default => 'secondary'
                        } }}"
                    />
                </div>
            </div>

            <div class="divider"></div>

            <div class="space-y-4">
                <div>
                    <h4 class="text-sm font-semibold opacity-70">Email</h4>
                    <p>{{ $user?->email ?? 'Not provided' }}</p>
                </div>

                <div>
                    <h4 class="text-sm font-semibold opacity-70">Phone</h4>
                    <p>{{ $parent->phone ?? 'Not provided' }}</p>
                </div>

                <div>
                    <h4 class="text-sm font-semibold opacity-70">Address</h4>
                    <p>{{ $parent->address ?? 'Not provided' }}</p>
                </div>

                <div>
                    <h4 class="text-sm font-semibold opacity-70">Last Login</h4>
                    <p>{{ $user && $user->last_login_at ? $user->last_login_at->format('M d, Y H:i') : 'Never' }}</p>
                </div>

                <div>
                    <h4 class="text-sm font-semibold opacity-70">Last Login IP</h4>
                    <p>{{ $user?->last_login_ip ?? 'N/A' }}</p>
                </div>
            </div>

            <div class="divider"></div>

            <div class="flex justify-between">
                <x-button
                    icon="{{ ($user?->status ?? '') === 'active' ? 'o-x-circle' : 'o-check-circle' }}"
                    wire:click="toggleStatus"
                    label="{{ ($user?->status ?? '') === 'active' ? 'Deactivate' : 'Activate' }}"
                    color="{{ ($user?->status ?? '') === 'active' ? 'warning' : 'success' }}"
                />

                <x-button
                    icon="o-trash"
                    label="Delete"
                    color="error"
                    wire:click="$dispatch('openModal', { component: 'admin.parents.delete-modal', arguments: { parentId: {{ $parent->id }} }})"
                />
            </div>
        </x-card>

        <!-- Children Profiles -->
        <x-card title="Children" class="lg:col-span-2">
            <div class="w-full mb-6 stats bg-base-200">
                <div class="stat">
                    <div class="stat-title">Total Children</div>
                    <div class="stat-value">{{ $totalChildren }}</div>
                </div>
            </div>

            @forelse($childProfiles as $child)
                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Class</th>
                                <th>Roll Number</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($childProfiles as $child)
                                <tr class="hover">
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <div class="avatar">
                                                <div class="w-10 h-10 mask mask-squircle">
                                                    <img src="https://ui-avatars.com/api/?name={{ urlencode($child['name']) }}&color=7F9CF5&background=EBF4FF" alt="{{ $child['name'] }}">
                                                </div>
                                            </div>
                                            <div>
                                                <div class="font-bold">{{ $child['name'] }}</div>
                                                <div class="text-sm opacity-70">ID: {{ $child['id'] }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        {{ $child['class']['name'] ?? 'N/A' }}
                                        @if(isset($child['section']['name']))
                                            - {{ $child['section']['name'] }}
                                        @endif
                                    </td>
                                    <td>{{ $child['roll_number'] ?? 'N/A' }}</td>
                                    <td>
                                        <x-badge
                                            label="{{ match($child['status'] ?? 'inactive') {
                                                'active' => 'Active',
                                                'inactive' => 'Inactive',
                                                default => 'Unknown'
                                            } }}"
                                            color="{{ match($child['status'] ?? 'inactive') {
                                                'active' => 'success',
                                                'inactive' => 'warning',
                                                default => 'secondary'
                                            } }}"
                                        />
                                    </td>
                                    <td class="text-right">
                                        <div class="flex justify-end gap-2">
                                            <x-button
                                                icon="o-eye"
                                                wire:click="$dispatch('openModal', { component: 'admin.students.show-modal', arguments: { studentId: {{ $child['id'] }} }})"
                                                color="secondary"
                                                size="sm"
                                                tooltip="View"
                                            />

                                            <x-button
                                                icon="o-pencil"
                                                wire:click="$dispatch('openModal', { component: 'admin.students.edit-modal', arguments: { studentId: {{ $child['id'] }} }})"
                                                color="info"
                                                size="sm"
                                                tooltip="Edit"
                                            />

                                            <x-button
                                                icon="o-trash"
                                                wire:click="confirmDeleteChild({{ $child['id'] }})"
                                                color="error"
                                                size="sm"
                                                tooltip="Delete"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                                @empty
                        <div class="py-8 text-center">
                            <div class="flex flex-col items-center justify-center gap-2">
                                <x-icon name="o-user-group" class="w-16 h-16 text-gray-400" />
                                <h3 class="text-lg font-semibold text-gray-600">No children found</h3>
                                <p class="text-gray-500">This parent does not have any children profiles yet</p>
                                <x-button
                                    label="Add Child"
                                    icon="o-plus"
                                    wire:click="$dispatch('openModal', { component: 'admin.children.create-modal', arguments: { parentId: {{ $parent->id }} }})"
                                    class="mt-2"
                                />
                            </div>
                        </div>
                    @endforelse
        </x-card>

        <!-- Recent Activities -->
        <x-card title="Recent Activities" class="lg:col-span-3">
            @if(count($recentActivities) > 0)
                <div class="overflow-x-auto">
                    <table class="table w-full">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>User</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentActivities as $activity)
                                <tr class="hover">
                                    <td>{{ \Carbon\Carbon::parse($activity['created_at'])->format('M d, Y H:i') }}</td>
                                    <td>
                                        <x-badge
                                            label="{{ ucfirst($activity['action'] ?? 'Unknown') }}"
                                            color="{{ match($activity['action'] ?? '') {
                                                'create' => 'success',
                                                'update' => 'info',
                                                'delete' => 'error',
                                                'access' => 'secondary',
                                                default => 'ghost'
                                            } }}"
                                        />
                                    </td>
                                    <td>{{ $activity['description'] }}</td>
                                    <td>
                                        @php
                                            $user = \App\Models\User::find($activity['user_id']);
                                            $userName = $user ? $user->name : 'Unknown';
                                        @endphp
                                        {{ $userName }}
                                    </td>
                                    <td>{{ $activity['properties']['ip'] ?? 'N/A' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 text-center">
                    <x-button
                        label="View All Activities"
                        icon="o-clock"
                        wire:click="$dispatch('openModal', { component: 'admin.activities.index-modal', arguments: { type: 'parent_profile', id: {{ $parent->id }} }})"
                    />
                </div>
            @else
                <div class="py-8 text-center">
                    <div class="flex flex-col items-center justify-center gap-2">
                        <x-icon name="o-clock" class="w-16 h-16 text-gray-400" />
                        <h3 class="text-lg font-semibold text-gray-600">No recent activities</h3>
                        <p class="text-gray-500">No activities have been recorded for this parent yet</p>
                    </div>
                </div>
            @endif
        </x-card>
    </div>

    <!-- Delete child confirmation modal -->
    <x-modal wire:model="showDeleteChildModal" title="Delete Confirmation">
        <div class="p-4">
            <div class="flex items-center gap-4">
                <div class="p-3 rounded-full bg-error/20">
                    <x-icon name="o-exclamation-triangle" class="w-8 h-8 text-error" />
                </div>
                <div>
                    <h3 class="text-lg font-semibold">Are you sure you want to delete this child profile?</h3>
                    <p class="text-gray-600">This action is irreversible and will remove all associated data.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancelDeleteChild" />
            <x-button label="Delete" icon="o-trash" wire:click="deleteChild" color="error" />
        </x-slot:actions>
    </x-modal>
</div>
