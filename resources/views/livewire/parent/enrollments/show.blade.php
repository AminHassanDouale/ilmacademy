<?php

use App\Models\ProgramEnrollment;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\ExamResult;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public ProgramEnrollment $programEnrollment;

    public function mount(ProgramEnrollment $programEnrollment): void
    {
        // Ensure parent can only view their own children's enrollments
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            $this->error('Parent profile not found.');
            $this->redirect(route('parent.enrollments.index'));
            return;
        }

        // Get all children IDs for this parent
        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        // Check if the enrollment belongs to one of the parent's children
        if (!in_array($programEnrollment->child_profile_id, $childrenIds)) {
            $this->error('You do not have permission to view this enrollment.');
            $this->redirect(route('parent.enrollments.index'));
            return;
        }

        $this->programEnrollment = $programEnrollment->load([
            'childProfile.user',
            'program',
            'attendances' => fn($query) => $query->orderBy('date', 'desc'),
            'examResults' => fn($query) => $query->orderBy('exam_date', 'desc')
        ]);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed program enrollment details: {$this->programEnrollment->program->name} for {$this->programEnrollment->childProfile->user->name}",
            ProgramEnrollment::class,
            $this->programEnrollment->id,
            ['ip' => request()->ip()]
        );
    }

    #[Title('Enrollment Details')]
    public function title(): string
    {
        return "Enrollment: " .
            ($this->programEnrollment->program->name ?? 'Unknown Program') .
            " - " .
            ($this->programEnrollment->childProfile->user->name ?? 'Unknown Child');
    }

    // Calculate attendance statistics
    public function attendanceStats()
    {
        $attendances = $this->programEnrollment->attendances;

        if ($attendances->isEmpty()) {
            return [
                'total' => 0,
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'excused' => 0,
                'presentPercentage' => 0,
                'absentPercentage' => 0,
                'latePercentage' => 0,
                'excusedPercentage' => 0
            ];
        }

        $total = $attendances->count();
        $present = $attendances->where('status', 'present')->count();
        $absent = $attendances->where('status', 'absent')->count();
        $late = $attendances->where('status', 'late')->count();
        $excused = $attendances->where('status', 'excused')->count();

        return [
            'total' => $total,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'excused' => $excused,
            'presentPercentage' => $total > 0 ? round(($present / $total) * 100) : 0,
            'absentPercentage' => $total > 0 ? round(($absent / $total) * 100) : 0,
            'latePercentage' => $total > 0 ? round(($late / $total) * 100) : 0,
            'excusedPercentage' => $total > 0 ? round(($excused / $total) * 100) : 0
        ];
    }

    // Calculate exam statistics
    public function examStats()
    {
        $examResults = $this->programEnrollment->examResults;

        if ($examResults->isEmpty()) {
            return [
                'total' => 0,
                'average' => 0,
                'highest' => 0,
                'lowest' => 0,
                'passed' => 0,
                'failed' => 0,
                'passRate' => 0
            ];
        }

        $total = $examResults->count();
        $scores = $examResults->pluck('score')->toArray();
        $average = round(array_sum($scores) / $total, 1);
        $highest = max($scores);
        $lowest = min($scores);

        // Assuming 60% is passing score
        $passed = $examResults->filter(function ($result) {
            return $result->score >= 60;
        })->count();

        $failed = $total - $passed;
        $passRate = $total > 0 ? round(($passed / $total) * 100) : 0;

        return [
            'total' => $total,
            'average' => $average,
            'highest' => $highest,
            'lowest' => $lowest,
            'passed' => $passed,
            'failed' => $failed,
            'passRate' => $passRate
        ];
    }

    public function with(): array
    {
        return [
            'attendanceStats' => $this->attendanceStats(),
            'examStats' => $this->examStats(),
        ];
    }
};?>

<div>
    <x-header :title="$programEnrollment->program->name" separator>
        <x-slot:subtitle>
            Enrollment for {{ $programEnrollment->childProfile->user->name }}
        </x-slot:subtitle>

        <x-slot:actions>
            <x-button label="Back to Enrollments" icon="o-arrow-left" link="{{ route('parent.enrollments.index') }}" />
            <x-button label="View Child Profile" icon="o-user" link="{{ route('parent.children.show', $programEnrollment->childProfile) }}" class="btn-primary" />
        </x-slot:actions>
    </x-header>

    <div class="grid gap-6 md:grid-cols-12">
        <!-- PROGRAM INFO CARD -->
        <div class="md:col-span-4">
            <x-card title="Program Information" separator>
                <div class="space-y-4">
                    @if($programEnrollment->program->image)
                        <img src="{{ asset('storage/' . $programEnrollment->program->image) }}" alt="{{ $programEnrollment->program->name }}" class="w-full rounded-lg">
                    @endif

                    <div>
                        <h3 class="text-xl font-bold">{{ $programEnrollment->program->name }}</h3>
                        <p class="mt-2 text-gray-600">{{ $programEnrollment->program->description }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4 pt-4 border-t">
                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Department</h4>
                            <p>{{ $programEnrollment->program->department ?? 'Not specified' }}</p>
                        </div>

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Level</h4>
                            <p>{{ $programEnrollment->program->level ?? 'Not specified' }}</p>
                        </div>

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Duration</h4>
                            <p>{{ $programEnrollment->program->duration ?? 'Not specified' }}</p>
                        </div>

                        <div>
                            <h4 class="text-sm font-medium text-gray-500">Sessions</h4>
                            <p>{{ $programEnrollment->program->sessions ?? 'Not specified' }}</p>
                        </div>
                    </div>

                    <div class="pt-4 border-t">
                        <h4 class="text-sm font-medium text-gray-500">Instructor</h4>
                        <p>{{ $programEnrollment->program->instructor ?? 'Not assigned' }}</p>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- ENROLLMENT DETAILS CARD -->
        <div class="md:col-span-8">
            <x-card title="Enrollment Details" separator>
                <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-3">
                    <!-- STATUS -->
                    <div class="flex flex-col items-center justify-center p-4 border rounded-lg">
                        <div class="mb-1 text-sm text-gray-500">Status</div>
                        <x-badge
                            label="{{ ucfirst($programEnrollment->status ?? 'Unknown') }}"
                            size="lg"
                            color="{{ match($programEnrollment->status ?? '') {
                                'active' => 'success',
                                'pending' => 'warning',
                                'completed' => 'info',
                                'cancelled' => 'error',
                                default => 'ghost'
                            } }}"
                        />
                    </div>

                    <!-- PROGRESS -->
                    <div class="p-4 border rounded-lg">
                        <div class="mb-2 text-sm text-gray-500">Progress</div>
                        <div class="flex items-center gap-2">
                            <div class="radial-progress text-primary" style="--value:{{ $programEnrollment->progress ?? 0 }}; --size:4rem;">
                                {{ $programEnrollment->progress ?? 0 }}%
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between mb-1 text-xs text-gray-500">
                                    <span>Progress</span>
                                    <span>{{ $programEnrollment->progress ?? 0 }}%</span>
                                </div>
                                <progress class="w-full progress progress-primary" value="{{ $programEnrollment->progress ?? 0 }}" max="100"></progress>
                            </div>
                        </div>
                    </div>

                    <!-- DATES -->
                    <div class="p-4 border rounded-lg">
                        <div class="mb-2 text-sm text-gray-500">Important Dates</div>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-500">Enrolled:</span>
                                <span class="text-xs font-medium">{{ $programEnrollment->enrollment_date?->format('d/m/Y') ?? 'Not set' }}</span>
                            </div>
                            @if($programEnrollment->start_date)
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-500">Started:</span>
                                <span class="text-xs font-medium">{{ $programEnrollment->start_date->format('d/m/Y') }}</span>
                            </div>
                            @endif
                            @if($programEnrollment->completion_date)
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-500">Completed:</span>
                                <span class="text-xs font-medium">{{ $programEnrollment->completion_date->format('d/m/Y') }}</span>
                            </div>
                            @endif
                            @if($programEnrollment->end_date)
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-500">End Date:</span>
                                <span class="text-xs font-medium">{{ $programEnrollment->end_date->format('d/m/Y') }}</span>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- TABS -->
                <div x-data="{ tab: 'attendance' }">
                    <div class="mb-4 tabs tabs-boxed">
                        <a @click.prevent="tab = 'attendance'" :class="{ 'tab-active': tab === 'attendance' }" class="tab">Attendance</a>
                        <a @click.prevent="tab = 'exams'" :class="{ 'tab-active': tab === 'exams' }" class="tab">Exam Results</a>
                        <a @click.prevent="tab = 'notes'" :class="{ 'tab-active': tab === 'notes' }" class="tab">Notes</a>
                    </div>

                    <!-- ATTENDANCE TAB -->
                    <div x-show="tab === 'attendance'" class="space-y-6">
                        <!-- ATTENDANCE STATS -->
                        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                            <div class="p-4 rounded-lg stat bg-base-200">
                                <div class="stat-title">Present</div>
                                <div class="stat-value text-success">{{ $attendanceStats['present'] }}</div>
                                <div class="stat-desc">{{ $attendanceStats['presentPercentage'] }}% of sessions</div>
                            </div>

                            <div class="p-4 rounded-lg stat bg-base-200">
                                <div class="stat-title">Absent</div>
                                <div class="stat-value text-error">{{ $attendanceStats['absent'] }}</div>
                                <div class="stat-desc">{{ $attendanceStats['absentPercentage'] }}% of sessions</div>
                            </div>

                            <div class="p-4 rounded-lg stat bg-base-200">
                                <div class="stat-title">Late</div>
                                <div class="stat-value text-warning">{{ $attendanceStats['late'] }}</div>
                                <div class="stat-desc">{{ $attendanceStats['latePercentage'] }}% of sessions</div>
                            </div>

                            <div class="p-4 rounded-lg stat bg-base-200">
                                <div class="stat-title">Excused</div>
                                <div class="stat-value text-info">{{ $attendanceStats['excused'] }}</div>
                                <div class="stat-desc">{{ $attendanceStats['excusedPercentage'] }}% of sessions</div>
                            </div>
                        </div>

                        <!-- ATTENDANCE HISTORY -->
                        <div>
                            <h3 class="mb-3 text-lg font-semibold">Attendance History</h3>
                            @if($programEnrollment->attendances->isEmpty())
                                <div class="alert alert-info">
                                    <x-icon name="o-information-circle" class="w-5 h-5" />
                                    <span>No attendance records available yet.</span>
                                </div>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="table w-full table-zebra">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Status</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($programEnrollment->attendances as $attendance)
                                                <tr>
                                                    <td>{{ $attendance->date->format('d/m/Y') }}</td>
                                                    <td>
                                                        <x-badge
                                                            label="{{ ucfirst($attendance->status) }}"
                                                            color="{{ match($attendance->status) {
                                                                'present' => 'success',
                                                                'absent' => 'error',
                                                                'late' => 'warning',
                                                                'excused' => 'info',
                                                                default => 'ghost'
                                                            } }}"
                                                        />
                                                    </td>
                                                    <td>{{ $attendance->notes ?? 'No notes' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- EXAMS TAB -->
                    <div x-show="tab === 'exams'" class="space-y-6">
                        <!-- EXAM STATS -->
                        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                            <div class="p-4 rounded-lg stat bg-base-200">
                                <div class="stat-title">Average</div>
                                <div class="stat-value">{{ $examStats['average'] }}%</div>
                                <div class="stat-desc">Overall performance</div>
                            </div>

                            <div class="p-4 rounded-lg stat bg-base-200">
                                <div class="stat-title">Highest</div>
                                <div class="stat-value text-success">{{ $examStats['highest'] }}%</div>
                                <div class="stat-desc">Best score</div>
                            </div>

                            <div class="p-4 rounded-lg stat bg-base-200">
                                <div class="stat-title">Lowest</div>
                                <div class="stat-value text-error">{{ $examStats['lowest'] }}%</div>
                                <div class="stat-desc">Lowest score</div>
                            </div>

                            <div class="p-4 rounded-lg stat bg-base-200">
                                <div class="stat-title">Pass Rate</div>
                                <div class="stat-value text-info">{{ $examStats['passRate'] }}%</div>
                                <div class="stat-desc">{{ $examStats['passed'] }} of {{ $examStats['total'] }} exams passed</div>
                            </div>
                        </div>

                        <!-- EXAM HISTORY -->
                        <div>
                            <h3 class="mb-3 text-lg font-semibold">Exam Results</h3>
                            @if($programEnrollment->examResults->isEmpty())
                                <div class="alert alert-info">
                                    <x-icon name="o-information-circle" class="w-5 h-5" />
                                    <span>No exam results available yet.</span>
                                </div>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="table w-full table-zebra">
                                        <thead>
                                            <tr>
                                                <th>Exam</th>
                                                <th>Date</th>
                                                <th>Score</th>
                                                <th>Status</th>
                                                <th>Comments</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($programEnrollment->examResults as $exam)
                                                <tr>
                                                    <td>{{ $exam->title ?? 'Unnamed Exam' }}</td>
                                                    <td>{{ $exam->exam_date->format('d/m/Y') }}</td>
                                                    <td>{{ $exam->score }}%</td>
                                                    <td>
                                                        <x-badge
                                                            label="{{ $exam->score >= 60 ? 'Passed' : 'Failed' }}"
                                                            color="{{ $exam->score >= 60 ? 'success' : 'error' }}"
                                                        />
                                                    </td>
                                                    <td>{{ $exam->comments ?? 'No comments' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- NOTES TAB -->
                    <div x-show="tab === 'notes'" class="space-y-4">
                        @if($programEnrollment->notes)
                            <div class="prose max-w-none">
                                {!! nl2br(e($programEnrollment->notes)) !!}
                            </div>
                        @else
                            <div class="alert alert-info">
                                <x-icon name="o-information-circle" class="w-5 h-5" />
                                <span>No additional notes available for this enrollment.</span>
                            </div>
                        @endif

                        @if($programEnrollment->grade)
                            <div class="alert alert-success">
                                <x-icon name="o-academic-cap" class="w-5 h-5" />
                                <span>Final Grade: <strong>{{ $programEnrollment->grade }}</strong></span>
                            </div>
                        @endif

                        @if($programEnrollment->feedback)
                            <div class="mt-4">
                                <h3 class="mb-2 text-lg font-semibold">Instructor Feedback</h3>
                                <div class="p-4 prose border rounded-lg max-w-none bg-base-100">
                                    {!! nl2br(e($programEnrollment->feedback)) !!}
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
