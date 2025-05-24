<?php

use App\Models\TimetableSlot;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\Curriculum;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Timetable Slot')] class extends Component {
    use Toast;

    // Form data
    public ?int $subjectId = null;
    public ?int $teacherProfileId = null;
    public string $day = '';
    public string $startTime = '';
    public string $endTime = '';
    public string $date = '';
    public ?int $curriculumId = null;

    // Options
    public array $days = [
        'Monday' => 'Monday',
        'Tuesday' => 'Tuesday',
        'Wednesday' => 'Wednesday',
        'Thursday' => 'Thursday',
        'Friday' => 'Friday',
        'Saturday' => 'Saturday',
        'Sunday' => 'Sunday'
    ];

    public array $timeSlots = [
        '08:00' => '08:00 AM',
        '09:00' => '09:00 AM',
        '10:00' => '10:00 AM',
        '11:00' => '11:00 AM',
        '12:00' => '12:00 PM',
        '13:00' => '01:00 PM',
        '14:00' => '02:00 PM',
        '15:00' => '03:00 PM',
        '16:00' => '04:00 PM',
        '17:00' => '05:00 PM',
        '18:00' => '06:00 PM',
    ];

    public function mount(): void
    {
        // Set default date to current week Monday
        $this->date = now()->startOfWeek()->format('Y-m-d');

        // Pre-fill from query parameters if provided
        if (request('day')) {
            $this->day = request('day');
        }

        if (request('time')) {
            $this->startTime = request('time');
            // Set end time to one hour later
            $endHour = (int)request('time') + 1;
            $this->endTime = sprintf('%02d:00', $endHour);
        }
    }

    public function updatedCurriculumId(): void
    {
        // Reset subject when curriculum changes
        $this->subjectId = null;
    }

    public function updatedSubjectId(): void
    {
        // When subject changes, filter available teachers
        $this->teacherProfileId = null;
    }

    public function updatedStartTime(): void
    {
        // Auto-set end time to one hour later
        if ($this->startTime) {
            $startHour = (int)substr($this->startTime, 0, 2);
            $endHour = $startHour + 1;
            $this->endTime = sprintf('%02d:00', $endHour);
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'subjectId' => 'required|exists:subjects,id',
            'teacherProfileId' => 'required|exists:teacher_profiles,id',
            'day' => 'required|in:' . implode(',', array_keys($this->days)),
            'date' => 'required|date',
            'startTime' => 'required',
            'endTime' => 'required|after:startTime',
        ]);

        try {
            // Calculate the actual date and time
            $dayIndex = array_search($validated['day'], array_keys($this->days));
            $targetDate = \Carbon\Carbon::parse($validated['date'])->startOfWeek()->addDays($dayIndex);

            $startDateTime = $targetDate->copy()->setTimeFromTimeString($validated['startTime']);
            $endDateTime = $targetDate->copy()->setTimeFromTimeString($validated['endTime']);

            // Check for conflicts
            $conflicts = TimetableSlot::where(function($query) use ($startDateTime, $endDateTime, $validated) {
                $query->where('teacher_profile_id', $validated['teacherProfileId'])
                      ->where(function($q) use ($startDateTime, $endDateTime) {
                          $q->whereBetween('start_time', [$startDateTime, $endDateTime])
                            ->orWhereBetween('end_time', [$startDateTime, $endDateTime])
                            ->orWhere(function($q2) use ($startDateTime, $endDateTime) {
                                $q2->where('start_time', '<=', $startDateTime)
                                   ->where('end_time', '>=', $endDateTime);
                            });
                      });
            })->exists();

            if ($conflicts) {
                $this->error('Teacher already has a class scheduled during this time.');
                return;
            }

            DB::beginTransaction();

            TimetableSlot::create([
                'subject_id' => $validated['subjectId'],
                'teacher_profile_id' => $validated['teacherProfileId'],
                'day' => $validated['day'],
                'start_time' => $startDateTime,
                'end_time' => $endDateTime,
            ]);

            DB::commit();

            $this->success('Timetable slot created successfully.');
            $this->redirect(route('admin.timetable.index'));

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    public function getCurriculaProperty()
    {
        return Curriculum::orderBy('name')->get();
    }

    public function getSubjectsProperty()
    {
        if (!$this->curriculumId) {
            return collect();
        }

        return Subject::where('curriculum_id', $this->curriculumId)
                     ->orderBy('name')
                     ->get();
    }

    public function getTeachersProperty()
    {
        $query = TeacherProfile::with('user')->orderBy('id');

        if ($this->subjectId) {
            // Get teachers who can teach this subject
            $query->whereHas('subjects', function($q) {
                $q->where('subject_id', $this->subjectId);
            });
        }

        return $query->get();
    }

    public function getSelectedSubjectProperty()
    {
        if (!$this->subjectId) {
            return null;
        }

        return Subject::with('curriculum')->find($this->subjectId);
    }

    public function getSelectedTeacherProperty()
    {
        if (!$this->teacherProfileId) {
            return null;
        }

        return TeacherProfile::with('user')->find($this->teacherProfileId);
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Create Timetable Slot" separator>
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-arrow-left"
                link="{{ route('admin.timetable.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Form -->
        <div class="lg:col-span-2">
            <x-card title="Slot Information">
                <form wire:submit="save" class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Curriculum Selection -->
                        <div class="md:col-span-2">
                            <label class="block mb-2 text-sm font-medium text-gray-700">Curriculum *</label>
                            <select
                                wire:model.live="curriculumId"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                            >
                                <option value="">Select a curriculum</option>
                                @foreach($this->curricula as $curriculum)
                                    <option value="{{ $curriculum->id }}">{{ $curriculum->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Subject Selection -->
                        <div class="md:col-span-2">
                            <label class="block mb-2 text-sm font-medium text-gray-700">Subject *</label>
                            <select
                                wire:model.live="subjectId"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                                {{ !$this->curriculumId ? 'disabled' : '' }}
                            >
                                <option value="">{{ $this->curriculumId ? 'Select a subject' : 'Select curriculum first' }}</option>
                                @foreach($this->subjects as $subject)
                                    <option value="{{ $subject->id }}">{{ $subject->name }} ({{ $subject->code }})</option>
                                @endforeach
                            </select>
                            @if(!$this->curriculumId)
                                <p class="mt-1 text-sm text-gray-500">Please select a curriculum first</p>
                            @endif
                        </div>

                        <!-- Teacher Selection -->
                        <div class="md:col-span-2">
                            <label class="block mb-2 text-sm font-medium text-gray-700">Teacher *</label>
                            <select
                                wire:model.live="teacherProfileId"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                            >
                                <option value="">Select a teacher</option>
                                @foreach($this->teachers as $teacher)
                                    <option value="{{ $teacher->id }}">
                                        {{ $teacher->user->name ?? 'Unknown Teacher' }}
                                        @if($teacher->specialization)
                                            - {{ $teacher->specialization }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            @if($this->subjectId && $this->teachers->isEmpty())
                                <p class="mt-1 text-sm text-amber-600">No teachers assigned to this subject. Please assign teachers to subjects first.</p>
                            @endif
                        </div>

                        <!-- Week Date -->
                        <div>
                            <x-input
                                label="Week Starting Date"
                                wire:model.live="date"
                                type="date"
                                required
                                help-text="Select the Monday of the target week"
                            />
                        </div>

                        <!-- Day Selection -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Day *</label>
                            <select
                                wire:model.live="day"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                            >
                                <option value="">Select a day</option>
                                @foreach($days as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Start Time -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Start Time *</label>
                            <select
                                wire:model.live="startTime"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                            >
                                <option value="">Select start time</option>
                                @foreach($timeSlots as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- End Time -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">End Time *</label>
                            <select
                                wire:model.live="endTime"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                            >
                                <option value="">Select end time</option>
                                @foreach($timeSlots as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @if($startTime && $endTime && $endTime <= $startTime)
                                <p class="mt-1 text-sm text-red-600">End time must be after start time</p>
                            @endif
                        </div>
                    </div>

                    <div class="flex justify-end pt-4 space-x-2">
                        <x-button
                            label="Cancel"
                            link="{{ route('admin.timetable.index') }}"
                            class="btn-ghost"
                        />
                        <x-button
                            label="Create Slot"
                            icon="o-plus"
                            type="submit"
                            color="primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right column (1/3) - Preview and Info -->
        <div class="space-y-6">
            <!-- Preview Card -->
            <x-card title="Slot Preview">
                <div class="space-y-4">
                    @if($this->selectedSubject)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Subject</div>
                            <div class="font-semibold">{{ $this->selectedSubject->name }}</div>
                            <div class="text-sm text-gray-600">{{ $this->selectedSubject->code }}</div>
                            @if($this->selectedSubject->curriculum)
                                <div class="text-sm text-gray-600">{{ $this->selectedSubject->curriculum->name }}</div>
                            @endif
                        </div>
                    @endif

                    @if($this->selectedTeacher)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Teacher</div>
                            <div class="font-semibold">{{ $this->selectedTeacher->user->name ?? 'Unknown' }}</div>
                            @if($this->selectedTeacher->specialization)
                                <div class="text-sm text-gray-600">{{ $this->selectedTeacher->specialization }}</div>
                            @endif
                        </div>
                    @endif

                    @if($day && $date)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Schedule</div>
                            <div class="font-semibold">{{ $day }}</div>
                            @php
                                $dayIndex = array_search($day, array_keys($days));
                                $targetDate = \Carbon\Carbon::parse($date)->startOfWeek()->addDays($dayIndex);
                            @endphp
                            <div class="text-sm text-gray-600">{{ $targetDate->format('M d, Y') }}</div>
                        </div>
                    @endif

                    @if($startTime && $endTime)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Time</div>
                            <div class="font-semibold">
                                {{ $timeSlots[$startTime] ?? $startTime }} - {{ $timeSlots[$endTime] ?? $endTime }}
                            </div>
                            @php
                                $duration = $endTime && $startTime ? (strtotime($endTime) - strtotime($startTime)) / 3600 : 0;
                            @endphp
                            @if($duration > 0)
                                <div class="text-sm text-gray-600">Duration: {{ $duration }} hour(s)</div>
                            @endif
                        </div>
                    @endif
                </div>

                @if($subjectId && $teacherProfileId && $day && $startTime && $endTime)
                    <div class="p-3 mt-4 border border-green-200 rounded-md bg-green-50">
                        <div class="text-sm font-medium text-green-800">Ready to Create</div>
                        <div class="text-sm text-green-600">All required fields are filled</div>
                    </div>
                @endif
            </x-card>

            <!-- Teacher Schedule (if teacher selected) -->
            @if($this->selectedTeacher && $date && $day)
                <x-card title="Teacher's Schedule">
                    <div class="text-sm">
                        <div class="mb-2 font-medium text-gray-700">
                            {{ $this->selectedTeacher->user->name ?? 'Unknown' }} - {{ $day }}
                        </div>

                        @php
                            $dayIndex = array_search($day, array_keys($days));
                            $targetDate = \Carbon\Carbon::parse($date)->startOfWeek()->addDays($dayIndex);

                            $existingSlots = \App\Models\TimetableSlot::where('teacher_profile_id', $teacherProfileId)
                                ->whereDate('start_time', $targetDate->format('Y-m-d'))
                                ->with('subject')
                                ->orderBy('start_time')
                                ->get();
                        @endphp

                        @if($existingSlots->count() > 0)
                            <div class="space-y-2">
                                @foreach($existingSlots as $slot)
                                    <div class="flex items-center justify-between p-2 border-l-4 border-blue-400 rounded bg-blue-50">
                                        <div>
                                            <div class="font-medium text-blue-800">{{ $slot->subject->name ?? 'N/A' }}</div>
                                            <div class="text-xs text-blue-600">{{ $slot->subject->code ?? '' }}</div>
                                        </div>
                                        <div class="text-sm text-blue-700">
                                            {{ $slot->start_time->format('H:i') }} - {{ $slot->end_time->format('H:i') }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="py-4 text-center text-gray-500">
                                No classes scheduled for this day
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif

            <!-- Help Card -->
            <x-card title="Help & Information">
                <div class="space-y-4 text-sm">
                    <div>
                        <div class="font-semibold">Creating Slots</div>
                        <p class="text-gray-600">Select curriculum first, then subject, then assign a teacher. The system will check for scheduling conflicts.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Teacher Assignment</div>
                        <p class="text-gray-600">Only teachers assigned to the selected subject will appear in the dropdown. Manage teacher-subject assignments separately.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Time Conflicts</div>
                        <p class="text-gray-600">The system will prevent double-booking teachers. Make sure to check existing schedules before creating slots.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Weekly Schedule</div>
                        <p class="text-gray-600">Select the Monday date of the target week. The system will calculate the exact date for the selected day.</p>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
</div>
