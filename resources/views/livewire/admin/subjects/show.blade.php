<?php

use App\Models\Subject;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('View Subject')] class extends Component {
    use Toast;

    public ?Subject $subject = null;

    public function mount(Subject $subject): void
    {
        // Load subject with relationships
        $this->subject = $subject->load(['curriculum', 'sessions', 'exams', 'timetableSlots'])
            ->loadCount(['sessions', 'exams', 'timetableSlots', 'subjectEnrollments']);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Viewed subject: {$subject->name} ({$subject->code})",
            Subject::class,
            $subject->id,
            ['ip' => request()->ip()]
        );
    }

    // Redirect methods
    public function redirectToEdit(): void
    {
        $this->redirect(route('admin.subjects.edit', $this->subject->id));
    }

    public function redirectToIndex(): void
    {
        $this->redirect(route('admin.subjects.index'));
    }

    // Get paginated sessions - Fixed: Order by start_time instead of date
    public function sessions()
    {
        return $this->subject->sessions()
            ->with(['teacherProfile', 'teacherProfile.user']) // Fixed relationship name
            ->orderBy('start_time') // Fixed: Use start_time instead of date
            ->paginate(5);
    }

    // Get paginated exams - Fixed: Check if date column exists
    public function exams()
    {
        return $this->subject->exams()
            ->with(['academicYear'])
            ->orderBy('created_at', 'desc') // Use created_at if date doesn't exist
            ->paginate(5);
    }

    // Get paginated enrollments
    public function enrollments()
    {
        return $this->subject->subjectEnrollments()
            ->with(['childProfile.user', 'programEnrollment.academicYear'])
            ->orderBy('created_at', 'desc')
            ->paginate(5);
    }

    public function with(): array
    {
        return [
            'sessions' => $this->sessions(),
            'exams' => $this->exams(),
            'enrollments' => $this->enrollments(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header :title="'Subject: ' . $subject->name" separator>
        <x-slot:subtitle>
            <div class="flex items-center gap-2">
                <!-- Fixed: Use inline HTML instead of x-badge -->
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 font-mono">
                    {{ $subject->code }}
                </span>
                <span class="text-sm text-gray-500">|</span>
                @if(!empty($subject->level))
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ match(strtolower($subject->level)) {
                        'beginner' => 'bg-green-100 text-green-800',
                        'intermediate' => 'bg-yellow-100 text-yellow-800',
                        'advanced' => 'bg-red-100 text-red-800',
                        default => 'bg-gray-100 text-gray-600'
                    } }}">
                        {{ $subject->level }}
                    </span>
                @endif
            </div>
        </x-slot:subtitle>

        <x-slot:actions>
            <x-button
                label="Back to Subjects"
                icon="o-arrow-left"
                wire:click="redirectToIndex"
                class="btn-ghost"
                responsive
            />

            <x-button
                label="Edit"
                icon="o-pencil"
                wire:click="redirectToEdit"
                class="btn-info"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <!-- Subject details card -->
        <div class="md:col-span-1">
            <x-card>
                <x-card.header>
                    <h3 class="text-lg font-semibold">Subject Details</h3>
                </x-card.header>

                <div class="p-4">
                    <div class="space-y-4">
                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Name</h4>
                            <p class="font-medium">{{ $subject->name }}</p>
                        </div>

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Code</h4>
                            <p class="font-mono">{{ $subject->code }}</p>
                        </div>

                        @if(!empty($subject->level))
                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Level</h4>
                            <p>{{ $subject->level }}</p>
                        </div>
                        @endif

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Curriculum</h4>
                            <p>
                                @if($subject->curriculum)
                                    <a href="{{ route('admin.curricula.show', $subject->curriculum_id) }}" class="text-blue-600 hover:text-blue-800 underline">
                                        {{ $subject->curriculum->name }}
                                    </a>
                                    @if($subject->curriculum->code)
                                        <span class="text-sm text-gray-500">({{ $subject->curriculum->code }})</span>
                                    @endif
                                @else
                                    <span class="text-gray-400 italic">Not assigned</span>
                                @endif
                            </p>
                        </div>

                        @if(!empty($subject->description))
                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Description</h4>
                            <p class="text-sm">{{ $subject->description }}</p>
                        </div>
                        @endif

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Created</h4>
                            <p class="text-sm">{{ $subject->created_at->format('M d, Y H:i') }}</p>
                        </div>

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Last Updated</h4>
                            <p class="text-sm">{{ $subject->updated_at->format('M d, Y H:i') }}</p>
                        </div>
                    </div>
                </div>

                <x-card.footer class="bg-base-200">
                    <div class="grid w-full grid-cols-3 gap-2">
                        <div class="text-center">
                            <span class="text-xl font-bold text-blue-600">{{ $subject->sessions_count }}</span>
                            <p class="text-xs text-gray-500">Sessions</p>
                        </div>

                        <div class="text-center">
                            <span class="text-xl font-bold text-orange-600">{{ $subject->exams_count }}</span>
                            <p class="text-xs text-gray-500">Exams</p>
                        </div>

                        <div class="text-center">
                            <span class="text-xl font-bold text-purple-600">{{ $subject->subject_enrollments_count }}</span>
                            <p class="text-xs text-gray-500">Enrollments</p>
                        </div>
                    </div>
                </x-card.footer>
            </x-card>

            <!-- Timetable slots card -->
            @if($subject->timetableSlots->count() > 0)
            <x-card class="mt-4">
                <x-card.header>
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Timetable Slots</h3>
                        <span class="text-sm text-gray-500">{{ $subject->timetableSlots->count() }} slots</span>
                    </div>
                </x-card.header>

                <div class="p-4">
                    <ul class="space-y-3">
                        @foreach($subject->timetableSlots as $slot)
                            <li class="pb-2 border-b last:border-b-0 last:pb-0">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="font-medium">
                                            {{ $slot->day_of_week }}
                                        </p>
                                        <p class="text-sm text-gray-500">
                                            @if($slot->start_time && $slot->end_time)
                                                {{ $slot->start_time->format('h:i A') }} - {{ $slot->end_time->format('h:i A') }}
                                            @endif
                                            @if($slot->room)
                                                | Room: {{ $slot->room->name }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </x-card>
            @endif
        </div>

        <!-- Sessions and Exams -->
        <div class="md:col-span-2">
            <!-- Sessions card -->
            <x-card>
                <x-card.header>
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Recent Sessions</h3>
                        <span class="text-sm text-gray-500">{{ $subject->sessions_count }} total</span>
                    </div>
                </x-card.header>

                <div>
                    @if(count($sessions) > 0)
                        <div class="overflow-x-auto">
                            <table class="table w-full table-zebra">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Duration</th>
                                        <th>Teacher</th>
                                        <th>Type</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($sessions as $session)
                                        <tr>
                                            <td>
                                                <div class="font-medium">{{ $session->start_time->format('M d, Y') }}</div>
                                                <div class="text-sm text-gray-500">{{ $session->start_time->format('h:i A') }}</div>
                                            </td>
                                            <td>
                                                @if($session->start_time && $session->end_time)
                                                    {{ $session->start_time->format('h:i A') }} - {{ $session->end_time->format('h:i A') }}
                                                    <div class="text-xs text-gray-500">
                                                        {{ $session->start_time->diffInMinutes($session->end_time) }} min
                                                    </div>
                                                @else
                                                    <span class="text-gray-400">Not set</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($session->teacherProfile?->user)
                                                    {{ $session->teacherProfile->user->name }}
                                                @else
                                                    <span class="text-gray-400 italic">Not assigned</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($session->type)
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                        {{ $session->type }}
                                                    </span>
                                                @else
                                                    <span class="text-gray-400 italic">No type</span>
                                                @endif
                                            </td>
                                            <td class="text-right">
                                                <div class="flex justify-end gap-2">
                                                    <button
                                                        onclick="window.open('{{ route('admin.sessions.show', $session->id) }}', '_blank')"
                                                        class="p-1 text-gray-600 bg-gray-100 rounded hover:text-gray-900 hover:bg-gray-200"
                                                        title="View"
                                                    >
                                                        👁️
                                                    </button>
                                                    <button
                                                        onclick="window.location.href='{{ route('admin.sessions.edit', $session->id) }}'"
                                                        class="p-1 text-blue-600 bg-blue-100 rounded hover:text-blue-900 hover:bg-blue-200"
                                                        title="Edit"
                                                    >
                                                        ✏️
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="p-4">
                            {{ $sessions->links() }}
                        </div>
                    @else
                        <div class="py-8 text-center">
                            <div class="text-6xl mb-4">📅</div>
                            <p class="mt-2 text-gray-500">No sessions have been scheduled for this subject yet.</p>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Exams card -->
            <x-card class="mt-4">
                <x-card.header>
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Recent Exams</h3>
                        <span class="text-sm text-gray-500">{{ $subject->exams_count }} total</span>
                    </div>
                </x-card.header>

                <div>
                    @if(count($exams) > 0)
                        <div class="overflow-x-auto">
                            <table class="table w-full table-zebra">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Date</th>
                                        <th>Academic Year</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($exams as $exam)
                                        <tr>
                                            <td class="font-medium">{{ $exam->name }}</td>
                                            <td>
                                                @if(isset($exam->date))
                                                    {{ $exam->date->format('M d, Y') }}
                                                @else
                                                    <span class="text-gray-400 italic">Not set</span>
                                                @endif
                                            </td>
                                            <td>{{ $exam->academicYear->name ?? 'Not assigned' }}</td>
                                            <td class="text-right">
                                                <div class="flex justify-end gap-2">
                                                    <button
                                                        onclick="window.open('{{ route('admin.exams.show', $exam->id) }}', '_blank')"
                                                        class="p-1 text-gray-600 bg-gray-100 rounded hover:text-gray-900 hover:bg-gray-200"
                                                        title="View"
                                                    >
                                                        👁️
                                                    </button>
                                                    <button
                                                        onclick="window.location.href='{{ route('admin.exams.edit', $exam->id) }}'"
                                                        class="p-1 text-blue-600 bg-blue-100 rounded hover:text-blue-900 hover:bg-blue-200"
                                                        title="Edit"
                                                    >
                                                        ✏️
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="p-4">
                            {{ $exams->links() }}
                        </div>
                    @else
                        <div class="py-8 text-center">
                            <div class="text-6xl mb-4">📝</div>
                            <p class="mt-2 text-gray-500">No exams have been scheduled for this subject yet.</p>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Enrollments card -->
            <x-card class="mt-4">
                <x-card.header>
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Recent Enrollments</h3>
                        <span class="text-sm text-gray-500">{{ $subject->subject_enrollments_count }} total</span>
                    </div>
                </x-card.header>

                <div>
                    @if(count($enrollments) > 0)
                        <div class="overflow-x-auto">
                            <table class="table w-full table-zebra">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Academic Year</th>
                                        <th>Enrollment Date</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($enrollments as $enrollment)
                                        <tr>
                                            <td>
                                                <div class="flex items-center space-x-3">
                                                    <div class="avatar">
                                                        <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                            <span class="text-blue-600 font-medium text-sm">
                                                                {{ substr($enrollment->childProfile->user?->name ?? 'U', 0, 1) }}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <div class="font-medium">{{ $enrollment->childProfile->user?->name ?? 'Unknown' }}</div>
                                                        <div class="text-sm text-gray-500">Student</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>{{ $enrollment->programEnrollment->academicYear->name ?? 'N/A' }}</td>
                                            <td>{{ $enrollment->created_at->format('M d, Y') }}</td>
                                            <td class="text-right">
                                                <div class="flex justify-end gap-2">
                                                    <button
                                                        onclick="window.open('{{ route('admin.subject-enrollments.show', $enrollment->id) }}', '_blank')"
                                                        class="p-1 text-gray-600 bg-gray-100 rounded hover:text-gray-900 hover:bg-gray-200"
                                                        title="View"
                                                    >
                                                        👁️
                                                    </button>
                                                    <button
                                                        onclick="window.location.href='{{ route('admin.subject-enrollments.edit', $enrollment->id) }}'"
                                                        class="p-1 text-blue-600 bg-blue-100 rounded hover:text-blue-900 hover:bg-blue-200"
                                                        title="Edit"
                                                    >
                                                        ✏️
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="p-4">
                            {{ $enrollments->links() }}
                        </div>
                    @else
                        <div class="py-8 text-center">
                            <div class="text-6xl mb-4">👥</div>
                            <p class="mt-2 text-gray-500">No students are enrolled in this subject yet.</p>
                        </div>
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</div>
