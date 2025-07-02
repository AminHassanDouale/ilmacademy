<?php

use App\Models\User;
use App\Models\Session;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Session Details')] class extends Component {
    use Toast;

    // Current user and session
    public User $user;
    public $session; // Using mixed type since Session model structure is not fully defined

    // Session data
    public array $sessionData = [];
    public array $attendanceData = [];
    public array $materialsData = [];
    public array $notesData = [];

    // Tab management
    public string $activeTab = 'overview';

    public function mount($session): void
    {
        $this->user = Auth::user();

        // For now, we'll work with the session ID and mock data
        // In a real implementation, you would load the actual Session model
        $this->session = $session;
        $this->loadSessionData();

        // Check if user has access to this session
        $this->checkAccess();

        // Log activity
        ActivityLog::log(
            $this->user->id,
            'view',
            "Viewed session details: {$this->sessionData['title'] ?? 'Session #' . $session}",
            Session::class,
            $session,
            [
                'session_id' => $session,
                'session_title' => $this->sessionData['title'] ?? 'Unknown',
                'ip' => request()->ip()
            ]
        );
    }

    protected function loadSessionData(): void
    {
        // Mock session data - replace with actual database queries
        $this->sessionData = [
            'id' => $this->session,
            'title' => 'Mathematics - Algebra Fundamentals',
            'description' => 'Introduction to algebraic concepts including variables, expressions, and basic equations.',
            'session_date' => now()->addDays(2),
            'start_time' => '10:00:00',
            'end_time' => '11:30:00',
            'duration' => 90, // minutes
            'status' => 'scheduled',
            'session_type' => 'lecture',
            'location' => 'Room 205, Building A',
            'virtual_link' => 'https://meet.example.com/session-123',
            'instructor_name' => 'Dr. Smith Johnson',
            'instructor_email' => 'dr.smith@school.edu',
            'subject_name' => 'Mathematics',
            'subject_code' => 'MATH101',
            'enrollment_id' => 1,
            'program_name' => 'Advanced Mathematics Program',
            'academic_year' => '2024-2025',
            'max_attendees' => 25,
            'current_attendees' => 18,
            'requirements' => 'Notebook, calculator, textbook chapter 3',
            'objectives' => [
                'Understand the concept of variables',
                'Learn to write algebraic expressions',
                'Solve basic linear equations',
                'Apply algebraic thinking to real-world problems'
            ],
            'homework_assigned' => true,
            'homework_due_date' => now()->addDays(7),
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subHours(2)
        ];

        $this->attendanceData = [
            'status' => 'not_marked', // not_marked, present, absent, late
            'check_in_time' => null,
            'check_out_time' => null,
            'notes' => ''
        ];

        $this->materialsData = [
            [
                'id' => 1,
                'name' => 'Algebra Fundamentals - Lecture Slides',
                'type' => 'presentation',
                'file_size' => '2.5 MB',
                'uploaded_at' => now()->subDays(1),
                'download_url' => '#'
            ],
            [
                'id' => 2,
                'name' => 'Practice Worksheet',
                'type' => 'worksheet',
                'file_size' => '856 KB',
                'uploaded_at' => now()->subDays(1),
                'download_url' => '#'
            ],
            [
                'id' => 3,
                'name' => 'Homework Assignment',
                'type' => 'assignment',
                'file_size' => '1.2 MB',
                'uploaded_at' => now()->subHours(3),
                'download_url' => '#'
            ]
        ];

        $this->notesData = [
            [
                'id' => 1,
                'content' => 'Remember to review quadratic equations for next session.',
                'created_by' => 'instructor',
                'author_name' => 'Dr. Smith Johnson',
                'created_at' => now()->subHours(1),
                'is_public' => true
            ],
            [
                'id' => 2,
                'content' => 'Great participation in today\'s discussion!',
                'created_by' => 'instructor',
                'author_name' => 'Dr. Smith Johnson',
                'created_at' => now()->subHours(2),
                'is_public' => false
            ]
        ];
    }

    protected function checkAccess(): void
    {
        // In a real implementation, check if the user has access to this session
        // through their enrollments
        $hasAccess = true; // Placeholder

        if (!$hasAccess) {
            abort(403, 'You do not have permission to view this session.');
        }
    }

    // Set active tab
    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    // Navigation methods
    public function redirectToSessions(): void
    {
        $this->redirect(route('student.sessions.index'));
    }

    public function redirectToEnrollment(): void
    {
        $this->redirect(route('student.enrollments.show', $this->sessionData['enrollment_id']));
    }

    public function joinVirtualSession(): void
    {
        if ($this->sessionData['virtual_link']) {
            // Log the action
            ActivityLog::log(
                $this->user->id,
                'join',
                "Joined virtual session: {$this->sessionData['title']}",
                Session::class,
                $this->session,
                [
                    'session_id' => $this->session,
                    'virtual_link' => $this->sessionData['virtual_link'],
                    'join_time' => now()
                ]
            );

            // Redirect to virtual session
            redirect($this->sessionData['virtual_link']);
        } else {
            $this->error('Virtual session link is not available.');
        }
    }

    public function downloadMaterial(int $materialId): void
    {
        $material = collect($this->materialsData)->firstWhere('id', $materialId);

        if ($material) {
            // Log the download
            ActivityLog::log(
                $this->user->id,
                'download',
                "Downloaded material: {$material['name']} for session: {$this->sessionData['title']}",
                Session::class,
                $this->session,
                [
                    'session_id' => $this->session,
                    'material_id' => $materialId,
                    'material_name' => $material['name']
                ]
            );

            $this->success("Downloading {$material['name']}...");
            // In real implementation, trigger file download
        } else {
            $this->error('Material not found.');
        }
    }

    // Helper functions
    public function getStatusColor(string $status): string
    {
        return match($status) {
            'scheduled' => 'bg-blue-100 text-blue-800',
            'in_progress' => 'bg-yellow-100 text-yellow-800',
            'completed' => 'bg-green-100 text-green-800',
            'cancelled' => 'bg-red-100 text-red-800',
            'postponed' => 'bg-orange-100 text-orange-800',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function getAttendanceStatusColor(string $status): string
    {
        return match($status) {
            'present' => 'bg-green-100 text-green-800',
            'absent' => 'bg-red-100 text-red-800',
            'late' => 'bg-yellow-100 text-yellow-800',
            'not_marked' => 'bg-gray-100 text-gray-600',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function getMaterialIcon(string $type): string
    {
        return match($type) {
            'presentation' => 'o-presentation-chart-bar',
            'worksheet' => 'o-document',
            'assignment' => 'o-clipboard-document-check',
            'video' => 'o-video-camera',
            'audio' => 'o-musical-note',
            'document' => 'o-document-text',
            default => 'o-document'
        };
    }

    public function formatDuration(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours > 0) {
            return "{$hours}h {$mins}m";
        }
        return "{$mins}m";
    }

    public function formatDateTime($date, $time = null): string
    {
        try {
            $dateObj = is_string($date) ? \Carbon\Carbon::parse($date) : $date;
            $formatted = $dateObj->format('M d, Y');

            if ($time) {
                $formatted .= ' at ' . date('g:i A', strtotime($time));
            }

            return $formatted;
        } catch (\Exception $e) {
            return 'Date not available';
        }
    }

    public function isSessionToday(): bool
    {
        try {
            $sessionDate = is_string($this->sessionData['session_date'])
                ? \Carbon\Carbon::parse($this->sessionData['session_date'])
                : $this->sessionData['session_date'];
            return $sessionDate->isToday();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function isSessionUpcoming(): bool
    {
        try {
            $sessionDate = is_string($this->sessionData['session_date'])
                ? \Carbon\Carbon::parse($this->sessionData['session_date'])
                : $this->sessionData['session_date'];
            return $sessionDate->isFuture();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function canJoinVirtualSession(): bool
    {
        return !empty($this->sessionData['virtual_link']) &&
               in_array($this->sessionData['status'], ['scheduled', 'in_progress']) &&
               ($this->isSessionToday() || $this->sessionData['status'] === 'in_progress');
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
    <x-header title="{{ $sessionData['title'] }}" separator>
        <x-slot:subtitle>
            {{ $sessionData['subject_name'] }} • {{ $this->formatDateTime($sessionData['session_date'], $sessionData['start_time']) }}
        </x-slot:subtitle>

        <x-slot:actions>
            @if($this->canJoinVirtualSession())
                <x-button
                    label="Join Session"
                    icon="o-video-camera"
                    wire:click="joinVirtualSession"
                    class="btn-primary"
                />
            @endif
            <x-button
                label="Back to Sessions"
                icon="o-arrow-left"
                wire:click="redirectToSessions"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <!-- Session Status and Quick Info -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-4">
        <x-card>
            <div class="p-6 text-center">
                <div class="mb-3">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getStatusColor($sessionData['status']) }}">
                        {{ ucfirst(str_replace('_', ' ', $sessionData['status'])) }}
                    </span>
                </div>
                <div class="text-lg font-bold text-gray-900">{{ $this->formatDuration($sessionData['duration']) }}</div>
                <div class="text-sm text-gray-500">Duration</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="p-3 mx-auto mb-3 bg-blue-100 rounded-full w-fit">
                    <x-icon name="o-users" class="w-6 h-6 text-blue-600" />
                </div>
                <div class="text-lg font-bold text-blue-600">{{ $sessionData['current_attendees'] }}/{{ $sessionData['max_attendees'] }}</div>
                <div class="text-sm text-gray-500">Attendees</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="p-3 mx-auto mb-3 bg-green-100 rounded-full w-fit">
                    <x-icon name="o-academic-cap" class="w-6 h-6 text-green-600" />
                </div>
                <div class="text-lg font-bold text-green-600">{{ $sessionData['instructor_name'] }}</div>
                <div class="text-sm text-gray-500">Instructor</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="p-3 mx-auto mb-3 bg-orange-100 rounded-full w-fit">
                    <x-icon name="o-clock" class="w-6 h-6 text-orange-600" />
                </div>
                <div class="text-lg font-bold text-orange-600">
                    {{ date('g:i A', strtotime($sessionData['start_time'])) }}
                </div>
                <div class="text-sm text-gray-500">Start Time</div>
            </div>
        </x-card>
    </div>

    <!-- Action Buttons for Current/Upcoming Sessions -->
    @if($this->isSessionToday() || $this->isSessionUpcoming())
        <div class="flex flex-wrap gap-3 p-4 mb-6 border border-blue-200 rounded-lg bg-blue-50">
            <div class="flex items-center flex-1">
                <x-icon name="o-information-circle" class="w-5 h-5 mr-2 text-blue-600" />
                <span class="text-sm text-blue-800">
                    @if($this->isSessionToday())
                        Session is today!
                    @else
                        Upcoming session - {{ $this->formatDateTime($sessionData['session_date']) }}
                    @endif
                </span>
            </div>
            <div class="flex gap-2">
                @if($sessionData['virtual_link'] && $this->canJoinVirtualSession())
                    <x-button
                        label="Join Virtual Session"
                        icon="o-video-camera"
                        wire:click="joinVirtualSession"
                        class="btn-sm btn-primary"
                    />
                @endif
                <x-button
                    label="View Program"
                    icon="o-academic-cap"
                    wire:click="redirectToEnrollment"
                    class="btn-sm btn-outline"
                />
            </div>
        </div>
    @endif

    <!-- Tab Navigation -->
    <div class="mb-6">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px space-x-8" aria-label="Tabs">
                <button
                    wire:click="setActiveTab('overview')"
                    class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'overview' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    <x-icon name="o-information-circle" class="inline w-4 h-4 mr-1" />
                    Overview
                </button>
                <button
                    wire:click="setActiveTab('materials')"
                    class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'materials' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    <x-icon name="o-document" class="inline w-4 h-4 mr-1" />
                    Materials ({{ count($materialsData) }})
                </button>
                <button
                    wire:click="setActiveTab('attendance')"
                    class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'attendance' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    <x-icon name="o-user-check" class="inline w-4 h-4 mr-1" />
                    Attendance
                </button>
                <button
                    wire:click="setActiveTab('notes')"
                    class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'notes' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    <x-icon name="o-pencil" class="inline w-4 h-4 mr-1" />
                    Notes ({{ count($notesData) }})
                </button>
            </nav>
        </div>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Overview Tab -->
        @if($activeTab === 'overview')
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Left Column - Session Details -->
                <div class="space-y-6 lg:col-span-2">
                    <!-- Session Information -->
                    <x-card title="Session Details">
                        <div class="space-y-4">
                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Description</label>
                                <p class="text-sm text-gray-900">{{ $sessionData['description'] }}</p>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="block mb-1 text-sm font-medium text-gray-700">Date & Time</label>
                                    <p class="text-sm text-gray-900">
                                        {{ $this->formatDateTime($sessionData['session_date']) }}
                                        <br>
                                        {{ date('g:i A', strtotime($sessionData['start_time'])) }} -
                                        {{ date('g:i A', strtotime($sessionData['end_time'])) }}
                                    </p>
                                </div>

                                <div>
                                    <label class="block mb-1 text-sm font-medium text-gray-700">Session Type</label>
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-800 bg-gray-100 rounded">
                                        {{ ucfirst($sessionData['session_type']) }}
                                    </span>
                                </div>

                                <div>
                                    <label class="block mb-1 text-sm font-medium text-gray-700">Location</label>
                                    <p class="text-sm text-gray-900">{{ $sessionData['location'] }}</p>
                                </div>

                                <div>
                                    <label class="block mb-1 text-sm font-medium text-gray-700">Capacity</label>
                                    <p class="text-sm text-gray-900">
                                        {{ $sessionData['current_attendees'] }} / {{ $sessionData['max_attendees'] }} attendees
                                    </p>
                                    <div class="w-full h-2 mt-1 bg-gray-200 rounded-full">
                                        <div class="h-2 bg-blue-600 rounded-full" style="width: {{ ($sessionData['current_attendees'] / $sessionData['max_attendees']) * 100 }}%"></div>
                                    </div>
                                </div>
                            </div>

                            @if($sessionData['requirements'])
                                <div>
                                    <label class="block mb-1 text-sm font-medium text-gray-700">Requirements</label>
                                    <p class="text-sm text-gray-900">{{ $sessionData['requirements'] }}</p>
                                </div>
                            @endif
                        </div>
                    </x-card>

                    <!-- Learning Objectives -->
                    @if($sessionData['objectives'])
                        <x-card title="Learning Objectives">
                            <ul class="space-y-2">
                                @foreach($sessionData['objectives'] as $objective)
                                    <li class="flex items-start">
                                        <x-icon name="o-check-circle" class="w-5 h-5 text-green-500 mr-2 mt-0.5" />
                                        <span class="text-sm text-gray-700">{{ $objective }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </x-card>
                    @endif

                    <!-- Homework Information -->
                    @if($sessionData['homework_assigned'])
                        <x-card title="Homework Assignment" class="border-orange-200 bg-orange-50">
                            <div class="flex items-start">
                                <x-icon name="o-clipboard-document-check" class="w-6 h-6 mt-1 mr-3 text-orange-600" />
                                <div>
                                    <h4 class="mb-1 font-medium text-orange-800">Assignment Available</h4>
                                    <p class="mb-2 text-sm text-orange-700">
                                        Homework has been assigned for this session. Please check the materials section for details.
                                    </p>
                                    <p class="text-sm text-orange-600">
                                        <strong>Due:</strong> {{ $this->formatDateTime($sessionData['homework_due_date']) }}
                                    </p>
                                </div>
                            </div>
                        </x-card>
                    @endif
                </div>

                <!-- Right Column - Instructor and Program Info -->
                <div class="space-y-6">
                    <!-- Instructor Information -->
                    <x-card title="Instructor">
                        <div class="text-center">
                            <div class="mx-auto mb-4 avatar">
                                <div class="w-16 h-16 rounded-full">
                                    <img src="https://ui-avatars.com/api/?name={{ urlencode($sessionData['instructor_name']) }}&color=7F9CF5&background=EBF4FF" alt="{{ $sessionData['instructor_name'] }}" />
                                </div>
                            </div>
                            <h3 class="font-semibold text-gray-900">{{ $sessionData['instructor_name'] }}</h3>
                            <p class="mb-3 text-sm text-gray-500">{{ $sessionData['instructor_email'] }}</p>
                            <x-button
                                label="Contact Instructor"
                                icon="o-envelope"
                                href="mailto:{{ $sessionData['instructor_email'] }}"
                                class="w-full btn-sm btn-outline"
                            />
                        </div>
                    </x-card>

                    <!-- Program Information -->
                    <x-card title="Program Details">
                        <div class="space-y-3">
                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Subject</label>
                                <p class="text-sm text-gray-900">{{ $sessionData['subject_name'] }} ({{ $sessionData['subject_code'] }})</p>
                            </div>
                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Program</label>
                                <p class="text-sm text-gray-900">{{ $sessionData['program_name'] }}</p>
                            </div>
                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Academic Year</label>
                                <p class="text-sm text-gray-900">{{ $sessionData['academic_year'] }}</p>
                            </div>
                            <div class="pt-3 border-t">
                                <x-button
                                    label="View Full Program"
                                    icon="o-academic-cap"
                                    wire:click="redirectToEnrollment"
                                    class="w-full btn-sm btn-outline"
                                />
                            </div>
                        </div>
                    </x-card>

                    <!-- Virtual Session Info -->
                    @if($sessionData['virtual_link'])
                        <x-card title="Virtual Session" class="border-blue-200 bg-blue-50">
                            <div class="text-center">
                                <x-icon name="o-video-camera" class="w-12 h-12 mx-auto mb-3 text-blue-600" />
                                <h4 class="mb-2 font-medium text-blue-800">Virtual Session Available</h4>
                                <p class="mb-4 text-sm text-blue-700">
                                    This session includes a virtual component. Join when the session begins.
                                </p>
                                @if($this->canJoinVirtualSession())
                                    <x-button
                                        label="Join Now"
                                        icon="o-video-camera"
                                        wire:click="joinVirtualSession"
                                        class="w-full btn-sm btn-primary"
                                    />
                                @else
                                    <p class="text-xs text-blue-600">
                                        Virtual session will be available when the session starts
                                    </p>
                                @endif
                            </div>
                        </x-card>
                    @endif

                    <!-- Quick Actions -->
                    <x-card title="Quick Actions">
                        <div class="space-y-2">
                            <x-button
                                label="All Sessions"
                                icon="o-calendar"
                                wire:click="redirectToSessions"
                                class="w-full btn-outline btn-sm"
                            />
                            <x-button
                                label="View Materials"
                                icon="o-document"
                                wire:click="setActiveTab('materials')"
                                class="w-full btn-outline btn-sm"
                            />
                            <x-button
                                label="Check Attendance"
                                icon="o-user-check"
                                wire:click="setActiveTab('attendance')"
                                class="w-full btn-outline btn-sm"
                            />
                        </div>
                    </x-card>
                </div>
            </div>
        @endif

        <!-- Materials Tab -->
        @if($activeTab === 'materials')
            <x-card title="Session Materials">
                @if(count($materialsData) > 0)
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                        @foreach($materialsData as $material)
                            <div class="p-4 transition-shadow border border-gray-200 rounded-lg hover:shadow-md">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="flex items-start">
                                        <x-icon name="{{ $this->getMaterialIcon($material['type']) }}" class="w-8 h-8 mt-1 mr-3 text-blue-500" />
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-900">{{ $material['name'] }}</h4>
                                            <p class="mt-1 text-xs text-gray-500">
                                                {{ ucfirst($material['type']) }} • {{ $material['file_size'] }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between mb-3 text-xs text-gray-500">
                                    <span>Uploaded {{ \Carbon\Carbon::parse($material['uploaded_at'])->diffForHumans() }}</span>
                                </div>

                                <x-button
                                    label="Download"
                                    icon="o-arrow-down-tray"
                                    wire:click="downloadMaterial({{ $material['id'] }})"
                                    class="w-full btn-sm btn-primary"
                                />
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-document" class="w-16 h-16 mx-auto mb-4 text-gray-300" />
                        <h3 class="mb-2 text-lg font-medium text-gray-900">No Materials Available</h3>
                        <p class="text-gray-500">Session materials will be uploaded by the instructor.</p>
                    </div>
                @endif
            </x-card>
        @endif

        <!-- Attendance Tab -->
        @if($activeTab === 'attendance')
            <x-card title="Attendance Information">
                <div class="space-y-6">
                    <!-- Current Attendance Status -->
                    <div class="p-6 rounded-lg bg-gray-50">
                        <div class="text-center">
                            <div class="mb-4">
                                <span class="inline-flex items-center px-4 py-2 rounded-full text-lg font-medium {{ $this->getAttendanceStatusColor($attendanceData['status']) }}">
                                    {{ ucfirst(str_replace('_', ' ', $attendanceData['status'])) }}
                                </span>
                            </div>
                            <h3 class="mb-2 text-lg font-semibold text-gray-900">Attendance Status</h3>

                            @if($attendanceData['status'] === 'not_marked')
                                <p class="text-gray-600">
                                    Attendance has not been marked for this session yet.
                                </p>
                            @elseif($attendanceData['status'] === 'present')
                                <p class="mb-2 text-green-700">You attended this session!</p>
                                @if($attendanceData['check_in_time'])
                                    <p class="text-sm text-gray-600">
                                        Check-in: {{ date('g:i A', strtotime($attendanceData['check_in_time'])) }}
                                    </p>
                                @endif
                                @if($attendanceData['check_out_time'])
                                    <p class="text-sm text-gray-600">
                                        Check-out: {{ date('g:i A', strtotime($attendanceData['check_out_time'])) }}
                                    </p>
                                @endif
                            @elseif($attendanceData['status'] === 'late')
                                <p class="mb-2 text-yellow-700">You were marked as late for this session.</p>
                                @if($attendanceData['check_in_time'])
                                    <p class="text-sm text-gray-600">
                                        Late check-in: {{ date('g:i A', strtotime($attendanceData['check_in_time'])) }}
                                    </p>
                                @endif
                            @elseif($attendanceData['status'] === 'absent')
                                <p class="text-red-700">You were marked as absent for this session.</p>
                            @endif

                            @if($attendanceData['notes'])
                                <div class="p-3 mt-4 rounded-lg bg-blue-50">
                                    <p class="text-sm text-blue-800">
                                        <strong>Note:</strong> {{ $attendanceData['notes'] }}
                                    </p>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Attendance Guidelines -->
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="p-4 border border-green-200 rounded-lg bg-green-50">
                            <div class="flex items-start">
                                <x-icon name="o-check-circle" class="w-6 h-6 mt-1 mr-3 text-green-600" />
                                <div>
                                    <h4 class="mb-1 font-medium text-green-800">Present</h4>
                                    <p class="text-sm text-green-700">
                                        Arrived on time and attended the full session.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="p-4 border border-yellow-200 rounded-lg bg-yellow-50">
                            <div class="flex items-start">
                                <x-icon name="o-clock" class="w-6 h-6 mt-1 mr-3 text-yellow-600" />
                                <div>
                                    <h4 class="mb-1 font-medium text-yellow-800">Late</h4>
                                    <p class="text-sm text-yellow-700">
                                        Arrived after the session start time but attended.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="p-4 border border-red-200 rounded-lg bg-red-50">
                            <div class="flex items-start">
                                <x-icon name="o-x-circle" class="w-6 h-6 mt-1 mr-3 text-red-600" />
                                <div>
                                    <h4 class="mb-1 font-medium text-red-800">Absent</h4>
                                    <p class="text-sm text-red-700">
                                        Did not attend the session.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="p-4 border border-gray-200 rounded-lg bg-gray-50">
                            <div class="flex items-start">
                                <x-icon name="o-question-mark-circle" class="w-6 h-6 mt-1 mr-3 text-gray-600" />
                                <div>
                                    <h4 class="mb-1 font-medium text-gray-800">Not Marked</h4>
                                    <p class="text-sm text-gray-700">
                                        Attendance has not been recorded yet.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance Policy -->
                    <div class="p-4 border border-blue-200 rounded-lg bg-blue-50">
                        <div class="flex items-start">
                            <x-icon name="o-information-circle" class="w-6 h-6 mt-1 mr-3 text-blue-600" />
                            <div>
                                <h4 class="mb-2 font-medium text-blue-800">Attendance Policy</h4>
                                <ul class="space-y-1 text-sm text-blue-700">
                                    <li>• Regular attendance is required for all sessions</li>
                                    <li>• Notify your instructor in advance if you cannot attend</li>
                                    <li>• Excessive absences may affect your enrollment status</li>
                                    <li>• Make-up sessions may be available for excused absences</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </x-card>
        @endif

        <!-- Notes Tab -->
        @if($activeTab === 'notes')
            <x-card title="Session Notes">
                @if(count($notesData) > 0)
                    <div class="space-y-4">
                        @foreach($notesData as $note)
                            <div class="border border-gray-200 rounded-lg p-4 {{ $note['is_public'] ? '' : 'bg-yellow-50 border-yellow-200' }}">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="flex items-center">
                                        <div class="mr-3 avatar">
                                            <div class="w-8 h-8 rounded-full">
                                                <img src="https://ui-avatars.com/api/?name={{ urlencode($note['author_name']) }}&color=7F9CF5&background=EBF4FF" alt="{{ $note['author_name'] }}" />
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">{{ $note['author_name'] }}</div>
                                            <div class="text-xs text-gray-500">
                                                {{ ucfirst($note['created_by']) }} • {{ \Carbon\Carbon::parse($note['created_at'])->diffForHumans() }}
                                            </div>
                                        </div>
                                    </div>
                                    @if(!$note['is_public'])
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-yellow-800 bg-yellow-100 rounded-full">
                                            <x-icon name="o-lock-closed" class="w-3 h-3 mr-1" />
                                            Private
                                        </span>
                                    @endif
                                </div>

                                <div class="text-sm text-gray-700">
                                    {{ $note['content'] }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-pencil" class="w-16 h-16 mx-auto mb-4 text-gray-300" />
                        <h3 class="mb-2 text-lg font-medium text-gray-900">No Notes Available</h3>
                        <p class="text-gray-500">Session notes and announcements will appear here.</p>
                    </div>
                @endif
            </x-card>
        @endif
    </div>

    <!-- Floating Action Button for Virtual Session -->
    @if($this->canJoinVirtualSession())
        <div class="fixed z-50 bottom-6 right-6">
            <x-button
                icon="o-video-camera"
                wire:click="joinVirtualSession"
                class="shadow-lg btn-circle btn-primary btn-lg animate-pulse"
                title="Join Virtual Session"
            />
        </div>
    @endif
</div>
