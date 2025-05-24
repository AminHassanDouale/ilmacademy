<?php

use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Carbon\Carbon;

new #[Title('Exam Details')] class extends Component {
    use Toast;

    public Exam $exam;
    public $examResult;
    public $childProfiles = [];
    public $selectedChildId = null;

    // Load data
    public function mount(Exam $exam): void
    {
        // Get the client profile associated with this user
        $clientProfile = Auth::user()->clientProfile;

        if (!$clientProfile) {
            $this->error("You don't have a client profile.");
            return redirect()->route('student.exams.index');
        }

        // Get child profiles associated with this parent
        $this->childProfiles = \App\Models\ChildProfile::where('parent_profile_id', $clientProfile->id)->get();
        $childProfileIds = $this->childProfiles->pluck('id')->toArray();

        // Check if the exam has results for any of the user's children
        $hasExamResult = ExamResult::where('exam_id', $exam->id)
            ->whereIn('child_profile_id', $childProfileIds)
            ->exists();

        if (!$hasExamResult) {
            $this->error("You don't have access to this exam.");
            return redirect()->route('student.exams.index');
        }

        $this->exam = $exam;
        $this->exam->load(['subject']);

        // Set selected child to the first one with exam result by default
        $firstExamResult = ExamResult::where('exam_id', $exam->id)
            ->whereIn('child_profile_id', $childProfileIds)
            ->first();

        if ($firstExamResult) {
            $this->selectedChildId = $firstExamResult->child_profile_id;
            $this->loadExamResult();
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            'Student viewed exam details',
            Exam::class,
            $exam->id,
            ['ip' => request()->ip()]
        );
    }

    // Change selected child
    public function changeChild($childId): void
    {
        $this->selectedChildId = $childId;
        $this->loadExamResult();
    }

    // Load exam result for selected child
    public function loadExamResult(): void
    {
        if (!$this->selectedChildId) {
            return;
        }

        $this->examResult = ExamResult::where('exam_id', $this->exam->id)
            ->where('child_profile_id', $this->selectedChildId)
            ->first();
    }

    // Get related exams with the same subject
    public function getRelatedExams()
    {
        // Get the client profile associated with this user
        $clientProfile = Auth::user()->clientProfile;

        if (!$clientProfile) {
            return collect();
        }

        // Get child profiles associated with this parent
        $childProfiles = \App\Models\ChildProfile::where('parent_profile_id', $clientProfile->id)->get();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        return Exam::with(['subject'])
            ->whereHas('examResults', function($query) use ($childProfileIds) {
                $query->whereIn('child_profile_id', $childProfileIds);
            })
            ->where('subject_id', $this->exam->subject_id)
            ->where('id', '!=', $this->exam->id)
            ->where('date', '>', Carbon::now())
            ->orderBy('date', 'asc')
            ->limit(3)
            ->get();
    }

    // Back to exams list
    public function backToList()
    {
        return redirect()->route('student.exams.index');
    }

    // Check if exam is upcoming
    public function isUpcoming()
    {
        return Carbon::parse($this->exam->date)->isFuture();
    }

    // Get exam status
    public function getExamStatus()
    {
        $now = Carbon::now();
        $examDate = Carbon::parse($this->exam->date);

        if ($now->lt($examDate)) {
            return 'upcoming';
        } elseif ($now->isSameDay($examDate)) {
            return 'today';
        } else {
            return 'completed';
        }
    }

    // Get result status
    public function getResultStatus()
    {
        if (!$this->examResult) {
            return 'not_available';
        }

        if ($this->examResult->score === null) {
            return 'pending';
        }

        return 'available';
    }

    public function with(): array
    {
        return [
            'relatedExams' => $this->getRelatedExams(),
            'isUpcoming' => $this->isUpcoming(),
            'examStatus' => $this->getExamStatus(),
            'resultStatus' => $this->getResultStatus(),
        ];
    }
};
?>

<div>
    <!-- Page header -->
    <x-header title="Exam Details" separator back-button back-url="{{ route('student.exams.index') }}">
        <x-slot:subtitle>
            {{ $exam->subject->name }} - {{ Carbon\Carbon::parse($exam->date)->format('M d, Y') }}
        </x-slot:subtitle>
    </x-header>

    <!-- Exam Details -->
    <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-3">
        <!-- Left column - Exam metadata -->
        <div class="col-span-1">
            <x-card title="Exam Information">
                <div class="space-y-4">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Date</div>
                        <div class="mt-1 font-semibold">{{ Carbon\Carbon::parse($exam->date)->format('l, F d, Y') }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Time</div>
                        <div class="mt-1">{{ Carbon\Carbon::parse($exam->start_time)->format('g:i A') }} - {{ Carbon\Carbon::parse($exam->end_time)->format('g:i A') }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Subject</div>
                        <div class="mt-1">{{ $exam->subject->name }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Exam Type</div>
                        <div class="mt-1">
                            <span class="capitalize">{{ $exam->type }}</span>
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Status</div>
                        <div class="mt-1">
                            @if ($examStatus === 'upcoming')
                                <x-badge label="Upcoming" color="warning" />
                            @elseif ($examStatus === 'today')
                                <x-badge label="Today" color="success" />
                            @else
                                <x-badge label="Completed" color="info" />
                            @endif
                        </div>
                    </div>

                    @if($exam->location)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Location</div>
                            <div class="mt-1">
                                <div class="flex items-start">
                                    <x-icon name="o-map-pin" class="w-5 h-5 mr-1 text-gray-500 flex-shrink-0 mt-0.5" />
                                    <span>{{ $exam->location }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>

            @if(count($childProfiles) > 1)
                <x-card title="Child Selection" class="mt-4">
                    <div class="space-y-4">
                        <div class="text-sm text-gray-500">
                            Select which child's exam results to view:
                        </div>

                        <div>
                            @foreach($childProfiles as $child)
                                @php
                                    $hasExamResult = \App\Models\ExamResult::where('exam_id', $exam->id)
                                        ->where('child_profile_id', $child->id)
                                        ->exists();
                                @endphp

                                @if($hasExamResult)
                                    <div class="mb-2">
                                        <x-radio
                                            id="child-{{ $child->id }}"
                                            wire:model.live="selectedChildId"
                                            value="{{ $child->id }}"
                                            label="{{ $child->user->name }}"
                                        />
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </x-card>
            @endif
        </div>

        <!-- Right column - Details and results -->
        <div class="col-span-1 md:col-span-2">
            <!-- Exam results if exam is in the past -->
            @if(!$isUpcoming && $examResult)
                <x-card title="Exam Results">
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <div class="text-sm font-medium text-gray-500">Status</div>
                                <div class="mt-1">
                                    @if($resultStatus === 'available')
                                        <x-badge label="Available" color="success" />
                                    @elseif($resultStatus === 'pending')
                                        <x-badge label="Pending" color="warning" />
                                    @else
                                        <x-badge label="Not Available" color="error" />
                                    @endif
                                </div>
                            </div>

                            @if($resultStatus === 'available')
                                <div>
                                    <div class="text-sm font-medium text-gray-500">Score</div>
                                    <div class="mt-1 text-lg font-bold">
                                        {{ $examResult->score }} / {{ $exam->total_marks }}
                                        ({{ round(($examResult->score / $exam->total_marks) * 100) }}%)
                                    </div>
                                </div>
                            @endif
                        </div>

                        @if($resultStatus === 'available')
                            @if($examResult->grade)
                                <div>
                                    <div class="text-sm font-medium text-gray-500">Grade</div>
                                    <div class="mt-1 text-xl font-bold">
                                        {{ $examResult->grade }}
                                    </div>
                                </div>
                            @endif

                            @if($examResult->feedback)
                                <div>
                                    <div class="text-sm font-medium text-gray-500">Feedback</div>
                                    <div class="p-3 mt-1 text-sm rounded-lg bg-base-200">
                                        {{ $examResult->feedback }}
                                    </div>
                                </div>
                            @endif
                        @elseif($resultStatus === 'pending')
                            <div class="p-4 text-center rounded-lg bg-base-200">
                                <p class="text-gray-500">
                                    Results are being processed and will be available soon.
                                </p>
                            </div>
                        @endif
                    </div>
                </x-card>
            @elseif($isUpcoming)
                <x-card title="Exam Preparation">
                    <div class="p-4 rounded-lg bg-base-200">
                        <div class="flex items-start">
                            <x-icon name="o-academic-cap" class="flex-shrink-0 w-6 h-6 mr-2 text-info" />
                            <div>
                                <p class="font-medium">This exam is scheduled for {{ Carbon\Carbon::parse($exam->date)->diffForHumans() }}.</p>
                                <p class="mt-2 text-sm text-gray-500">
                                    Make sure to prepare well and arrive at the exam location on time.
                                </p>
                            </div>
                        </div>
                    </div>
                </x-card>
            @endif

            <!-- Exam details -->
            <x-card title="Exam Details" class="mt-4">
                <div class="space-y-4">
                    @if($exam->description)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Description</div>
                            <div class="p-3 mt-1 rounded-lg bg-base-200">
                                {{ $exam->description ?? 'No description available.' }}
                            </div>
                        </div>
                    @endif

                    @if($exam->topics)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Topics</div>
                            <div class="mt-1">
                                <ul class="list-disc list-inside">
                                    @foreach(explode(',', $exam->topics) as $item)
                                        <li>{{ trim($item) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif

                    @if($exam->required_materials)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Required Materials</div>
                            <div class="mt-1">
                                <ul class="list-disc list-inside">
                                    @foreach(explode(',', $exam->required_materials) as $item)
                                        <li>{{ trim($item) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif

                    @if($exam->special_instructions)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Special Instructions</div>
                            <div class="p-3 mt-1 text-sm rounded-lg bg-base-200">
                                {{ $exam->special_instructions }}
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Related exams -->
            @if(count($relatedExams) > 0)
                <x-card title="Upcoming Exams for This Subject" class="mt-4">
                    <div class="space-y-4">
                        @foreach($relatedExams as $relatedExam)
                            <div class="p-3 transition-colors border rounded-lg hover:bg-base-100">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-medium">{{ Carbon\Carbon::parse($relatedExam->date)->format('l, F d, Y') }}</div>
                                        <div class="text-sm text-gray-500">
                                            {{ Carbon\Carbon::parse($relatedExam->start_time)->format('g:i A') }} - {{ Carbon\Carbon::parse($relatedExam->end_time)->format('g:i A') }}
                                        </div>
                                    </div>
                                    <x-button
                                        icon="o-eye"
                                        color="secondary"
                                        size="sm"
                                        tooltip="View Exam Details"
                                        href="{{ route('student.exams.show', $relatedExam->id) }}"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</div>
