<?php

use App\Models\ChildProfile;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Child Details')] class extends Component {
    use Toast;

    public ChildProfile $child;
    public ?User $user = null;
    public ?User $parentUser = null;
    public array $enrollments = [];
    public array $attendances = [];
    public array $examResults = [];

    // Stats
    public int $totalEnrollments = 0;
    public int $totalAttendances = 0;
    public int $totalExams = 0;
    public array $recentActivities = [];

    public function mount(ChildProfile $child): void
    {
        $this->child = $child;
        $this->user = $child->user;

        // Check if the user relationship is loaded
        if (!$this->user && $this->child->user_id) {
            // Attempt to load user manually if relationship is not loaded
            $this->user = User::find($this->child->user_id);
        }

        // Load parent user if available
        if ($this->child->parentProfile && $this->child->parentProfile->user) {
            $this->parentUser = $this->child->parentProfile->user;
        } elseif ($this->child->parent_profile_id) {
            $parentProfile = \App\Models\ParentProfile::find($this->child->parent_profile_id);
            if ($parentProfile && $parentProfile->user_id) {
                $this->parentUser = User::find($parentProfile->user_id);
            }
        }

        $this->loadRelatedData();
        $this->calculateStats();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Viewed child profile: " . ($this->user?->name ?? 'Unknown'),
            ChildProfile::class,
            $this->child->id,
            [
                'child_name' => $this->user?->name ?? 'Unknown',
                'parent_name' => $this->parentUser?->name ?? 'Unknown'
            ]
        );
    }

    // Load related data
    private function loadRelatedData(): void
    {
        // Load program enrollments with program details
        $this->enrollments = $this->child->programEnrollments()
            ->with(['program', 'payments'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();

        // Load recent attendances
        $this->attendances = $this->child->attendances()
            ->with(['session'])
            ->orderBy('created_at', 'desc') // Changed from 'date' to 'created_at'
            ->limit(10)
            ->get()
            ->toArray();

        // Load exam results
        $this->examResults = $this->child->examResults()
            ->with(['exam'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    // Calculate stats
    private function calculateStats(): void
    {
        $this->totalEnrollments = count($this->enrollments);
        $this->totalAttendances = $this->child->attendances()->count();
        $this->totalExams = count($this->examResults);

        // Get recent activities
        $this->recentActivities = ActivityLog::where('loggable_type', ChildProfile::class)
            ->where('loggable_id', $this->child->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->toArray();
    }

    // Calculate age from date of birth
    public function getAge(): ?int
    {
        if (!$this->child->date_of_birth) {
            return null;
        }

        return $this->child->date_of_birth->age;
    }
};?>

<div>
    <!-- Breadcrumbs -->
    <div class="mb-4 text-sm breadcrumbs">
        <ul>
            <li><a href="/admin/dashboard">Dashboard</a></li>
            <li><a href="/admin/children">Children</a></li>
            <li>{{ $user?->name ?? 'Child Details' }}</li>
        </ul>
    </div>

    <!-- Page header -->
    <x-header :title="'Child: ' . ($user?->name ?? 'No Name')" separator progress-indicator>
        <x-slot:actions>
            <x-button
                label="Edit"
                icon="o-pencil"
                wire:click="$dispatch('openModal', { component: 'admin.children.edit-modal', arguments: { childId: {{ $child->id }} }})"
                class="btn-primary"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Child Information Card -->
        <x-card title="Child Information" class="lg:col-span-1">
            <div class="flex flex-col items-center mb-6 text-center">
                <div class="mb-4 avatar">
                    <div class="w-24 h-24 rounded-full ring ring-primary ring-offset-base-100 ring-offset-2">
                        @if ($child->photo)
                            <img src="{{ asset('storage/' . $child->photo) }}" alt="{{ $user?->name ?? 'Child' }}" />
                        @else
                            <img src="{{ $user?->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Child&color=7F9CF5&background=EBF4FF' }}" alt="{{ $user?->name ?? 'Child' }}" />
                        @endif
                    </div>
                </div>
                <h3 class="text-xl font-bold">{{ $user?->name ?? 'N/A' }}</h3>
                <p class="text-sm opacity-70">
                    {{ $this->getAge() ? $this->getAge() . ' years old' : 'Age not set' }}
                </p>

                <div class="mt-2">
                    <x-badge
                        label="{{ ucfirst($child->gender ?? 'Not specified') }}"
                        color="{{ match($child->gender ?? '') {
                            'male' => 'info',
                            'female' => 'secondary',
                            'other' => 'warning',
                            default => 'ghost'
                        } }}"
                    />
                </div>
            </div>

            <div class="divider"></div>

            <div class="space-y-4">
                @if($user)
                <div>
                    <h4 class="text-sm font-semibold opacity-70">Email</h4>
                    <p>{{ $user->email ?? 'Not provided' }}</p>
                </div>
                @endif

                <div>
                    <h4 class="text-sm font-semibold opacity-70">Date of Birth</h4>
                    <p>{{ $child->date_of_birth?->format('F d, Y') ?? 'Not provided' }}</p>
                </div>

                <div>
                    <h4 class="text-sm font-semibold opacity-70">Parent</h4>
                    @if($parentUser)
                        <p>
                            <a href="/admin/parents/{{ $child->parent_profile_id }}" class="link link-hover">
                                {{ $parentUser->name }}
                            </a>
                        </p>
                    @else
                        <p>No parent associated</p>
                    @endif
                </div>

                <div>
                    <h4 class="text-sm font-semibold opacity-70">Registered Since</h4>
                    <p>{{ $child->created_at?->format('M d, Y') ?? 'Unknown' }}</p>
                </div>
            </div>

            <div class="divider"></div>

            <div class="w-full stats bg-base-200">
                <div class="stat">
                    <div class="stat-title">Programs</div>
                    <div class="stat-value text-primary">{{ $totalEnrollments }}</div>
                </div>

                <div class="stat">
                    <div class="stat-title">Attendances</div>
                    <div class="stat-value text-secondary">{{ $totalAttendances }}</div>
                </div>

                <div class="stat">
                    <div class="stat-title">Exams</div>
                    <div class="stat-value text-accent">{{ $totalExams }}</div>
                </div>
            </div>
        </x-card>

        <!-- Program Enrollments -->
        <x-card title="Program Enrollments" class="lg:col-span-2">
            @forelse($enrollments as $enrollment)
                <div class="p-4 mb-4 rounded-lg bg-base-200">
                    <div class="flex flex-col gap-4 md:flex-row md:justify-between md:items-center">
                        <div>
                            <h3 class="text-lg font-bold">{{ $enrollment['program']['name'] ?? 'Unknown Program' }}</h3>
                            <p class="text-sm opacity-70">
                                Enrolled: {{ \Carbon\Carbon::parse($enrollment['created_at'])->format('M d, Y') }}
                            </p>
                            <p class="mt-1">
                                <x-badge
                                    label="{{ ucfirst($enrollment['status'] ?? 'active') }}"
                                    color="{{ match($enrollment['status'] ?? 'active') {
                                        'active' => 'success',
                                        'completed' => 'info',
                                        'suspended' => 'warning',
                                        'cancelled' => 'error',
                                        default => 'ghost'
                                    } }}"
                                />
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-xl font-bold">
                                {{ isset($enrollment['program']['price']) ? '$' . number_format($enrollment['program']['price'], 2) : 'Price not set' }}
                            </p>

                            <p class="text-sm">
                                @php
                                    $totalPaid = array_reduce($enrollment['payments'] ?? [], function($carry, $payment) {
                                        return $carry + ($payment['amount'] ?? 0);
                                    }, 0);

                                    $isPaid = isset($enrollment['program']['price']) && $totalPaid >= $enrollment['program']['price'];
                                @endphp

                                <x-badge
                                    label="{{ $isPaid ? 'Paid' : 'Payment Due' }}"
                                    color="{{ $isPaid ? 'success' : 'warning' }}"
                                />
                            </p>

                            <div class="mt-2">
                                <x-button
                                    icon="o-eye"
                                    size="sm"
                                    wire:click="$dispatch('openModal', { component: 'admin.enrollments.show-modal', arguments: { enrollmentId: {{ $enrollment['id'] }} }})"
                                    label="Details"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center">
                    <div class="flex flex-col items-center justify-center gap-2">
                        <x-icon name="o-academic-cap" class="w-16 h-16 text-gray-400" />
                        <h3 class="text-lg font-semibold text-gray-600">No program enrollments</h3>
                        <p class="text-gray-500">This child is not enrolled in any programs yet</p>
                        <x-button
                            label="Enroll in Program"
                            icon="o-plus"
                            wire:click="$dispatch('openModal', { component: 'admin.enrollments.create-modal', arguments: { childId: {{ $child->id }} }})"
                            class="mt-2"
                        />
                    </div>
                </div>
            @endforelse

            @if(count($enrollments) > 0)
                <div class="mt-4 text-center">
                    <x-button
                        label="Enroll in Another Program"
                        icon="o-plus"
                        wire:click="$dispatch('openModal', { component: 'admin.enrollments.create-modal', arguments: { childId: {{ $child->id }} }})"
                    />
                </div>
            @endif
        </x-card>

        <!-- Exam Results -->
        <x-card title="Exam Results" class="lg:col-span-3">
            @forelse($examResults as $result)
                <div class="p-4 mb-4 rounded-lg bg-base-200">
                    <div class="flex flex-col gap-4 md:flex-row md:justify-between md:items-center">
                        <div>
                            <h3 class="text-lg font-bold">{{ $result['exam']['title'] ?? 'Unknown Exam' }}</h3>
                            <p class="text-sm opacity-70">
                                Date: {{ isset($result['exam']['date']) ? \Carbon\Carbon::parse($result['exam']['date'])->format('M d, Y') : 'Date not set' }}
                            </p>
                            <p class="mt-1">
                                Subject: {{ $result['exam']['subject'] ?? 'Not specified' }}
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-2xl font-bold">
                                {{ $result['score'] ?? 'N/A' }}
                                @if(isset($result['exam']['total_marks']) && $result['exam']['total_marks'] > 0)
                                    / {{ $result['exam']['total_marks'] }}
                                @endif
                            </p>

                            @if(isset($result['score']) && isset($result['exam']['total_marks']) && $result['exam']['total_marks'] > 0)
                                @php
                                    $percentage = ($result['score'] / $result['exam']['total_marks']) * 100;
                                    $grade = '';
                                    $color = '';

                                    if ($percentage >= 90) {
                                        $grade = 'A';
                                        $color = 'success';
                                    } elseif ($percentage >= 80) {
                                        $grade = 'B';
                                        $color = 'success';
                                    } elseif ($percentage >= 70) {
                                        $grade = 'C';
                                        $color = 'info';
                                    } elseif ($percentage >= 60) {
                                        $grade = 'D';
                                        $color = 'warning';
                                    } else {
                                        $grade = 'F';
                                        $color = 'error';
                                    }
                                @endphp

                                <p class="text-sm">
                                    <x-badge
                                        label="Grade: {{ $grade }}"
                                        color="{{ $color }}"
                                    />
                                    <span class="ml-2">{{ number_format($percentage, 1) }}%</span>
                                </p>
                            @endif

                            <div class="mt-2">
                                <x-button
                                    icon="o-eye"
                                    size="sm"
                                    wire:click="$dispatch('openModal', { component: 'admin.exams.result-modal', arguments: { resultId: {{ $result['id'] }} }})"
                                    label="Details"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center">
                    <div class="flex flex-col items-center justify-center gap-2">
                        <x-icon name="o-document-chart-bar" class="w-16 h-16 text-gray-400" />
                        <h3 class="text-lg font-semibold text-gray-600">No exam results</h3>
                        <p class="text-gray-500">This child has not taken any exams yet</p>
                    </div>
                </div>
            @endforelse
        </x-card>

        <!-- Recent Attendances -->
        <x-card title="Recent Attendances" class="lg:col-span-3">
            @forelse($attendances as $attendance)
                <div class="mb-2 p-3 {{ $attendance['status'] === 'present' ? 'bg-success/10' : ($attendance['status'] === 'absent' ? 'bg-error/10' : 'bg-warning/10') }} rounded-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="font-bold">{{ $attendance['session']['title'] ?? 'Session' }}</h3>
                            <p class="text-sm opacity-70">
                                {{ isset($attendance['attendance_date']) ? \Carbon\Carbon::parse($attendance['attendance_date'])->format('d/m/Y') : \Carbon\Carbon::parse($attendance['created_at'])->format('d/m/Y') }}
                                @if(isset($attendance['session']['start_time']) && isset($attendance['session']['end_time']))
                                    | {{ \Carbon\Carbon::parse($attendance['session']['start_time'])->format('h:i A') }} -
                                    {{ \Carbon\Carbon::parse($attendance['session']['end_time'])->format('h:i A') }}
                                @endif
                            </p>
                        </div>

                        <div>
                            <x-badge
                                label="{{ ucfirst($attendance['status'] ?? 'unknown') }}"
                                color="{{ match($attendance['status'] ?? '') {
                                    'present' => 'success',
                                    'absent' => 'error',
                                    'late' => 'warning',
                                    'excused' => 'info',
                                    default => 'ghost'
                                } }}"
                            />

                            @if($attendance['remarks'])


                                <x-button label="Bottom" tooltip-bottom="{{ $attendance['remarks'] }}" />

                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center">
                    <div class="flex flex-col items-center justify-center gap-2">
                        <x-icon name="o-calendar" class="w-16 h-16 text-gray-400" />
                        <h3 class="text-lg font-semibold text-gray-600">No attendance records</h3>
                        <p class="text-gray-500">This child does not have any attendance records yet</p>
                    </div>
                </div>
            @endforelse

            @if(count($attendances) > 0)
                <div class="mt-4 text-center">
                    <x-button
                        label="View All Attendances"
                        icon="o-calendar"
                        wire:click="$dispatch('openModal', { component: 'admin.attendances.list-modal', arguments: { childId: {{ $child->id }} }})"
                    />
                </div>
            @endif
        </x-card>

        <!-- Recent Activities -->
        <x-card title="Recent Activities" class="lg:col-span-3">
            @forelse($recentActivities as $activity)
                <div class="p-3 mb-2 rounded-lg bg-base-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium">
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
                                <span class="ml-2">{{ $activity['description'] }}</span>
                            </p>
                            <p class="text-sm opacity-70">
                                {{ \Carbon\Carbon::parse($activity['created_at'])->format('M d, Y H:i') }}
                                @php
                                    $user = \App\Models\User::find($activity['user_id']);
                                    $userName = $user ? $user->name : 'Unknown';
                                @endphp
                                | By: {{ $userName }}
                            </p>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center">
                    <div class="flex flex-col items-center justify-center gap-2">
                        <x-icon name="o-clock" class="w-16 h-16 text-gray-400" />
                        <h3 class="text-lg font-semibold text-gray-600">No recent activities</h3>
                        <p class="text-gray-500">No activities have been recorded for this child yet</p>
                    </div>
                </div>
            @endforelse

            @if(count($recentActivities) > 0)
                <div class="mt-4 text-center">
                    <x-button
                        label="View All Activities"
                        icon="o-clock"
                        wire:click="$dispatch('openModal', { component: 'admin.activities.index-modal', arguments: { type: 'child_profile', id: {{ $child->id }} }})"
                    />
                </div>
            @endif
        </x-card>
    </div>
</div>
