<?php

use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\SubjectEnrollment;
use App\Models\ActivityLog;
use App\Models\ProgramEnrollment;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Subject Details')] class extends Component {
    use WithPagination;
    use Toast;

    // Subject model
    public Subject $subject;

    // Stats and counters
    public $studentCount = 0;
    public $sessionCount = 0;
    public $examCount = 0;
    public $assignmentCount = 0;

    // Teaching status
    public $isTeaching = false;
    public $hasRequested = false;

    // Tabs
    public string $activeTab = 'overview';

    // Students pagination
    public int $perPage = 10;

    public function mount(Subject $subject): void
    {
        $this->subject = $subject;

        // Load the subject with relationships
        $this->subject->load(['curriculum']);

        // Get teaching status
        $teacherProfile = Auth::user()->teacherProfile;

        if ($teacherProfile) {
            try {
                if (method_exists($teacherProfile, 'subjects')) {
                    $this->isTeaching = $teacherProfile->subjects()
                        ->where('subjects.id', $this->subject->id)
                        ->exists();
                }

                if (method_exists($teacherProfile, 'subjectRequests')) {
                    $this->hasRequested = $teacherProfile->subjectRequests()
                        ->where('subject_id', $this->subject->id)
                        ->exists();
                }
            } catch (\Exception $e) {
                // Log the error but continue
                ActivityLog::log(
                    Auth::id(),
                    'error',
                    'Error checking teaching status: ' . $e->getMessage(),
                    Subject::class,
                    $this->subject->id,
                    ['ip' => request()->ip()]
                );
            }
        }

        // Get counts
        $this->loadCounts();

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Teacher viewed subject details: ' . $this->subject->name,
            Subject::class,
            $this->subject->id,
            ['ip' => request()->ip()]
        );
    }

    // Change active tab
    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    // Request to teach this subject
    public function requestSubject()
    {
        try {
            $teacherProfile = Auth::user()->teacherProfile;

            if (!$teacherProfile) {
                $this->error('Teacher profile not found. Please complete your profile first.');
                return;
            }

            // Check if the relationship exists
            if (method_exists($teacherProfile, 'subjectRequests')) {
                // Check if already requested
                $alreadyRequested = $teacherProfile->subjectRequests()
                    ->where('subject_id', $this->subject->id)
                    ->exists();

                if ($alreadyRequested) {
                    $this->info('You have already requested to teach this subject.');
                    return;
                }

                // Add the request
                $teacherProfile->subjectRequests()->create([
                    'subject_id' => $this->subject->id,
                    'status' => 'pending'
                ]);

                // Update status
                $this->hasRequested = true;

                // Log activity
                ActivityLog::log(
                    Auth::id(),
                    'request',
                    'Teacher requested to teach subject: ' . $this->subject->name,
                    Subject::class,
                    $this->subject->id,
                    ['ip' => request()->ip()]
                );

                $this->success('Your request to teach this subject has been submitted.');
            } else {
                $this->error('Unable to process subject request. Please contact an administrator.');
            }
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    // Get subject learning outcomes
    public function learningOutcomes()
    {
        // If your subject has learning outcomes as a relationship
        if (method_exists($this->subject, 'learningOutcomes')) {
            return $this->subject->learningOutcomes;
        }

        // If learning outcomes are stored as JSON
        if (isset($this->subject->learning_outcomes) && is_string($this->subject->learning_outcomes)) {
            try {
                $outcomes = json_decode($this->subject->learning_outcomes, true);
                if (is_array($outcomes)) {
                    return $outcomes;
                }
            } catch (\Exception $e) {
                // Do nothing, return empty array below
            }
        }

        return [];
    }

    // Get enrolled students
    public function students()
    {
        try {
            // Get enrollments for this subject
            $enrollments = SubjectEnrollment::where('subject_id', $this->subject->id)
                ->with(['programEnrollment.childProfile.user'])
                ->paginate($this->perPage);

            return $enrollments;
        } catch (\Exception $e) {
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading students: ' . $e->getMessage(),
                Subject::class,
                $this->subject->id,
                ['ip' => request()->ip()]
            );

            return [];
        }
    }

    // Get sessions for this subject
    public function sessions()
    {
        if (method_exists($this->subject, 'sessions')) {
            try {
                return $this->subject->sessions()
                    ->with(['teacherProfile.user'])
                    ->orderBy('date', 'desc')
                    ->take(5)
                    ->get();
            } catch (\Exception $e) {
                ActivityLog::log(
                    Auth::id(),
                    'error',
                    'Error loading sessions: ' . $e->getMessage(),
                    Subject::class,
                    $this->subject->id,
                    ['ip' => request()->ip()]
                );
            }
        }

        return [];
    }

    // Get exams for this subject
    public function exams()
    {
        if (method_exists($this->subject, 'exams')) {
            try {
                return $this->subject->exams()
                    ->with(['teacherProfile.user'])
                    ->orderBy('date', 'desc')
                    ->take(5)
                    ->get();
            } catch (\Exception $e) {
                ActivityLog::log(
                    Auth::id(),
                    'error',
                    'Error loading exams: ' . $e->getMessage(),
                    Subject::class,
                    $this->subject->id,
                    ['ip' => request()->ip()]
                );
            }
        }

        return [];
    }

    // Load count statistics
    private function loadCounts()
    {
        try {
            // Student count
            $this->studentCount = SubjectEnrollment::where('subject_id', $this->subject->id)->count();

            // Session count
            if (method_exists($this->subject, 'sessions')) {
                $this->sessionCount = $this->subject->sessions()->count();
            }

            // Exam count
            if (method_exists($this->subject, 'exams')) {
                $this->examCount = $this->subject->exams()->count();
            }

            // Assignment count
            if (method_exists($this->subject, 'assignments')) {
                $this->assignmentCount = $this->subject->assignments()->count();
            }
        } catch (\Exception $e) {
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading counts: ' . $e->getMessage(),
                Subject::class,
                $this->subject->id,
                ['ip' => request()->ip()]
            );
        }
    }

    // Get teachers teaching this subject
    public function teachers()
    {
        try {
            $teacherProfiles = TeacherProfile::whereHas('subjects', function ($query) {
                $query->where('subjects.id', $this->subject->id);
            })->with('user')->get();

            return $teacherProfiles;
        } catch (\Exception $e) {
            ActivityLog::log(
                Auth::id(),
                'error',
                'Error loading teachers: ' . $e->getMessage(),
                Subject::class,
                $this->subject->id,
                ['ip' => request()->ip()]
            );

            return [];
        }
    }

    public function with(): array
    {
        return [
            'learningOutcomes' => $this->learningOutcomes(),
            'students' => $this->students(),
            'sessions' => $this->sessions(),
            'exams' => $this->exams(),
            'teachers' => $this->teachers(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header :title="$subject->name" separator progress-indicator>
        <x-slot:subtitle>
            {{ $subject->code }} | {{ $subject->level }} | {{ $subject->curriculum?->name ?? 'No Curriculum' }}
        </x-slot:subtitle>

        <x-slot:actions>
            @if (!$isTeaching && !$hasRequested)
                <x-button
                    label="Request to Teach"
                    icon="o-hand-raised"
                    wire:click="requestSubject"
                    class="btn-primary"
                />
            @elseif ($hasRequested)
                <x-button
                    label="Request Pending"
                    icon="o-clock"
                    class="btn-warning"
                    disabled
                />
            @else
                <x-button
                    label="Currently Teaching"
                    icon="o-academic-cap"
                    class="btn-success"
                    disabled
                />
            @endif
        </x-slot:actions>
    </x-header>

    <!-- Quick Stats -->
    <div class="grid grid-cols-2 gap-4 mb-6 md:grid-cols-4">
        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-primary">
                <x-icon name="o-users" class="w-8 h-8" />
            </div>
            <div class="stat-title">Students</div>
            <div class="stat-value">{{ $studentCount }}</div>
            <div class="stat-desc">Enrolled students</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-secondary">
                <x-icon name="o-academic-cap" class="w-8 h-8" />
            </div>
            <div class="stat-title">Sessions</div>
            <div class="stat-value">{{ $sessionCount }}</div>
            <div class="stat-desc">Total classes</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-accent">
                <x-icon name="o-document-text" class="w-8 h-8" />
            </div>
            <div class="stat-title">Exams</div>
            <div class="stat-value">{{ $examCount }}</div>
            <div class="stat-desc">Scheduled exams</div>
        </div>

        <div class="rounded-lg shadow-sm stat bg-base-200">
            <div class="stat-figure text-info">
                <x-icon name="o-clipboard-document-list" class="w-8 h-8" />
            </div>
            <div class="stat-title">Assignments</div>
            <div class="stat-value">{{ $assignmentCount }}</div>
            <div class="stat-desc">Total assignments</div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="mb-6 tabs">
        <a
            wire:click="setActiveTab('overview')"
            class="tab tab-bordered {{ $activeTab === 'overview' ? 'tab-active' }}"
        >
            Overview
        </a>
        <a
            wire:click="setActiveTab('students')"
            class="tab tab-bordered {{ $activeTab === 'students' ? 'tab-active' }}"
        >
            Students
        </a>
        <a
            wire:click="setActiveTab('sessions')"
            class="tab tab-bordered {{ $activeTab === 'sessions' ? 'tab-active' }}"
        >
            Sessions
        </a>
        <a
            wire:click="setActiveTab('exams')"
            class="tab tab-bordered {{ $activeTab === 'exams' ? 'tab-active' }}"
        >
            Exams
        </a>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Overview Tab -->
        @if ($activeTab === 'overview')
            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <!-- Subject Description -->
                <div class="md:col-span-2">
                    <x-card title="Subject Description">
                        <div class="prose-sm prose max-w-none">
                            @if ($subject->description)
                                {!! nl2br(e($subject->description)) !!}
                            @else
                                <p class="text-gray-500">No description available for this subject.</p>
                            @endif
                        </div>
                    </x-card>

                    <!-- Learning Outcomes -->
                    <x-card title="Learning Outcomes" class="mt-6">
                        @if (count($learningOutcomes) > 0)
                            <ul class="ml-6 list-disc">
                                @foreach ($learningOutcomes as $outcome)
                                    <li class="mb-2">{{ is_array($outcome) ? $outcome['text'] ?? $outcome : $outcome }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-gray-500">No learning outcomes defined for this subject.</p>
                        @endif
                    </x-card>
                </div>

                <!-- Teachers Column -->
                <div>
                    <x-card title="Teachers">
                        @forelse ($teachers as $teacher)
                            <div class="flex items-center gap-4 mb-4">
                                <div class="avatar">
                                    <div class="w-12 h-12 rounded-full">
                                        @if ($teacher->user->profile_photo_path)
                                            <img src="{{ asset('storage/' . $teacher->user->profile_photo_path) }}" alt="{{ $teacher->user->name }}" />
                                        @else
                                            <img src="{{ 'https://ui-avatars.com/api/?name=' . urlencode($teacher->user->name) . '&color=7F9CF5&background=EBF4FF' }}" alt="{{ $teacher->user->name }}" />
                                        @endif
                                    </div>
                                </div>
                                <div>
                                    <h3 class="font-medium">{{ $teacher->user->name }}</h3>
                                    <p class="text-sm text-gray-600">{{ $teacher->specialization }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-gray-500">No teachers assigned to this subject yet.</p>
                        @endforelse
                    </x-card>

                    <!-- Resources -->
                    <x-card title="Resources" class="mt-6">
                        @if (method_exists($subject, 'resources') && $subject->resources()->count() > 0)
                            <ul class="space-y-2">
                                @foreach ($subject->resources as $resource)
                                    <li>
                                        <a href="{{ route('teacher.resources.show', $resource->id) }}" class="flex items-center gap-2 hover:text-primary">
                                            <x-icon name="{{ $resource->type === 'document' ? 'o-document-text' : ($resource->type === 'video' ? 'o-video-camera' : 'o-link') }}" class="w-5 h-5" />
                                            <span>{{ $resource->title }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-gray-500">No resources available for this subject.</p>

                            @if ($isTeaching)
                                <div class="mt-4">
                                    <x-button
                                        label="Add Resource"
                                        icon="o-plus"
                                        href="{{ route('teacher.resources.create', ['subject_id' => $subject->id]) }}"
                                        class="w-full btn-sm btn-outline"
                                    />
                                </div>
                            @endif
                        @endif
                    </x-card>
                </div>
            </div>
        @endif

        <!-- Students Tab -->
        @if ($activeTab === 'students')
            <x-card title="Enrolled Students">
                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Program</th>
                                <th>Enrolled On</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($students as $enrollment)
                                <tr class="hover">
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <div class="avatar">
                                                <div class="w-10 h-10 mask mask-squircle">
                                                    @if ($enrollment->programEnrollment->childProfile->photo)
                                                        <img src="{{ asset('storage/' . $enrollment->programEnrollment->childProfile->photo) }}" alt="{{ $enrollment->programEnrollment->childProfile->user->name ?? 'Student' }}">
                                                    @else
                                                        <img src="{{ 'https://ui-avatars.com/api/?name=' . urlencode($enrollment->programEnrollment->childProfile->user->name ?? 'Student') . '&color=7F9CF5&background=EBF4FF' }}" alt="{{ $enrollment->programEnrollment->childProfile->user->name ?? 'Student' }}">
                                                    @endif
                                                </div>
                                            </div>
                                            <div>
                                                {{ $enrollment->programEnrollment->childProfile->user->name ?? 'Unknown Student' }}
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ $enrollment->programEnrollment->program->name ?? 'Unknown Program' }}</td>
                                    <td>{{ $enrollment->created_at->format('d M Y') }}</td>
                                    <td>
                                        <x-badge
                                            label="{{ ucfirst($enrollment->programEnrollment->status ?? 'active') }}"
                                            color="{{ ($enrollment->programEnrollment->status ?? 'active') === 'active' ? 'success' : 'warning' }}"
                                        />
                                    </td>
                                    <td class="text-right">
                                        <div class="flex justify-end gap-2">
                                            <x-button
                                                icon="o-eye"
                                                color="secondary"
                                                size="sm"
                                                tooltip="View Student Details"
                                                href="{{ route('teacher.students.show', $enrollment->programEnrollment->childProfile->id) }}"
                                            />

                                            @if ($isTeaching)
                                                <x-button
                                                    icon="o-chart-bar"
                                                    color="primary"
                                                    size="sm"
                                                    tooltip="View Student Progress"
                                                    href="{{ route('teacher.students.progress', [
                                                        'student' => $enrollment->programEnrollment->childProfile->id,
                                                        'subject' => $subject->id
                                                    ]) }}"
                                                />
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-8 text-center">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <x-icon name="o-users" class="w-16 h-16 text-gray-400" />
                                            <h3 class="text-lg font-semibold text-gray-600">No students enrolled</h3>
                                            <p class="text-gray-500">There are no students enrolled in this subject yet</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                @if ($students instanceof \Illuminate\Pagination\LengthAwarePaginator)
                    <div class="mt-4">
                        {{ $students->links() }}
                    </div>
                @endif
            </x-card>
        @endif

        <!-- Sessions Tab -->
        @if ($activeTab === 'sessions')
            <x-card title="Class Sessions">
                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Teacher</th>
                                <th>Topic</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sessions as $session)
                                <tr class="hover">
                                    <td>
                                        <div class="flex flex-col">
                                            <span class="font-medium">{{ $session->date->format('d M Y') }}</span>
                                            <span class="text-sm text-gray-600">{{ $session->start_time }} - {{ $session->end_time }}</span>
                                        </div>
                                    </td>
                                    <td>{{ $session->teacherProfile->user->name ?? 'Unknown Teacher' }}</td>
                                    <td>{{ $session->topic }}</td>
                                    <td>
                                        @php
                                            $now = now();
                                            $sessionStatus = 'upcoming';
                                            $statusColor = 'info';

                                            if ($session->date->isPast() && $now->format('H:i:s') > $session->end_time) {
                                                $sessionStatus = 'completed';
                                                $statusColor = 'success';
                                            } elseif ($session->date->isToday() && $now->format('H:i:s') >= $session->start_time && $now->format('H:i:s') <= $session->end_time) {
                                                $sessionStatus = 'in progress';
                                                $statusColor = 'warning';
                                            }
                                        @endphp

                                        <x-badge
                                            label="{{ ucfirst($sessionStatus) }}"
                                            color="{{ $statusColor }}"
                                        />
                                    </td>
                                    <td class="text-right">
                                        <div class="flex justify-end gap-2">
                                            <x-button
                                                icon="o-eye"
                                                color="secondary"
                                                size="sm"
                                                tooltip="View Session Details"
                                                href="{{ route('teacher.sessions.show', $session->id) }}"
                                            />

                                            @if ($isTeaching && $sessionStatus === 'upcoming')
                                                <x-button
                                                    icon="o-pencil-square"
                                                    color="primary"
                                                    size="sm"
                                                    tooltip="Edit Session"
                                                    href="{{ route('teacher.sessions.edit', $session->id) }}"
                                                />
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-8 text-center">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <x-icon name="o-calendar" class="w-16 h-16 text-gray-400" />
                                            <h3 class="text-lg font-semibold text-gray-600">No sessions scheduled</h3>
                                            <p class="text-gray-500">There are no class sessions scheduled for this subject yet</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($isTeaching)
                    <div class="flex justify-end mt-4">
                        <x-button
                            label="Schedule New Session"
                            icon="o-plus"
                            href="{{ route('teacher.sessions.create', ['subject_id' => $subject->id]) }}"
                            class="btn-primary"
                        />
                    </div>
                @endif
            </x-card>
        @endif

        <!-- Exams Tab -->
        @if ($activeTab === 'exams')
            <x-card title="Exams & Assessments">
                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($exams as $exam)
                                <tr class="hover">
                                    <td>{{ $exam->date->format('d M Y') }}</td>
                                    <td>{{ $exam->title }}</td>
                                    <td>{{ ucfirst($exam->type) }}</td>
                                    <td>{{ $exam->duration }} minutes</td>
                                    <td>
                                        @php
                                            $examStatus = 'upcoming';
                                            $statusColor = 'info';

                                            if ($exam->date->isPast()) {
                                                $examStatus = 'completed';
                                                $statusColor = 'success';
                                            } elseif ($exam->date->isToday()) {
                                                $examStatus = 'today';
                                                $statusColor = 'warning';
                                            }
                                        @endphp

                                        <x-badge
                                            label="{{ ucfirst($examStatus) }}"
                                            color="{{ $statusColor }}"
                                        />
                                    </td>
                                    <td class="text-right">
                                        <div class="flex justify-end gap-2">
                                            <x-button
                                                icon="o-eye"
                                                color="secondary"
                                                size="sm"
                                                tooltip="View Exam Details"
                                                href="{{ route('teacher.exams.show', $exam->id) }}"
                                            />

                                            @if ($isTeaching && $exam->date->isFuture())
                                                <x-button
                                                    icon="o-pencil-square"
                                                    color="primary"
                                                    size="sm"
                                                    tooltip="Edit Exam"
                                                    href="{{ route('teacher.exams.edit', $exam->id) }}"
                                                />
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-8 text-center">
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <x-icon name="o-document-text" class="w-16 h-16 text-gray-400" />
                                            <h3 class="text-lg font-semibold text-gray-600">No exams scheduled</h3>
                                            <p class="text-gray-500">There are no exams or assessments scheduled for this subject yet</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($isTeaching)
                    <div class="flex justify-end mt-4">
                        <x-button
                            label="Create New Exam"
                            icon="o-plus"
                            href="{{ route('teacher.exams.create', ['subject_id' => $subject->id]) }}"
                            class="btn-primary"
                        />
                    </div>
                @endif
            </x-card>
        @endif
    </div>
</div>
