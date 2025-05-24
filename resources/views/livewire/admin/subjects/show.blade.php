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

    // Get paginated sessions
    public function sessions()
    {
        return $this->subject->sessions()
            ->with(['teacher', 'room'])
            ->orderBy('date')
            ->orderBy('start_time')
            ->paginate(5);
    }

    // Get paginated exams
    public function exams()
    {
        return $this->subject->exams()
            ->with(['academicYear'])
            ->orderBy('date')
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
                <x-badge label="{{ $subject->code }}" color="info" />
                <span class="text-sm text-gray-500">|</span>
                <x-badge
                    label="{{ $subject->level }}"
                    color="{{ match(strtolower($subject->level ?? '')) {
                        'beginner' => 'success',
                        'intermediate' => 'warning',
                        'advanced' => 'error',
                        default => 'ghost'
                    } }}"
                />
            </div>
        </x-slot:subtitle>

        <x-slot:actions>
            <x-button
                label="Back to Subjects"
                icon="o-arrow-left"
                link="{{ route('admin.subjects.index') }}"
                class="btn-ghost"
                responsive
            />

            <x-button
                label="Edit"
                icon="o-pencil"
                link="{{ route('admin.subjects.edit', $subject->id) }}"
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
                            <p>{{ $subject->name }}</p>
                        </div>

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Code</h4>
                            <p>{{ $subject->code }}</p>
                        </div>

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Level</h4>
                            <p>{{ $subject->level }}</p>
                        </div>

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Curriculum</h4>
                            <p>
                                <a href="{{ route('admin.curricula.show', $subject->curriculum_id) }}" class="link link-hover text-primary">
                                    {{ $subject->curriculum->name ?? 'Not assigned' }}
                                </a>
                            </p>
                        </div>

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Created</h4>
                            <p>{{ $subject->created_at->format('M d, Y') }}</p>
                        </div>

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Last Updated</h4>
                            <p>{{ $subject->updated_at->format('M d, Y') }}</p>
                        </div>
                    </div>
                </div>

                <x-card.footer class="bg-base-200">
                    <div class="grid w-full grid-cols-3 gap-2">
                        <div class="text-center">
                            <span class="text-xl font-bold">{{ $subject->sessions_count }}</span>
                            <p class="text-xs text-gray-500">Sessions</p>
                        </div>

                        <div class="text-center">
                            <span class="text-xl font-bold">{{ $subject->exams_count }}</span>
                            <p class="text-xs text-gray-500">Exams</p>
                        </div>

                        <div class="text-center">
                            <span class="text-xl font-bold">{{ $subject->subject_enrollments_count }}</span>
                            <p class="text-xs text-gray-500">Enrollments</p>
                        </div>
                    </div>
                </x-card.footer>
            </x-card>

            <!-- Timetable slots card -->
            <x-card class="mt-4">
                <x-card.header>
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Timetable Slots</h3>
                        <x-button
                            icon="o-plus"
                            size="sm"
                            link="{{ route('admin.timetable-slots.create', ['subject_id' => $subject->id]) }}"
                            tooltip="Add Timetable Slot"
                        />
                    </div>
                </x-card.header>

                <div class="p-4">
                    @if($subject->timetableSlots->count() > 0)
                        <ul class="space-y-3">
                            @foreach($subject->timetableSlots as $slot)
                                <li class="pb-2 border-b last:border-b-0 last:pb-0">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="font-medium">
                                                {{ $slot->day_of_week }} ({{ $slot->start_time->format('h:i A') }} - {{ $slot->end_time->format('h:i A') }})
                                            </p>
                                            <p class="text-sm text-gray-500">
                                                Room: {{ $slot->room->name ?? 'Not assigned' }},
                                                Teacher: {{ $slot->teacher->name ?? 'Not assigned' }}
                                            </p>
                                        </div>
                                        <x-button
                                            icon="o-pencil"
                                            size="xs"
                                            link="{{ route('admin.timetable-slots.edit', $slot->id) }}"
                                            class="btn-ghost"
                                            tooltip="Edit Slot"
                                        />
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="py-4 text-center">
                            <p class="text-gray-500">No timetable slots set up.</p>
                            <x-button
                                label="Add Timetable Slot"
                                icon="o-plus"
                                link="{{ route('admin.timetable-slots.create', ['subject_id' => $subject->id]) }}"
                                class="mt-2 btn-sm"
                            />
                        </div>
                    @endif
                </div>
            </x-card>
        </div>

        <!-- Sessions and Exams -->
        <div class="md:col-span-2">
            <!-- Sessions card -->
            <x-card>
                <x-card.header>
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Sessions</h3>
                        <x-button
                            label="Add Session"
                            icon="o-plus"
                            link="{{ route('admin.sessions.create', ['subject_id' => $subject->id]) }}"
                            size="sm"
                        />
                    </div>
                </x-card.header>

                <div>
                    @if(count($sessions) > 0)
                        <div class="overflow-x-auto">
                            <table class="table w-full table-zebra">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Teacher</th>
                                        <th>Room</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($sessions as $session)
                                        <tr>
                                            <td>{{ $session->date->format('M d, Y') }}</td>
                                            <td>{{ $session->start_time->format('h:i A') }} - {{ $session->end_time->format('h:i A') }}</td>
                                            <td>{{ $session->teacher->name ?? 'Not assigned' }}</td>
                                            <td>{{ $session->room->name ?? 'Not assigned' }}</td>
                                            <td class="text-right">
                                                <div class="flex justify-end gap-2">
                                                    <x-button
                                                        icon="o-eye"
                                                        size="xs"
                                                        link="{{ route('admin.sessions.show', $session->id) }}"
                                                        tooltip="View"
                                                    />
                                                    <x-button
                                                        icon="o-pencil"
                                                        size="xs"
                                                        link="{{ route('admin.sessions.edit', $session->id) }}"
                                                        tooltip="Edit"
                                                    />
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

                        <x-card.footer>
                            <x-button
                                label="View All Sessions"
                                icon="o-arrow-right"
                                link="{{ route('admin.sessions.index', ['subject' => $subject->id]) }}"
                                class="btn-ghost"
                            />
                        </x-card.footer>
                    @else
                        <div class="py-8 text-center">
                            <x-icon name="o-calendar" class="w-12 h-12 mx-auto text-gray-400" />
                            <p class="mt-2 text-gray-500">No sessions have been scheduled for this subject yet.</p>
                            <x-button
                                label="Add Session"
                                icon="o-plus"
                                link="{{ route('admin.sessions.create', ['subject_id' => $subject->id]) }}"
                                class="mt-4 btn-sm"
                            />
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Exams card -->
            <x-card class="mt-4">
                <x-card.header>
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Exams</h3>
                        <x-button
                            label="Add Exam"
                            icon="o-plus"
                            link="{{ route('admin.exams.create', ['subject_id' => $subject->id]) }}"
                            size="sm"
                        />
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
                                            <td>{{ $exam->name }}</td>
                                            <td>{{ $exam->date->format('M d, Y') }}</td>
                                            <td>{{ $exam->academicYear->name ?? 'Not assigned' }}</td>
                                            <td class="text-right">
                                                <div class="flex justify-end gap-2">
                                                    <x-button
                                                        icon="o-eye"
                                                        size="xs"
                                                        link="{{ route('admin.exams.show', $exam->id) }}"
                                                        tooltip="View"
                                                    />
                                                    <x-button
                                                        icon="o-pencil"
                                                        size="xs"
                                                        link="{{ route('admin.exams.edit', $exam->id) }}"
                                                        tooltip="Edit"
                                                    />
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

                        <x-card.footer>
                            <x-button
                                label="View All Exams"
                                icon="o-arrow-right"
                                link="{{ route('admin.exams.index', ['subject' => $subject->id]) }}"
                                class="btn-ghost"
                            />
                        </x-card.footer>
                    @else
                        <div class="py-8 text-center">
                            <x-icon name="o-clipboard-document-check" class="w-12 h-12 mx-auto text-gray-400" />
                            <p class="mt-2 text-gray-500">No exams have been scheduled for this subject yet.</p>
                            <x-button
                                label="Add Exam"
                                icon="o-plus"
                                link="{{ route('admin.exams.create', ['subject_id' => $subject->id]) }}"
                                class="mt-4 btn-sm"
                            />
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Enrollments card -->
            <x-card class="mt-4">
                <x-card.header>
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Enrollments</h3>
                        <x-button
                            label="New Enrollment"
                            icon="o-plus"
                            link="{{ route('admin.subject-enrollments.create', ['subject_id' => $subject->id]) }}"
                            size="sm"
                        />
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
                                                        <div class="w-8 h-8 mask mask-squircle">
                                                            @if($enrollment->childProfile->photo)
                                                                <img src="{{ asset('storage/' . $enrollment->childProfile->photo) }}" alt="{{ $enrollment->childProfile->user?->name ?? 'Child' }}">
                                                            @else
                                                                <img src="{{ $enrollment->childProfile->user?->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Child&color=7F9CF5&background=EBF4FF' }}" alt="{{ $enrollment->childProfile->user?->name ?? 'Child' }}">
                                                            @endif
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <a href="{{ route('admin.children.show', $enrollment->childProfile->id) }}" class="link link-hover">
                                                            {{ $enrollment->childProfile->user?->name ?? 'Unknown' }}
                                                        </a>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>{{ $enrollment->programEnrollment->academicYear->name ?? 'N/A' }}</td>
                                            <td>{{ $enrollment->created_at->format('M d, Y') }}</td>
                                            <td class="text-right">
                                                <div class="flex justify-end gap-2">
                                                    <x-button
                                                        icon="o-eye"
                                                        size="xs"
                                                        link="{{ route('admin.subject-enrollments.show', $enrollment->id) }}"
                                                        tooltip="View"
                                                    />
                                                    <x-button
                                                        icon="o-pencil"
                                                        size="xs"
                                                        link="{{ route('admin.subject-enrollments.edit', $enrollment->id) }}"
                                                        tooltip="Edit"
                                                    />
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

                        <x-card.footer>
                            <x-button
                                label="View All Enrollments"
                                icon="o-arrow-right"
                                link="{{ route('admin.subject-enrollments.index', ['subject' => $subject->id]) }}"
                                class="btn-ghost"
                            />
                        </x-card.footer>
                    @else
                        <div class="py-8 text-center">
                            <x-icon name="o-user-group" class="w-12 h-12 mx-auto text-gray-400" />
                            <p class="mt-2 text-gray-500">No students are enrolled in this subject yet.</p>
                            <x-button
                                label="Create Enrollment"
                                icon="o-plus"
                                link="{{ route('admin.subject-enrollments.create', ['subject_id' => $subject->id]) }}"
                                class="mt-4 btn-sm"
                            />
                        </div>
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</div>
