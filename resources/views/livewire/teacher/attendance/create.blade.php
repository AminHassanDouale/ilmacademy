<?php

use Livewire\Volt\Component;
use App\Models\Session;
use App\Models\ChildProfile;
use App\Models\Attendance;
use App\Models\TeacherProfile;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mary\Traits\Toast;

new class extends Component
{
    use Toast;

    // Current teacher profile
    public ?TeacherProfile $teacherProfile = null;

    // Session data
    public ?Session $session = null;
    public int $sessionId = 0;

    // Students and attendance
    public $childProfiles = [];
    public array $attendance = [];

    // Form state
    public bool $isLoading = false;
    public bool $hasExistingAttendance = false;

    public function mount(int $sessionId): void
    {
        $this->sessionId = $sessionId;
        $this->teacherProfile = Auth::user()->teacherProfile;

        if (!$this->teacherProfile) {
            $this->error('Teacher profile not found. Please complete your profile first.');
            $this->redirect(route('teacher.profile.edit'));
            return;
        }

        // Load session with validation
        $this->session = Session::with(['subject', 'attendances.childProfile'])
            ->where('id', $this->sessionId)
            ->where('teacher_profile_id', $this->teacherProfile->id)
            ->first();

        if (!$this->session) {
            $this->error('Session not found or you do not have permission to access it.');
            $this->redirect(route('teacher.sessions.index'));
            return;
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed attendance creation for session #' . $this->session->id,
            Attendance::class,
            null,
            ['session_id' => $this->session->id, 'ip' => request()->ip()]
        );

        $this->loadChildProfiles();
        $this->loadExistingAttendance();
    }

    protected function loadChildProfiles(): void
    {
        try {
            // Get child profiles for this subject/session
            // Assuming you have a relationship or way to get enrolled students
            // This might need adjustment based on your enrollment system
            $this->childProfiles = ChildProfile::whereHas('enrollments', function($query) {
                $query->where('subject_id', $this->session->subject_id);
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

            Log::info('Child Profiles Loaded', [
                'session_id' => $this->session->id,
                'child_profiles_count' => $this->childProfiles->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Load Child Profiles', [
                'session_id' => $this->session->id,
                'error' => $e->getMessage()
            ]);

            $this->childProfiles = collect();
            $this->error('Failed to load student list. Please try again.');
        }
    }

    protected function loadExistingAttendance(): void
    {
        try {
            $existingAttendance = $this->session->attendances->keyBy('child_profile_id');
            $this->hasExistingAttendance = $existingAttendance->isNotEmpty();

            // Initialize attendance array
            $this->attendance = [];
            foreach ($this->childProfiles as $childProfile) {
                if ($existingAttendance->has($childProfile->id)) {
                    $this->attendance[$childProfile->id] = $existingAttendance[$childProfile->id]->status;
                } else {
                    $this->attendance[$childProfile->id] = 'present'; // Default to present
                }
            }

            Log::info('Existing Attendance Loaded', [
                'session_id' => $this->session->id,
                'existing_records' => $existingAttendance->count(),
                'has_existing' => $this->hasExistingAttendance
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to Load Existing Attendance', [
                'session_id' => $this->session->id,
                'error' => $e->getMessage()
            ]);

            // Initialize with default values
            $this->attendance = [];
            foreach ($this->childProfiles as $childProfile) {
                $this->attendance[$childProfile->id] = 'present';
            }
        }
    }

    public function saveAttendance(): void
    {
        $this->isLoading = true;

        Log::info('Attendance Save Started', [
            'session_id' => $this->session->id,
            'teacher_profile_id' => $this->teacherProfile->id,
            'attendance_count' => count($this->attendance)
        ]);

        try {
            $this->validate([
                'attendance' => 'required|array|min:1',
                'attendance.*' => 'required|in:present,absent,late,excused',
            ], [
                'attendance.required' => 'Please mark attendance for all students.',
                'attendance.min' => 'At least one student must be marked.',
                'attendance.*.required' => 'Please select attendance status for all students.',
                'attendance.*.in' => 'Invalid attendance status selected.',
            ]);

            DB::beginTransaction();

            // Delete existing attendance for this session
            if ($this->hasExistingAttendance) {
                $this->session->attendances()->delete();
                Log::info('Existing Attendance Deleted', ['session_id' => $this->session->id]);
            }

            // Create new attendance records
            $attendanceRecords = [];
            foreach ($this->attendance as $childProfileId => $status) {
                $attendanceRecords[] = [
                    'session_id' => $this->session->id,
                    'child_profile_id' => $childProfileId,
                    'status' => $status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            Attendance::insert($attendanceRecords);

            // Log activity
            $stats = $this->getAttendanceStats();
            ActivityLog::log(
                Auth::id(),
                $this->hasExistingAttendance ? 'update' : 'create',
                ($this->hasExistingAttendance ? 'Updated' : 'Created') .
                " attendance for session #{$this->session->id} - {$this->session->subject->name}",
                Attendance::class,
                $this->session->id,
                [
                    'session_id' => $this->session->id,
                    'subject_name' => $this->session->subject->name,
                    'total_students' => count($this->attendance),
                    'present_count' => $stats['present'],
                    'absent_count' => $stats['absent'],
                    'late_count' => $stats['late'],
                    'excused_count' => $stats['excused'],
                    'attendance_rate' => $stats['rate'],
                ]
            );

            DB::commit();

            $this->success($this->hasExistingAttendance ?
                'Attendance has been successfully updated!' :
                'Attendance has been successfully recorded!'
            );

            Log::info('Attendance Save Completed', [
                'session_id' => $this->session->id,
                'records_created' => count($attendanceRecords)
            ]);

            // Redirect to session show or attendance index
            $this->redirect(route('teacher.sessions.show', $this->session->id));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Attendance Validation Failed', [
                'session_id' => $this->session->id,
                'errors' => $e->errors()
            ]);
            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Attendance Save Failed', [
                'session_id' => $this->session->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->error('An error occurred while saving attendance: ' . $e->getMessage());
        } finally {
            $this->isLoading = false;
        }
    }

    public function markAllAs(string $status): void
    {
        if (!in_array($status, ['present', 'absent', 'late', 'excused'])) {
            return;
        }

        foreach ($this->childProfiles as $childProfile) {
            $this->attendance[$childProfile->id] = $status;
        }

        $this->info("All students marked as " . ucfirst($status));
    }

    protected function getAttendanceStats(): array
    {
        $stats = [
            'total' => count($this->attendance),
            'present' => 0,
            'absent' => 0,
            'late' => 0,
            'excused' => 0,
        ];

        foreach ($this->attendance as $status) {
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        $attendingCount = $stats['present'] + $stats['late'];
        $stats['rate'] = $stats['total'] > 0 ? round(($attendingCount / $stats['total']) * 100, 1) : 0;

        return $stats;
    }

    public function getAttendanceStatusColor(string $status): string
    {
        return match(strtolower($status)) {
            'present' => 'badge-success',
            'absent' => 'badge-error',
            'late' => 'badge-warning',
            'excused' => 'badge-info',
            default => 'badge-ghost'
        };
    }

    public function with(): array
    {
        return [
            'stats' => $this->getAttendanceStats(),
        ];
    }
}; ?>

<div>
    <!-- Page header -->
    <x-header
        title="{{ $hasExistingAttendance ? 'Edit' : 'Take' }} Attendance"
        subtitle="Session #{{ $session->id }} - {{ $session->subject->name }}"
        separator
    >
        <x-slot:actions>
            <x-button
                label="Back to Session"
                icon="o-arrow-left"
                link="{{ route('teacher.sessions.show', $session->id) }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <!-- Main Content (3/4) -->
        <div class="lg:col-span-3">
            <!-- Session Info Card -->
            <x-card class="mb-6">
                <div class="grid grid-cols-1 gap-4 p-4 md:grid-cols-3">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Subject</div>
                        <div class="text-lg font-semibold">{{ $session->subject->name }}</div>
                        <div class="text-sm text-gray-600">{{ $session->subject->code }}</div>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-500">Date & Time</div>
                        <div class="text-lg font-semibold">{{ $session->start_time->format('M d, Y') }}</div>
                        <div class="text-sm text-gray-600">
                            {{ $session->start_time->format('g:i A') }} - {{ $session->end_time->format('g:i A') }}
                        </div>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-500">Session Type</div>
                        <div class="text-lg font-semibold">{{ ucfirst($session->type) }}</div>
                        @if($session->classroom_id)
                            <div class="text-sm text-gray-600">{{ $session->classroom->name ?? 'Room #' . $session->classroom_id }}</div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Attendance Form -->
            <x-card title="Student Attendance">
                @if($childProfiles->count() > 0)
                    <form wire:submit="saveAttendance">
                        <!-- Quick Actions -->
                        <div class="p-4 mb-4 rounded-lg bg-base-200">
                            <div class="mb-2 text-sm font-medium">Quick Actions:</div>
                            <div class="flex flex-wrap gap-2">
                                <x-button
                                    label="Mark All Present"
                                    icon="o-check-circle"
                                    wire:click="markAllAs('present')"
                                    class="btn-sm btn-success"
                                    type="button"
                                />
                                <x-button
                                    label="Mark All Absent"
                                    icon="o-x-circle"
                                    wire:click="markAllAs('absent')"
                                    class="btn-sm btn-error"
                                    type="button"
                                />
                                <x-button
                                    label="Mark All Late"
                                    icon="o-clock"
                                    wire:click="markAllAs('late')"
                                    class="btn-sm btn-warning"
                                    type="button"
                                />
                            </div>
                        </div>

                        <!-- Student List -->
                        <div class="space-y-3">
                            @foreach($childProfiles as $childProfile)
                                <div class="flex items-center justify-between p-4 border rounded-lg hover:bg-base-50">
                                    <div class="flex items-center">
                                        <!-- Avatar -->
                                        <div class="flex items-center justify-center w-12 h-12 mr-4 font-semibold text-white rounded-full bg-primary">
                                            {{ substr($childProfile->first_name, 0, 1) }}{{ substr($childProfile->last_name, 0, 1) }}
                                        </div>

                                        <!-- Student Info -->
                                        <div>
                                            <h4 class="font-medium text-gray-900">
                                                {{ $childProfile->first_name }} {{ $childProfile->last_name }}
                                            </h4>
                                            @if($childProfile->student_id)
                                                <p class="text-sm text-gray-500">ID: {{ $childProfile->student_id }}</p>
                                            @endif
                                            @if($childProfile->grade_level)
                                                <p class="text-sm text-gray-500">Grade: {{ $childProfile->grade_level }}</p>
                                            @endif
                                        </div>
                                    </div>

                                    <!-- Attendance Options -->
                                    <div class="flex gap-4">
                                        @foreach(['present', 'absent', 'late', 'excused'] as $status)
                                            <label class="flex items-center cursor-pointer">
                                                <input
                                                    type="radio"
                                                    wire:model="attendance.{{ $childProfile->id }}"
                                                    value="{{ $status }}"
                                                    class="radio radio-{{ match($status) {
                                                        'present' => 'success',
                                                        'absent' => 'error',
                                                        'late' => 'warning',
                                                        'excused' => 'info',
                                                        default => 'primary'
                                                    } }}"
                                                >
                                                <span class="ml-2 text-sm font-medium text-{{ match($status) {
                                                    'present' => 'success',
                                                    'absent' => 'error',
                                                    'late' => 'warning',
                                                    'excused' => 'info',
                                                    default => 'gray-700'
                                                } }}">
                                                    {{ ucfirst($status) }}
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <!-- Submit Button -->
                        <div class="flex justify-end pt-6 mt-6 border-t">
                            <x-button
                                label="{{ $hasExistingAttendance ? 'Update Attendance' : 'Save Attendance' }}"
                                icon="o-check"
                                type="submit"
                                class="btn-primary"
                                :loading="$isLoading"
                            />
                        </div>
                    </form>
                @else
                    <!-- No Students -->
                    <div class="py-12 text-center">
                        <x-icon name="o-user-group" class="w-20 h-20 mx-auto text-gray-300" />
                        <h3 class="mt-4 text-lg font-semibold text-gray-600">No Students Found</h3>
                        <p class="mt-2 text-gray-500">
                            No students are enrolled for this subject or session.
                        </p>
                        <x-button
                            label="Back to Sessions"
                            icon="o-arrow-left"
                            link="{{ route('teacher.sessions.index') }}"
                            class="mt-4"
                        />
                    </div>
                @endif
            </x-card>
        </div>

        <!-- Sidebar (1/4) -->
        <div class="space-y-6">
            <!-- Attendance Summary -->
            @if($childProfiles->count() > 0)
                <x-card title="Summary">
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="text-center">
                                <div class="text-2xl font-bold text-success">{{ $stats['present'] }}</div>
                                <div class="text-sm text-gray-500">Present</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-error">{{ $stats['absent'] }}</div>
                                <div class="text-sm text-gray-500">Absent</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-warning">{{ $stats['late'] }}</div>
                                <div class="text-sm text-gray-500">Late</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-info">{{ $stats['excused'] }}</div>
                                <div class="text-sm text-gray-500">Excused</div>
                            </div>
                        </div>

                        <div class="pt-4 border-t">
                            <div class="text-center">
                                <div class="text-3xl font-bold text-primary">{{ $stats['rate'] }}%</div>
                                <div class="text-sm text-gray-500">Attendance Rate</div>
                            </div>
                            <div class="w-full h-2 mt-2 bg-gray-200 rounded-full">
                                <div
                                    class="h-2 rounded-full {{ $stats['rate'] >= 80 ? 'bg-success' : ($stats['rate'] >= 60 ? 'bg-warning' : 'bg-error') }}"
                                    style="width: {{ $stats['rate'] }}%"
                                ></div>
                            </div>
                        </div>

                        <div class="text-center">
                            <div class="text-lg font-semibold">{{ $stats['total'] }}</div>
                            <div class="text-sm text-gray-500">Total Students</div>
                        </div>
                    </div>
                </x-card>
            @endif

            <!-- Status Legend -->
            <x-card title="Status Guide">
                <div class="space-y-3">
                    <div class="flex items-center">
                        <div class="w-4 h-4 mr-3 rounded-full bg-success"></div>
                        <div>
                            <div class="font-medium">Present</div>
                            <div class="text-xs text-gray-500">Student attended the session</div>
                        </div>
                    </div>
                    <div class="flex items-center">
                        <div class="w-4 h-4 mr-3 rounded-full bg-warning"></div>
                        <div>
                            <div class="font-medium">Late</div>
                            <div class="text-xs text-gray-500">Student arrived after session started</div>
                        </div>
                    </div>
                    <div class="flex items-center">
                        <div class="w-4 h-4 mr-3 rounded-full bg-error"></div>
                        <div>
                            <div class="font-medium">Absent</div>
                            <div class="text-xs text-gray-500">Student did not attend</div>
                        </div>
                    </div>
                    <div class="flex items-center">
                        <div class="w-4 h-4 mr-3 rounded-full bg-info"></div>
                        <div>
                            <div class="font-medium">Excused</div>
                            <div class="text-xs text-gray-500">Authorized absence</div>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-2">
                    <x-button
                        label="View Session"
                        icon="o-eye"
                        link="{{ route('teacher.sessions.show', $session->id) }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="All Sessions"
                        icon="o-presentation-chart-line"
                        link="{{ route('teacher.sessions.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                    <x-button
                        label="Attendance Reports"
                        icon="o-chart-bar"
                        link="{{ route('teacher.attendance.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />
                </div>
            </x-card>
        </div>
    </div>
</div>
