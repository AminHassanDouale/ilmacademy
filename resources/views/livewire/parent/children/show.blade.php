<?php

use App\Models\ChildProfile;
use App\Models\ProgramEnrollment;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public ChildProfile $childProfile;

    public function mount(ChildProfile $childProfile): void
    {
        // Ensure parent can only view their own children
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile || $childProfile->parent_profile_id !== $parentProfile->id) {
            $this->error('You do not have permission to view this child profile.');
            $this->redirect(route('parent.children.index'));
            return;
        }

        $this->childProfile = $childProfile->load(['user', 'programEnrollments.program']);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed child profile: {$this->childProfile->user?->name}",
            ChildProfile::class,
            $this->childProfile->id,
            ['ip' => request()->ip()]
        );
    }

    #[Title('Child Profile')]
    public function title(): string
    {
        return 'Child Profile: ' . ($this->childProfile->user?->name ?? 'Unknown');
    }

    // Get active enrollments
    public function activeEnrollments()
    {
        return $this->childProfile->programEnrollments()
            ->where('status', 'active')
            ->with('program')
            ->get();
    }

    // Get completed enrollments
    public function completedEnrollments()
    {
        return $this->childProfile->programEnrollments()
            ->where('status', 'completed')
            ->with('program')
            ->get();
    }

    // Get upcoming enrollments
    public function upcomingEnrollments()
    {
        return $this->childProfile->programEnrollments()
            ->where('status', 'pending')
            ->with('program')
            ->get();
    }

    public function with(): array
    {
        return [
            'activeEnrollments' => $this->activeEnrollments(),
            'completedEnrollments' => $this->completedEnrollments(),
            'upcomingEnrollments' => $this->upcomingEnrollments(),
        ];
    }
};?>

<div>
    <x-header :title="'Child Profile: ' . ($childProfile->user?->name ?? 'Unknown')" separator>
        <x-slot:actions>
            <x-button label="Back to Children" icon="o-arrow-left" link="{{ route('parent.children.index') }}" />
            <x-button
                label="Edit Profile"
                icon="o-pencil"
                wire:click="$dispatch('openModal', { component: 'parent.children.edit-modal', arguments: { childId: {{ $childProfile->id }} }})"
                class="btn-primary"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid gap-6 md:grid-cols-3">
        <!-- CHILD PROFILE CARD -->
        <div class="md:col-span-1">
            <x-card title="Profile Information" separator>
                <div class="flex flex-col items-center mb-6">
                    <div class="mb-4 avatar">
                        <div class="w-32 h-32 rounded-full">
                            @if ($childProfile->photo)
                                <img src="{{ asset('storage/' . $childProfile->photo) }}" alt="{{ $childProfile->user?->name ?? 'Child' }}">
                            @else
                                <img src="{{ $childProfile->user?->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Child&color=7F9CF5&background=EBF4FF' }}" alt="{{ $childProfile->user?->name ?? 'Child' }}">
                            @endif
                        </div>
                    </div>
                    <h2 class="text-xl font-bold">{{ $childProfile->user?->name ?? 'Unknown' }}</h2>
                    <div class="badge badge-{{ match($childProfile->gender ?? '') {
                        'male' => 'info',
                        'female' => 'secondary',
                        'other' => 'warning',
                        default => 'ghost'
                    } }} mt-2">{{ ucfirst($childProfile->gender ?? 'Not specified') }}</div>
                </div>

                <div class="space-y-4">
                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Date of Birth</h3>
                        <p>{{ $childProfile->date_of_birth?->format('d/m/Y') ?? 'Not specified' }}</p>
                    </div>

                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Age</h3>
                        <p>{{ $childProfile->date_of_birth ? $childProfile->date_of_birth->age . ' years' : 'Not available' }}</p>
                    </div>

                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Medical Information</h3>
                        <p>{{ $childProfile->medical_information ?? 'None provided' }}</p>
                    </div>

                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Special Needs</h3>
                        <p>{{ $childProfile->special_needs ?? 'None provided' }}</p>
                    </div>

                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Additional Notes</h3>
                        <p>{{ $childProfile->additional_notes ?? 'None provided' }}</p>
                    </div>

                    <div>
                        <h3 class="text-sm font-medium text-gray-500">Profile Created</h3>
                        <p>{{ $childProfile->created_at->format('d/m/Y') }}</p>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- PROGRAM ENROLLMENTS -->
        <div class="md:col-span-2">
            <!-- ACTIVE PROGRAMS -->
            <x-card title="Active Programs" separator class="mb-6">
                @if($activeEnrollments->count() > 0)
                    <div class="space-y-4">
                        @foreach($activeEnrollments as $enrollment)
                            <div class="p-4 transition-colors border rounded-lg hover:bg-base-200">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <h3 class="font-bold">{{ $enrollment->program->name }}</h3>
                                        <p class="text-sm opacity-70">{{ $enrollment->program->description }}</p>

                                        <div class="flex flex-wrap gap-2 mt-2">
                                            <x-badge label="Enrolled: {{ $enrollment->enrollment_date->format('d/m/Y') }}" />
                                            @if($enrollment->end_date)
                                                <x-badge label="Until: {{ $enrollment->end_date->format('d/m/Y') }}" />
                                            @endif
                                        </div>
                                    </div>

                                    <a href="/parent/children/{{ $childProfile->id }}/programs/{{ $enrollment->id }}" class="btn btn-sm btn-circle">
                                        <x-icon name="o-arrow-right" class="w-4 h-4" />
                                    </a>
                                </div>

                                <div class="mt-3">
                                    <div class="flex justify-between mb-1 text-xs">
                                        <span>Progress</span>
                                        <span>{{ $enrollment->progress ?? 0 }}%</span>
                                    </div>
                                    <progress class="w-full progress progress-primary" value="{{ $enrollment->progress ?? 0 }}" max="100"></progress>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-6 text-center">
                        <x-icon name="o-academic-cap" class="w-16 h-16 mx-auto text-gray-400" />
                        <h3 class="mt-2 text-lg font-semibold text-gray-600">No active programs</h3>
                        <p class="text-gray-500">Your child is not currently enrolled in any active programs</p>

                        <x-button label="Browse Available Programs" icon="o-academic-cap" link="/parent/programs" class="mt-4" />
                    </div>
                @endif
            </x-card>

            <!-- UPCOMING PROGRAMS -->
            @if($upcomingEnrollments->count() > 0)
                <x-card title="Upcoming Programs" separator class="mb-6">
                    <div class="space-y-4">
                        @foreach($upcomingEnrollments as $enrollment)
                            <div class="p-4 transition-colors border rounded-lg hover:bg-base-200">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <h3 class="font-bold">{{ $enrollment->program->name }}</h3>
                                        <p class="text-sm opacity-70">{{ $enrollment->program->description }}</p>

                                        <div class="flex flex-wrap gap-2 mt-2">
                                            <x-badge label="Starts: {{ $enrollment->start_date->format('d/m/Y') }}" color="success" />
                                            @if($enrollment->end_date)
                                                <x-badge label="Until: {{ $enrollment->end_date->format('d/m/Y') }}" />
                                            @endif
                                        </div>
                                    </div>

                                    <a href="/parent/children/{{ $childProfile->id }}/programs/{{ $enrollment->id }}" class="btn btn-sm btn-circle">
                                        <x-icon name="o-arrow-right" class="w-4 h-4" />
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif

            <!-- COMPLETED PROGRAMS -->
            @if($completedEnrollments->count() > 0)
                <x-card title="Completed Programs" separator>
                    <div class="space-y-4">
                        @foreach($completedEnrollments as $enrollment)
                            <div class="p-4 transition-colors border rounded-lg hover:bg-base-200">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <h3 class="font-bold">{{ $enrollment->program->name }}</h3>
                                        <p class="text-sm opacity-70">{{ $enrollment->program->description }}</p>

                                        <div class="flex flex-wrap gap-2 mt-2">
                                            <x-badge label="Completed: {{ $enrollment->completion_date->format('d/m/Y') }}" color="success" />
                                            @if($enrollment->grade)
                                                <x-badge label="Grade: {{ $enrollment->grade }}" color="info" />
                                            @endif
                                        </div>
                                    </div>

                                    <a href="/parent/children/{{ $childProfile->id }}/programs/{{ $enrollment->id }}" class="btn btn-sm btn-circle">
                                        <x-icon name="o-arrow-right" class="w-4 h-4" />
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif

            <!-- NO HISTORY -->
            @if($activeEnrollments->count() === 0 && $upcomingEnrollments->count() === 0 && $completedEnrollments->count() === 0)
                <x-card>
                    <div class="py-12 text-center">
                        <x-icon name="o-academic-cap" class="w-24 h-24 mx-auto text-gray-300" />
                        <h3 class="mt-4 text-xl font-semibold text-gray-600">No Program History</h3>
                        <p class="mt-2 text-gray-500">Your child hasn't been enrolled in any programs yet.</p>

                        <div class="mt-6">
                            <x-button label="Browse Available Programs" icon="o-academic-cap" link="/parent/programs" class="btn-primary" />
                        </div>
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</div>
